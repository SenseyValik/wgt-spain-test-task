<?php

namespace App\Services;

use App\Data\ReservationData;
use App\Exceptions\WGTSpainException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * Reserve one unit of an offer.
     *
     * Two concurrent requests for the last unit: the first takes a row lock on the offer;
     * the second blocks inside its own transaction until that commits, then re-reads
     * available_units as 0 and is rejected. Re-reading *after* acquiring the lock is the
     * essential part — checking before locking would be a time-of-check/time-of-use bug.
     */
    public function reserve(Offer $offer, ReservationData $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data) {
            $locked = Offer::query()
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Inside the lock, so two identical client_references for the same offer are
            // serialised and cannot both insert.
            $existing = Reservation::query()
                ->where('client_reference', $data->clientReference)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($locked->expires_at <= now()) {
                throw new WGTSpainException('The offer has expired.', 409, [
                    'offer_id' => $locked->id,
                    'expires_at' => $locked->expires_at->toIso8601ZuluString(),
                ]);
            }

            if ($locked->available_units < 1) {
                throw new WGTSpainException('The offer has no available units left.', 409, [
                    'offer_id' => $locked->id,
                    'available_units' => $locked->available_units,
                ]);
            }

            try {
                // Nested transaction => savepoint, so a unique violation on
                // client_reference does not poison the outer transaction.
                $reservation = DB::transaction(fn () => $locked->reservations()->create([
                    'client_reference' => $data->clientReference,
                    'customer_name' => $data->customerName,
                    'customer_email' => $data->customerEmail,
                    'units' => 1,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Same client_reference already used against a different offer.
                return Reservation::query()
                    ->where('client_reference', $data->clientReference)
                    ->firstOrFail();
            }

            $locked->decrement('available_units');

            return $reservation;
        });
    }
}

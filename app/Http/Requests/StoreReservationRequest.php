<?php

namespace App\Http\Requests;

use App\Data\ReservationData;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    /**
     * client_reference is deliberately not `unique:reservations` — a repeat is an idempotent
     * replay handled in the service, not a validation failure.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * The caller's own id for this booking. Send the same one twice and the second
             * request replays the first: 200 instead of 201, and no further unit taken.
             *
             * @example "booking-7f3a91"
             */
            'client_reference' => ['required', 'string', 'max:191'],

            /** @example "Ada Lovelace" */
            'customer_name' => ['required', 'string', 'max:255'],

            /** @example "ada@example.com" */
            'customer_email' => ['required', 'email', 'max:255'],
        ];
    }

    public function toData(): ReservationData
    {
        return new ReservationData(
            clientReference: $this->string('client_reference')->toString(),
            customerName: $this->string('customer_name')->toString(),
            customerEmail: $this->string('customer_email')->toString(),
        );
    }
}

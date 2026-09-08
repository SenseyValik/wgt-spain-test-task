<?php

namespace App\Services;

use App\Data\PropertySearchCriteria;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PropertySearchService
{
    /**
     * Cheapest actual offer per property.
     *
     * Filtering, cheapest-per-property, ordering and pagination all happen in Postgres via
     * a lateral join, so no collection ever holds more than one page of rows.
     *
     * @return LengthAwarePaginator<int, Property>
     */
    public function search(PropertySearchCriteria $criteria): LengthAwarePaginator
    {
        $best = Offer::query()
            ->select([
                'offers.id',
                'offers.supplier_id',
                'offers.price',
                'offers.currency',
                'offers.available_units',
                'offers.expires_at',
            ])
            ->whereColumn('offers.property_id', 'properties.id')
            ->where('offers.check_in', $criteria->checkIn)
            ->where('offers.check_out', $criteria->checkOut)
            ->where('offers.max_guests', '>=', $criteria->guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            // The id tie-break makes "cheapest" deterministic when prices are equal.
            ->orderBy('offers.price')
            ->orderBy('offers.id')
            ->limit(1);

        return Property::query()
            // Inner join: a property with no actual offer drops out of the results.
            ->joinLateral($best, 'best')
            ->join('suppliers', 'suppliers.id', '=', 'best.supplier_id')
            ->when(
                $criteria->city,
                fn ($query, $city) => $query->where('properties.city', $city)
            )
            ->select([
                'properties.*',
                'best.id as best_offer_id',
                'best.price as best_offer_price',
                'best.currency as best_offer_currency',
                'best.available_units as best_offer_available_units',
                'best.expires_at as best_offer_expires_at',
                'suppliers.code as best_offer_supplier',
            ])
            // Without the properties.id tie-break, pagination over equal prices can repeat
            // or skip rows between pages.
            ->orderBy('best.price')
            ->orderBy('properties.id')
            ->paginate($criteria->perPage);
    }
}

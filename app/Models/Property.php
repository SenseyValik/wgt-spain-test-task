<?php

namespace App\Models;

use App\Data\PropertySearchCriteria;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'city'];

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * One row per property, carrying its cheapest offer that is still actual.
     *
     * Three joins — offers, properties, suppliers — and `distinct on (properties.id)` to keep
     * only the first offer per property, which the inner ORDER BY makes the cheapest one. All
     * of it happens in Postgres; no collection ever holds more than the page being returned.
     *
     * The derived table is not decoration. `distinct on` requires its own expression to lead
     * the ORDER BY, so the inner query must sort by properties.id — and the endpoint has to
     * return rows sorted by price. One wrapper re-sorts the deduplicated rows; the caller
     * paginates that.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function withCheapestActualOffer(Builder $query, PropertySearchCriteria $criteria): void
    {
        $cheapestPerProperty = Property::query()
            ->selectRaw(
                'distinct on (properties.id)
                 properties.*,
                 offers.id as best_offer_id,
                 offers.price as best_offer_price,
                 offers.currency as best_offer_currency,
                 offers.available_units as best_offer_available_units,
                 offers.expires_at as best_offer_expires_at,
                 suppliers.code as best_offer_supplier'
            )
            // Inner joins: a property with no actual offer drops out of the results.
            ->join('offers', 'offers.property_id', '=', 'properties.id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.check_in', $criteria->checkIn)
            ->where('offers.check_out', $criteria->checkOut)
            ->where('offers.max_guests', '>=', $criteria->guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            ->when(
                $criteria->city,
                fn (Builder $query, string $city) => $query->where('properties.city', $city)
            )
            // Must start with the distinct-on expression. The price and id that follow are what
            // pick the winner: cheapest first, and the id tie-break makes it deterministic
            // when two offers share a price.
            ->orderBy('properties.id')
            ->orderBy('offers.price')
            ->orderBy('offers.id');

        $query
            ->fromSub($cheapestPerProperty, 'properties')
            // Without the id tie-break, pagination over equal prices can repeat or skip rows
            // between pages.
            ->orderBy('best_offer_price')
            ->orderBy('id');
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_IN = '2026-10-10';

    private const CHECK_OUT = '2026-10-15';

    private function url(array $params = []): string
    {
        return '/api/properties?'.http_build_query(array_merge([
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_OUT,
            'guests' => 2,
        ], $params));
    }

    private function offerFor(Property $property, Supplier $supplier, array $overrides = []): Offer
    {
        return Offer::factory()
            ->for($property)
            ->for($supplier)
            ->forDates(self::CHECK_IN, self::CHECK_OUT)
            ->create($overrides);
    }

    public function test_it_returns_the_cheapest_offer_per_property_across_suppliers(): void
    {
        $a = Supplier::factory()->create(['code' => 'supplier-a']);
        $b = Supplier::factory()->create(['code' => 'supplier-b']);
        $property = Property::factory()->inCity('Barcelona')->create(['code' => 'BCN-0001']);

        $this->offerFor($property, $a, ['price' => 72500]);
        $cheapest = $this->offerFor($property, $b, ['price' => 51000]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.city', 'Barcelona')
            ->assertJsonPath('data.0.best_offer.id', $cheapest->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', 51000)
            ->assertJsonPath('data.0.best_offer.currency', 'EUR')
            ->assertJsonStructure([
                'data' => [['code', 'name', 'city', 'best_offer' => [
                    'id', 'supplier', 'price', 'currency', 'available_units', 'expires_at',
                ]]],
            ]);
    }

    public function test_it_excludes_offers_that_are_not_actual(): void
    {
        $supplier = Supplier::factory()->create();

        $wrongDates = Property::factory()->create();
        Offer::factory()->for($wrongDates)->for($supplier)->forDates('2026-11-01', '2026-11-06')->create();

        $tooFewGuests = Property::factory()->create();
        $this->offerFor($tooFewGuests, $supplier, ['max_guests' => 1]);

        $soldOut = Property::factory()->create();
        $this->offerFor($soldOut, $supplier, ['available_units' => 0]);

        $expired = Property::factory()->create();
        $this->offerFor($expired, $supplier, ['expires_at' => now()->subDay()]);

        $good = Property::factory()->create(['code' => 'GOOD-1']);
        $this->offerFor($good, $supplier);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'GOOD-1');
    }

    public function test_it_filters_by_city_when_given(): void
    {
        $supplier = Supplier::factory()->create();
        $bcn = Property::factory()->inCity('Barcelona')->create(['code' => 'BCN-1']);
        $mad = Property::factory()->inCity('Madrid')->create(['code' => 'MAD-1']);
        $this->offerFor($bcn, $supplier);
        $this->offerFor($mad, $supplier);

        $this->getJson($this->url(['city' => 'Barcelona']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-1');

        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_it_orders_by_price_ascending(): void
    {
        $supplier = Supplier::factory()->create();

        foreach ([90000, 30000, 60000] as $i => $price) {
            $property = Property::factory()->create(['code' => "P-{$i}"]);
            $this->offerFor($property, $supplier, ['price' => $price]);
        }

        $prices = $this->getJson($this->url())->assertOk()->json('data.*.best_offer.price');

        $this->assertSame([30000, 60000, 90000], $prices);
    }

    public function test_it_paginates_with_next_prev_and_per_page(): void
    {
        $supplier = Supplier::factory()->create();

        foreach (range(1, 7) as $i) {
            $property = Property::factory()->create(['code' => "P-{$i}"]);
            $this->offerFor($property, $supplier, ['price' => $i * 1000]);
        }

        $page1 = $this->getJson($this->url(['per_page' => 3]))->assertOk();
        $page1->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('links.prev', null);

        $this->assertNotNull($page1->json('links.next'));

        $page2 = $this->getJson($this->url(['per_page' => 3, 'page' => 2]))->assertOk();
        $page2->assertJsonCount(3, 'data');
        $this->assertNotNull($page2->json('links.prev'));

        $this->assertEmpty(
            array_intersect($page1->json('data.*.code'), $page2->json('data.*.code')),
            'Pages must not overlap.'
        );
    }

    public function test_it_validates_the_search_parameters(): void
    {
        $this->getJson('/api/properties')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['check_in', 'check_out']);

        $this->getJson($this->url(['check_out' => '2026-10-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_out');

        $this->getJson($this->url(['per_page' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * The highest-value test here: the query count must not grow with the number of
     * properties or offers. This is the only assertion that fails if someone ever
     * "fixes" the search by loading offers and grouping them with a collection.
     */
    public function test_the_query_count_does_not_grow_with_the_result_set(): void
    {
        $supplier = Supplier::factory()->create();

        $countQueriesFor = function (int $properties) use ($supplier): int {
            Offer::query()->delete();
            Property::query()->delete();

            foreach (range(1, $properties) as $i) {
                $property = Property::factory()->create(['code' => "Q-{$i}"]);
                // Several competing offers per property, so a naive implementation would
                // have to fetch and reduce them.
                foreach ([50000, 40000, 30000] as $price) {
                    $this->offerFor($property, $supplier, ['price' => $price]);
                }
            }

            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->getJson($this->url(['per_page' => 100]))->assertOk();

            return $queries;
        };

        $small = $countQueriesFor(2);
        $large = $countQueriesFor(20);

        $this->assertSame(
            $small,
            $large,
            "Query count grew from {$small} to {$large} as the result set grew tenfold — "
            .'the cheapest-offer resolution is no longer happening in the database.'
        );
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationStoreTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ], $overrides);
    }

    public function test_it_reserves_an_offer_and_decrements_availability(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_name', 'John Smith')
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.units', 1)
            ->assertJsonStructure(['data' => [
                'id', 'offer_id', 'client_reference', 'customer_name',
                'customer_email', 'units', 'created_at',
            ]]);

        $this->assertSame(1, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_the_last_unit_can_be_reserved_and_then_no_more(): void
    {
        $offer = Offer::factory()->lastUnit()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(201);

        $this->assertSame(0, $offer->refresh()->available_units);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload([
            'client_reference' => 'web-order-second',
        ]))->assertStatus(409);

        $this->assertSame(1, Reservation::count());
    }

    public function test_it_rejects_a_sold_out_offer(): void
    {
        $offer = Offer::factory()->soldOut()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'The offer has no available units left.');

        $this->assertSame(0, Reservation::count());
    }

    public function test_it_rejects_an_expired_offer(): void
    {
        $offer = Offer::factory()->expired()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'The offer has expired.');

        $this->assertSame(0, Reservation::count());
    }

    public function test_it_returns_404_for_an_unknown_offer(): void
    {
        $this->postJson('/api/offers/999/reservations', $this->payload())
            ->assertStatus(404);
    }

    public function test_it_validates_the_payload(): void
    {
        $offer = Offer::factory()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", [
            'customer_email' => 'not-an-email',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);
    }

    /**
     * client_reference is an idempotency key: a resubmitted booking must not consume a
     * second unit.
     */
    public function test_the_same_client_reference_is_an_idempotent_replay(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $first = $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(201);

        $second = $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, $offer->refresh()->available_units, 'A replay must not consume a unit.');
    }

    /**
     * The application guard is a re-read under a row lock; this asserts the database-level
     * backstop that catches it if that guard is ever wrong.
     */
    public function test_the_database_refuses_to_oversell(): void
    {
        $offer = Offer::factory()->soldOut()->create();

        $this->expectException(QueryException::class);

        DB::table('offers')->where('id', $offer->id)->decrement('available_units');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();

            // "Last import that touched this offer" — not ownership. An offer outlives the
            // import that created it, so deleting an import must not delete offers.
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_id', 191);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('max_guests');

            // Minor units (72500 = 725.00 EUR). Integers keep cheapest-offer ordering exact.
            $table->unsignedBigInteger('price');
            $table->char('currency', 3);

            $table->unsignedInteger('available_units')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            // Upsert key: re-importing the same external_id under a different import
            // updates this row and repoints import_id.
            $table->unique(['supplier_id', 'external_id']);

            $table->index('property_id');
        });

        // Postgres has no unsigned integers — Laravel's unsignedInteger() maps to a plain
        // `integer`, so these CHECKs are doing real work, not duplicating a column type.
        DB::statement('alter table offers add constraint offers_dates_check check (check_out > check_in)');
        DB::statement('alter table offers add constraint offers_available_units_check check (available_units >= 0)');
        DB::statement('alter table offers add constraint offers_price_check check (price >= 0)');
        DB::statement('alter table offers add constraint offers_max_guests_check check (max_guests > 0)');

        // Search indexes. Partial (`where available_units > 0`) keeps sold-out offers out of
        // the index entirely; expires_at cannot go in the predicate because now() is not
        // immutable. Not expressible in the schema builder, hence raw SQL.
        DB::statement('create index offers_search_idx on offers (check_in, check_out, price) where available_units > 0');
        DB::statement('create index offers_property_price_idx on offers (property_id, price) where available_units > 0');
    }

    public function down(): void
    {
        DB::statement('drop index if exists offers_property_price_idx');
        DB::statement('drop index if exists offers_search_idx');

        Schema::dropIfExists('offers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();

            // The client's own order id. Unique so a double-submitted booking is rejected
            // rather than creating a second reservation.
            $table->string('client_reference', 191)->unique();

            $table->string('customer_name');
            $table->string('customer_email');
            $table->unsignedSmallInteger('units')->default(1);
            $table->timestampsTz();

            // Postgres does not index the referencing side of a foreign key.
            $table->index('offer_id');
        });

        DB::statement('alter table reservations add constraint reservations_units_check check (units > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

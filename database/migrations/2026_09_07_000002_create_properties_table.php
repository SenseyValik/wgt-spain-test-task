<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            // Global catalogue key: two suppliers offering BCN-0001 must resolve to the
            // same row, otherwise "cheapest offer per property" cannot compare suppliers.
            $table->string('code', 64)->unique();

            $table->string('name');
            $table->string('city', 120)->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};

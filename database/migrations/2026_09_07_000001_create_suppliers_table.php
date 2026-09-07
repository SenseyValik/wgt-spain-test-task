<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);

            // 1:1 account link. Nullable on purpose: a supplier that only pushes imports
            // over the API never needs a login, and deleting the account must not delete
            // the supplier. The unique index is what makes this 1:1 rather than 1:N.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('user_id');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};

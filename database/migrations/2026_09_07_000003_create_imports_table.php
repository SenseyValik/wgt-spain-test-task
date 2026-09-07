<?php

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('external_import_id', 191);

            // When the supplier generated the import; created_at is when we received it.
            $table->timestampTz('sent_at');

            $table->string('status', 20)->default(ImportStatus::Pending->value);

            // The raw offers array. Keeps the queue message down to just an import id and
            // survives worker restarts and job retries.
            $table->jsonb('payload');

            $table->unsignedInteger('total_offers')->default(0);
            $table->unsignedInteger('processed_offers')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            // Idempotency key: a resent import collides here, so the endpoint returns the
            // existing row instead of queueing a second job.
            $table->unique(['supplier_id', 'external_import_id']);
        });

        DB::statement(sprintf(
            "alter table imports add constraint imports_status_check check (status in ('%s'))",
            implode("','", ImportStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};

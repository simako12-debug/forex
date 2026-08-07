<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('corporate_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->string('type', 32);
            $table->date('ex_date');
            $table->decimal('ratio', 14, 6)->nullable();
            $table->decimal('amount', 14, 6)->nullable();
            $table->string('source', 32);
            $table->timestamp('ingested_at')->useCurrent();
            $table->timestamps();

            // Unique dělá ingest corporate actions idempotentní.
            $table->unique(['instrument_id', 'type', 'ex_date']);
            $table->index('instrument_id');
            $table->index('ex_date');
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_actions');
    }
};

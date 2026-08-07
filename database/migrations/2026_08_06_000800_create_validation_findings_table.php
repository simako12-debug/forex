<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('validation_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ingest_run_id');
            // instrument_id a date jsou nullable — strukturální finding (chybějící
            // sloupec v souboru) se k žádnému instrumentu nevztahuje.
            $table->uuid('instrument_id')->nullable();
            $table->date('date')->nullable();
            $table->string('rule', 64);
            $table->string('severity', 16);
            $table->text('detail');
            $table->timestamps();

            $table->index('ingest_run_id');
            $table->index('instrument_id');
            $table->index(['rule', 'severity']);
            $table->foreign('ingest_run_id')->references('id')->on('ingest_runs')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_findings');
    }
};

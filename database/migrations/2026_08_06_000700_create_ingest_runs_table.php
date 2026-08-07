<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ingest_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 64);
            $table->string('mode', 16);
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('rows_inserted')->default(0);
            $table->unsignedBigInteger('rows_updated')->default(0);
            $table->string('status', 16);
            $table->json('checkpoint')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_runs');
    }
};

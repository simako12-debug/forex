<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('instrument_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->string('symbol', 16);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'valid_from']);
            // ->index() je vedle ->foreign() záměrně — Postgres index pro FK sám nevytváří.
            $table->index('instrument_id');
            $table->index(['symbol', 'valid_from', 'valid_to']);
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_symbols');
    }
};

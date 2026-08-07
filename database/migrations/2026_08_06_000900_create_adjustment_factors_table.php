<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('adjustment_factors', function (Blueprint $table): void {
            $table->uuid('instrument_id');
            $table->date('date');
            $table->decimal('cum_split_factor', 20, 10)->default(1);
            $table->decimal('cum_div_factor', 20, 10)->default(1);

            // Časová řada, ne business entita — složený přirozený klíč, žádné UUID.
            $table->primary(['instrument_id', 'date']);
            $table->index('instrument_id');
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_factors');
    }
};

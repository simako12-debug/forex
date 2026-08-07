<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('universe_members', function (Blueprint $table): void {
            $table->uuid('definition_id');
            $table->date('date');
            $table->uuid('instrument_id');

            // Časová řada — složený přirozený klíč, žádné UUID.
            $table->primary(['definition_id', 'date', 'instrument_id']);
            $table->index(['definition_id', 'date']);
            $table->index('instrument_id');
            $table->foreign('definition_id')->references('id')->on('universe_definitions')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universe_members');
    }
};

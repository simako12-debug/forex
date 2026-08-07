<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('market_days', function (Blueprint $table): void {
            $table->string('exchange', 32);
            $table->date('date');
            $table->boolean('is_open');
            $table->time('open_at')->nullable();
            $table->time('close_at')->nullable();
            $table->boolean('is_early_close')->default(false);
            $table->timestamps();

            // Složený přirozený klíč — kalendář je časová řada, ne business entita,
            // takže výjimka z UUID pravidla platí.
            $table->primary(['exchange', 'date']);
            $table->index(['exchange', 'is_open', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_days');
    }
};

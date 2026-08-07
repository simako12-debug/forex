<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('universe_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->timestamps();

            // Verze je součástí identity — změna pravidel znamená novou verzi,
            // aby staré členství zůstalo reprodukovatelné.
            $table->unique(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universe_definitions');
    }
};

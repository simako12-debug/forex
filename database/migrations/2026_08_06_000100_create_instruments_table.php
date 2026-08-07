<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('asset_class', 32);
            $table->string('primary_exchange', 32);
            $table->string('sector', 64)->nullable();
            $table->date('listed_at')->nullable();
            $table->date('delisted_at')->nullable();
            $table->string('delisting_reason', 64)->nullable();
            $table->timestamps();

            $table->index('delisted_at');
            $table->index(['asset_class', 'primary_exchange']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};

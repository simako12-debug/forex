<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\InstrumentSymbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstrumentSymbol> */
class InstrumentSymbolFactory extends Factory
{
    /** @var class-string<InstrumentSymbol> */
    protected $model = InstrumentSymbol::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper($this->faker->lexify('????')),
            'valid_from' => '2000-01-03',
            'valid_to' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Instrument> */
class InstrumentFactory extends Factory
{
    /** @var class-string<Instrument> */
    protected $model = Instrument::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'asset_class' => 'us_equity',
            'primary_exchange' => $this->faker->randomElement(['NYSE', 'NASDAQ']),
            'sector' => $this->faker->randomElement(['Technology', 'Healthcare', 'Energy']),
            'listed_at' => '2000-01-03',
            'delisted_at' => null,
            'delisting_reason' => null,
        ];
    }
}

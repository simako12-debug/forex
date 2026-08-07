<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\DailyBar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyBar> */
class DailyBarFactory extends Factory
{
    /** @var class-string<DailyBar> */
    protected $model = DailyBar::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $close = $this->faker->randomFloat(2, 10, 500);

        return [
            'date' => '2019-03-15',
            'open' => $close,
            'high' => $close + 1.0,
            'low' => $close - 1.0,
            'close' => $close,
            'volume' => $this->faker->numberBetween(100_000, 10_000_000),
            'source' => 'fixture',
        ];
    }
}

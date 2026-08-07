<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\MarketDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketDay> */
class MarketDayFactory extends Factory
{
    /** @var class-string<MarketDay> */
    protected $model = MarketDay::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'exchange' => 'XNYS',
            'date' => '2019-03-15',
            'is_open' => true,
            'open_at' => '09:30',
            'close_at' => '16:00',
            'is_early_close' => false,
        ];
    }
}

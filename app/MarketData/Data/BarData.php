<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

final class BarData extends Data
{
    public function __construct(
        public readonly string $symbol,
        // Denní bar je čisté datum v pojmu burzy; intradenní timestamp je vždy UTC.
        // Formáty patří sem, protože výchozí ATOM z laravel-data ani jeden nesedí.
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly CarbonImmutable $date,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly int $volume,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public readonly null|CarbonImmutable $ts = null,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();
        $close = $faker->randomFloat(2, 10, 500);

        return self::from([
            'symbol' => strtoupper($faker->lexify('????')),
            'date' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'open' => $close,
            'high' => $close + 1.0,
            'low' => $close - 1.0,
            'close' => $close,
            'volume' => $faker->numberBetween(100_000, 10_000_000),
            'ts' => null,
            ...$attributes,
        ]);
    }
}

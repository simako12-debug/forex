<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Spatie\LaravelData\Data;

final class UniverseRulesData extends Data
{
    public function __construct(
        public readonly float $minPrice,
        public readonly float $minAvgDollarVolume,
        public readonly int $dollarVolumeLookbackDays,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        return self::from([
            'minPrice' => 5.0,
            'minAvgDollarVolume' => 5_000_000.0,
            'dollarVolumeLookbackDays' => 20,
            ...$attributes,
        ]);
    }
}

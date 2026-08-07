<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ValidationFindingData extends Data
{
    public function __construct(
        public readonly null|string $instrumentId,
        public readonly null|CarbonImmutable $date,
        public readonly string $detail,
    ) {
    }
}

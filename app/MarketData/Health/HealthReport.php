<?php

declare(strict_types=1);

namespace App\MarketData\Health;

use Carbon\CarbonImmutable;

final readonly class HealthReport
{
    /** @param array<int,int> $missingPartitionYears */
    public function __construct(
        public null|CarbonImmutable $lastSuccessfulIngestAt,
        public int $tradingDaysCoveredLast30,
        public int $openErrorFindings,
        public array $missingPartitionYears,
        public bool $healthy,
    ) {
    }
}

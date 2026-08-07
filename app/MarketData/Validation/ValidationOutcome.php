<?php

declare(strict_types=1);

namespace App\MarketData\Validation;

final readonly class ValidationOutcome
{
    /** @param array<int,string> $quarantinedInstrumentIds */
    public function __construct(
        public int $errorCount,
        public int $warningCount,
        public array $quarantinedInstrumentIds,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

final readonly class MergeResult
{
    public function __construct(
        public int $inserted,
        public int $updated,
    ) {
    }
}

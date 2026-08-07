<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Data\BarData;
use Generator;

interface BarSourcePort
{
    public function name(): string;

    /** @return Generator<int,BarData> */
    public function dailyBars(): Generator;
}

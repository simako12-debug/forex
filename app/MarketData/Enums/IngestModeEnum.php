<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum IngestModeEnum: string
{
    case BULK = 'bulk';
    case INCREMENTAL = 'incremental';
}

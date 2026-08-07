<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum IngestStatusEnum: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}

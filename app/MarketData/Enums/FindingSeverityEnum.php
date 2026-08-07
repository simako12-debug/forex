<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum FindingSeverityEnum: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
}

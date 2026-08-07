<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum CorporateActionTypeEnum: string
{
    case SPLIT = 'split';
    case DIVIDEND = 'dividend';
    case SYMBOL_CHANGE = 'symbol_change';
    case SPINOFF = 'spinoff';
}

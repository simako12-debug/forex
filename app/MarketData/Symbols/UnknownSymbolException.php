<?php

declare(strict_types=1);

namespace App\MarketData\Symbols;

use Carbon\CarbonImmutable;
use RuntimeException;

final class UnknownSymbolException extends RuntimeException
{
    public static function forSymbolAtDate(string $symbol, CarbonImmutable $date): self
    {
        return new self(sprintf('Unknown symbol "%s" at date %s', $symbol, $date->toDateString()));
    }
}

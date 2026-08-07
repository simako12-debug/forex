<?php

declare(strict_types=1);

namespace App\MarketData\Symbols;

use App\MarketData\Models\Instrument;
use App\MarketData\Models\InstrumentSymbol;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Třída není final, aby ji šlo spyovat. readonly tu není potřeba, protože nemá stav.
 */
class SymbolResolver
{
    public function resolve(string $symbol, CarbonImmutable $date): null|Instrument
    {
        $match = InstrumentSymbol::query()
            ->where('symbol', $symbol)
            ->where('valid_from', '<=', $date->toDateString())
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date->toDateString());
            })
            ->first();

        return $match?->instrument;
    }

    public function resolveOrFail(string $symbol, CarbonImmutable $date): Instrument
    {
        $instrument = $this->resolve($symbol, $date);

        if ($instrument === null) {
            throw UnknownSymbolException::forSymbolAtDate($symbol, $date);
        }

        return $instrument;
    }
}

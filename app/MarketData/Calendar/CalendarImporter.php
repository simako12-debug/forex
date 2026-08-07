<?php

declare(strict_types=1);

namespace App\MarketData\Calendar;

use App\MarketData\Data\MarketDayData;
use App\MarketData\Models\MarketDay;
use Illuminate\Support\Facades\DB;

class CalendarImporter
{
    /** @param iterable<int,MarketDayData> $days */
    public function import(iterable $days): int
    {
        $count = 0;

        foreach ($days as $day) {
            MarketDay::query()->upsert(
                [[
                    'exchange' => $day->exchange,
                    'date' => $day->date->toDateString(),
                    'is_open' => $day->isOpen,
                    'open_at' => $day->openAt,
                    'close_at' => $day->closeAt,
                    'is_early_close' => $day->isEarlyClose,
                    'created_at' => DB::raw('now()'),
                    'updated_at' => DB::raw('now()'),
                ]],
                ['exchange', 'date'],
                ['is_open', 'open_at', 'close_at', 'is_early_close', 'updated_at'],
            );
            $count++;
        }

        return $count;
    }
}

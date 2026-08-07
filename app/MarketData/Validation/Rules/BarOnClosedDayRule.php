<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

class BarOnClosedDayRule implements ValidationRule
{
    public function name(): string
    {
        return 'BarOnClosedDay';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator
    {
        /** @var iterable<int,object{instrument_id:string,date:string,is_open:null|bool}> $rows */
        $rows = DB::cursor(sprintf(
            'SELECT s.instrument_id, s.date, m.is_open FROM %s AS s '
            . "LEFT JOIN market_days AS m ON m.date = s.date AND m.exchange = 'XNYS' "
            . 'WHERE m.date IS NULL OR m.is_open = false '
            . 'ORDER BY s.instrument_id, s.date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        ));

        $emitted = 0;

        foreach ($rows as $row) {
            if ($emitted === self::FINDING_CAP) {
                yield new ValidationFindingData(
                    instrumentId: null,
                    date: null,
                    detail: sprintf(
                        'Dosažen strop %d nálezů pravidla %s, další nezapsány',
                        self::FINDING_CAP,
                        $this->name(),
                    ),
                );

                return;
            }

            $detail = $row->is_open === null
                ? sprintf('Bar k %s, ale den není v kalendáři', (string) $row->date)
                : sprintf('Bar k %s, ale burza byla zavřená', (string) $row->date);

            yield new ValidationFindingData(
                instrumentId: (string) $row->instrument_id,
                date: CarbonImmutable::parse((string) $row->date),
                detail: $detail,
            );

            $emitted++;
        }
    }
}

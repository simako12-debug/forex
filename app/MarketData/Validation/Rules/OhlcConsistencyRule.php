<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

class OhlcConsistencyRule implements ValidationRule
{
    public function name(): string
    {
        return 'OhlcConsistency';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    /**
     * DB::cursor místo DB::select — u velkého dumpu by select natáhl všechny
     * nálezy do paměti. LIMIT cap + 1 dovolí poznat překročení stropu bez
     * druhého dotazu.
     *
     * @return Generator<int,ValidationFindingData>
     */
    public function findings(string $stagingTable): Generator
    {
        /**
         * @var iterable<int,object{
         *     instrument_id:string,date:string,open:string,high:string,low:string,close:string
         * }> $rows
         */
        $rows = DB::cursor(sprintf(
            'SELECT instrument_id, date, open, high, low, close FROM %s '
            . 'WHERE low > least(open, close) OR high < greatest(open, close) '
            . 'OR low > high OR open <= 0 OR high <= 0 OR low <= 0 OR close <= 0 '
            . 'ORDER BY instrument_id, date LIMIT %d',
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

            yield new ValidationFindingData(
                instrumentId: (string) $row->instrument_id,
                date: CarbonImmutable::parse((string) $row->date),
                detail: sprintf(
                    'Nekonzistentní OHLC k %s: o=%s h=%s l=%s c=%s',
                    (string) $row->date,
                    (string) $row->open,
                    (string) $row->high,
                    (string) $row->low,
                    (string) $row->close,
                ),
            );

            $emitted++;
        }
    }
}

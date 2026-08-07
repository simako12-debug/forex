<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

class DuplicateBarRule implements ValidationRule
{
    public function name(): string
    {
        return 'DuplicateBar';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator
    {
        /** @var iterable<int,object{instrument_id:string,date:string,occurrences:int}> $rows */
        $rows = DB::cursor(sprintf(
            'SELECT instrument_id, date, count(*) AS occurrences FROM %s '
            . 'GROUP BY instrument_id, date HAVING count(*) > 1 '
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
                detail: sprintf('Duplicitní bar k %s: %d výskytů', (string) $row->date, (int) $row->occurrences),
            );

            $emitted++;
        }
    }
}

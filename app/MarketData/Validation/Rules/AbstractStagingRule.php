<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

abstract class AbstractStagingRule implements ValidationRule
{
    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator
    {
        /** @var iterable<int,object{instrument_id?:null|string,date?:null|string}> $rows */
        $rows = DB::cursor($this->query($stagingTable));

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
                instrumentId: isset($row->instrument_id) ? (string) $row->instrument_id : null,
                date: isset($row->date) ? CarbonImmutable::parse((string) $row->date) : null,
                detail: $this->detail($row),
            );

            $emitted++;
        }
    }

    /** Dotaz MUSÍ končit `LIMIT self::FINDING_CAP + 1`, aby šlo poznat překročení stropu. */
    abstract protected function query(string $stagingTable): string;

    abstract protected function detail(object $row): string;
}

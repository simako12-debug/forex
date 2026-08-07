<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class CrossSourceDivergenceRule extends AbstractStagingRule
{
    public function __construct(private readonly float $thresholdPct = 0.01)
    {
    }

    public function name(): string
    {
        return 'CrossSourceDivergence';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::WARNING;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT s.instrument_id, s.date, s.close AS staged_close, b.close AS stored_close, b.source '
            . 'FROM %s AS s '
            . 'JOIN daily_bars AS b ON b.instrument_id = s.instrument_id AND b.date = s.date '
            . 'WHERE b.source <> \'\' AND abs(s.close - b.close) / b.close > %F '
            . 'ORDER BY s.instrument_id, s.date LIMIT %d',
            $stagingTable,
            $this->thresholdPct,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string,staged_close:string,stored_close:string,source:string} $row */
        return sprintf(
            'Zdroje se rozcházejí k %s: nový %s vs. uložený %s (zdroj %s)',
            $row->date,
            $row->staged_close,
            $row->stored_close,
            $row->source,
        );
    }
}

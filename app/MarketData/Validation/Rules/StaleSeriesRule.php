<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;

class StaleSeriesRule extends AbstractStagingRule
{
    public function __construct(private readonly int $staleAfterDays = 5)
    {
    }

    public function name(): string
    {
        return 'StaleSeries';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::WARNING;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT s.instrument_id, max(s.date) AS date FROM %s AS s '
            . 'JOIN instruments AS i ON i.id = s.instrument_id AND i.delisted_at IS NULL '
            . 'GROUP BY s.instrument_id '
            . 'HAVING max(s.date) < ('
            . "  SELECT max(date) FROM market_days WHERE exchange = 'XNYS' AND is_open = true"
            . "   AND date <= '%s') - INTERVAL '%d days' "
            . 'ORDER BY s.instrument_id LIMIT %d',
            $stagingTable,
            CarbonImmutable::now()->toDateString(),
            $this->staleAfterDays,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string} $row */
        return sprintf(
            'Poslední bar k %s, ale instrument není delistovaný (práh %d dní)',
            $row->date,
            $this->staleAfterDays,
        );
    }
}

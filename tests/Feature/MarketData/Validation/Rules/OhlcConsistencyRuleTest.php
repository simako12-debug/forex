<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Validation\Rules\OhlcConsistencyRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(OhlcConsistencyRule::class)]
final class OhlcConsistencyRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10.5],
        ]);

        $this->assertSame([], iterator_to_array(new OhlcConsistencyRule()->findings($table)));
    }

    public function testFindingsLowAboveOpen(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 10.5, 'close' => 10.8],
        ]);

        $findings = iterator_to_array(new OhlcConsistencyRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-13', $findings[0]->detail);
    }

    public function testFindingsNegativePrice(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => -1, 'high' => 11, 'low' => -2, 'close' => 10],
        ]);

        $this->assertCount(1, iterator_to_array(new OhlcConsistencyRule()->findings($table)));
    }

    public function testFindingsEmptyTable(): void
    {
        $table = StagingFixture::withRows([]);

        $this->assertSame([], iterator_to_array(new OhlcConsistencyRule()->findings($table)));
    }

    public function testFindingsCapExceeded(): void
    {
        $rows = [];

        // Plán počítal měsíc jako 1 + intdiv($i, 28), což pro 1005 řádků vyrobí
        // 2019-13-01 a Postgres to odmítne. Data se proto rozkládají i přes roky.
        for ($i = 0; $i < 1005; $i++) {
            $month = intdiv($i, 28);

            $rows[] = [
                'symbol' => 'AAPL',
                'date' => sprintf('%04d-%02d-%02d', 2019 + intdiv($month, 12), 1 + $month % 12, 1 + $i % 28),
                'open' => 10,
                'high' => 9,
                'low' => 11,
                'close' => 10,
            ];
        }

        $findings = iterator_to_array(new OhlcConsistencyRule()->findings(StagingFixture::withRows($rows)));

        $this->assertCount(1001, $findings);
        $this->assertStringContainsString('strop', $findings[1000]->detail);
    }

    public function testSeverity(): void
    {
        $this->assertSame(FindingSeverityEnum::ERROR, new OhlcConsistencyRule()->severity());
    }
}

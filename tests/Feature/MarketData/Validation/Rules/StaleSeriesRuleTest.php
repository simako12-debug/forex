<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\StaleSeriesRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(StaleSeriesRule::class)]
final class StaleSeriesRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $table = $this->setUpScenario(lastBar: '2026-08-05', delistedAt: null);

        $this->assertSame([], iterator_to_array(new StaleSeriesRule(staleAfterDays: 5)->findings($table)));
    }

    public function testFindingsStale(): void
    {
        $table = $this->setUpScenario(lastBar: '2026-06-01', delistedAt: null);

        $findings = iterator_to_array(new StaleSeriesRule(staleAfterDays: 5)->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2026-06-01', $findings[0]->detail);
    }

    public function testFindingsDelisted(): void
    {
        $table = $this->setUpScenario(lastBar: '2026-06-01', delistedAt: '2026-06-01');

        $this->assertSame([], iterator_to_array(new StaleSeriesRule(staleAfterDays: 5)->findings($table)));
    }

    /**
     * setTestNow je nutné — pravidlo porovnává proti dnešku a bez fixního času
     * by test za pět dní začal padat.
     */
    private function setUpScenario(string $lastBar, null|string $delistedAt): string
    {
        CarbonImmutable::setTestNow('2026-08-06');
        MarketDay::factory()->create(['date' => '2026-08-05', 'is_open' => true]);

        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => $lastBar, 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ], self::INSTRUMENT);

        Instrument::query()->whereKey(self::INSTRUMENT)->update(['delisted_at' => $delistedAt]);

        return $table;
    }
}

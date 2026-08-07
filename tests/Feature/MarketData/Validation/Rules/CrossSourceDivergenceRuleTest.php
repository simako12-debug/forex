<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\DailyBar;
use App\MarketData\Validation\Rules\CrossSourceDivergenceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(CrossSourceDivergenceRule::class)]
final class CrossSourceDivergenceRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $table = $this->stagedWithStored(stagedClose: 100.0, storedClose: 100.05);

        $this->assertSame([], iterator_to_array(new CrossSourceDivergenceRule()->findings($table)));
    }

    public function testFindingsDivergence(): void
    {
        $table = $this->stagedWithStored(stagedClose: 100.0, storedClose: 108.0);

        $findings = iterator_to_array(new CrossSourceDivergenceRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('108', $findings[0]->detail);
    }

    /**
     * Bez překryvu dvou zdrojů pravidlo nic nekontroluje a nesmí se na něj
     * v takovém případě spoléhat.
     */
    public function testFindingsNoOverlap(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 100, 'high' => 100,
                'low' => 100, 'close' => 100],
        ], self::INSTRUMENT);

        $this->assertSame([], iterator_to_array(new CrossSourceDivergenceRule()->findings($table)));
    }

    private function stagedWithStored(float $stagedClose, float $storedClose): string
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => $stagedClose, 'high' => $stagedClose,
                'low' => $stagedClose, 'close' => $stagedClose],
        ], self::INSTRUMENT);

        // daily_bars je partitionovaná — bez partition pro rok 2019 insert selže.
        App::make(PartitionManager::class)->ensureDailyYear(2019);

        DailyBar::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'date' => '2019-03-13',
            'close' => $storedClose,
            'source' => 'other',
        ]);

        return $table;
    }
}

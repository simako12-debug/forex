<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Ingest;

use App\MarketData\Data\BarData;
use App\MarketData\Ingest\StagingResolver;
use App\MarketData\Ingest\StagingTable;
use App\MarketData\Models\IngestRun;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\ValidationFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(StagingResolver::class)]
final class StagingResolverTest extends TestCase
{
    use RefreshDatabase;

    public function testResolve(): void
    {
        $instrument = $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'AAPL', '2000-01-03', null);
        $table = $this->stagedBars([
            BarData::fake(['symbol' => 'AAPL', 'date' => '2019-03-13']),
        ]);

        $resolved = App::make(StagingResolver::class)->resolve($table);

        $this->assertSame(1, $resolved);
        $this->assertSame($instrument->id, DB::table($table)->first()?->instrument_id);
    }

    public function testResolveRecycledSymbol(): void
    {
        $old = $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'XYZ', '2000-01-03', '2012-06-30');
        $new = $this->instrumentWithSymbol('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'XYZ', '2015-01-05', null);
        $table = $this->stagedBars([
            BarData::fake(['symbol' => 'XYZ', 'date' => '2010-05-04']),
            BarData::fake(['symbol' => 'XYZ', 'date' => '2020-05-04']),
        ]);

        App::make(StagingResolver::class)->resolve($table);

        $this->assertSame($old->id, DB::table($table)->where('date', '2010-05-04')->first()?->instrument_id);
        $this->assertSame($new->id, DB::table($table)->where('date', '2020-05-04')->first()?->instrument_id);
    }

    public function testResolveGapBetweenOwners(): void
    {
        $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'XYZ', '2000-01-03', '2012-06-30');
        $table = $this->stagedBars([BarData::fake(['symbol' => 'XYZ', 'date' => '2013-08-08'])]);

        $resolved = App::make(StagingResolver::class)->resolve($table);

        $this->assertSame(0, $resolved);
        $this->assertNull(DB::table($table)->first()?->instrument_id);
    }

    /**
     * Nálezy se agregují per symbol, ne per řádek. Dva řádky neznámého symbolu
     * dají jeden nález s počtem — u rozbitého dumpu by per-řádkové nálezy
     * vyrobily miliony záznamů.
     */
    public function testQuarantine(): void
    {
        $run = IngestRun::factory()->create();
        $table = $this->stagedBars([
            BarData::fake(['symbol' => 'NOPE', 'date' => '2019-03-13']),
            BarData::fake(['symbol' => 'NOPE', 'date' => '2019-03-14']),
        ]);

        $removed = App::make(StagingResolver::class)->quarantine($table, $run->id);

        $this->assertSame(2, $removed);
        $this->assertSame(0, DB::table($table)->count());

        $finding = ValidationFinding::query()->firstOrFail();
        $this->assertSame('UnknownSymbol', $finding->rule);
        $this->assertStringContainsString('NOPE', $finding->detail);
        $this->assertStringContainsString('2', $finding->detail);
    }

    public function testQuarantineNothingToRemove(): void
    {
        $run = IngestRun::factory()->create();
        $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'AAPL', '2000-01-03', null);
        $table = $this->stagedBars([BarData::fake(['symbol' => 'AAPL', 'date' => '2019-03-13'])]);
        App::make(StagingResolver::class)->resolve($table);

        $this->assertSame(0, App::make(StagingResolver::class)->quarantine($table, $run->id));
        $this->assertSame(0, ValidationFinding::query()->count());
    }

    private function instrumentWithSymbol(
        string $id,
        string $symbol,
        string $validFrom,
        null|string $validTo,
    ): Instrument {
        $instrument = Instrument::factory()->create(['id' => $id]);
        $instrument->symbols()->create(['symbol' => $symbol, 'valid_from' => $validFrom, 'valid_to' => $validTo]);

        return $instrument;
    }

    /** @param array<int,BarData> $bars */
    private function stagedBars(array $bars): string
    {
        $staging = App::make(StagingTable::class);
        $table = $staging->create('550e8400-e29b-41d4-a716-446655440000');
        $staging->write($table, $bars);

        return $table;
    }
}

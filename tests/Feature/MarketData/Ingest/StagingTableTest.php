<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Ingest;

use App\MarketData\Data\BarData;
use App\MarketData\Ingest\StagingTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(StagingTable::class)]
final class StagingTableTest extends TestCase
{
    use RefreshDatabase;

    private const string RUN_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testWrite(): void
    {
        $staging = App::make(StagingTable::class);
        $table = $staging->create(self::RUN_ID);

        $written = $staging->write($table, [
            BarData::fake(['symbol' => 'AAPL', 'date' => '2019-03-13', 'close' => 181.71, 'volume' => 100]),
            BarData::fake(['symbol' => 'XYZ', 'date' => '2019-03-13', 'close' => 14.22, 'volume' => 200]),
        ]);

        $this->assertSame(2, $written);
        $this->assertSame(2, DB::table($table)->count());
        $this->assertSame('AAPL', DB::table($table)->orderBy('symbol')->first()?->symbol);

        $staging->drop($table);
    }

    public function testWriteEmpty(): void
    {
        $staging = App::make(StagingTable::class);
        $table = $staging->create(self::RUN_ID);

        $this->assertSame(0, $staging->write($table, []));

        $staging->drop($table);
    }

    public function testDrop(): void
    {
        $staging = App::make(StagingTable::class);
        $table = $staging->create(self::RUN_ID);

        $staging->drop($table);

        $this->assertNull(DB::selectOne('SELECT 1 AS found FROM pg_class WHERE relname = ?', [$table]));
    }
}

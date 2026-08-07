<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Export;

use App\MarketData\Export\ParquetExporter;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ParquetExporter::class)]
final class ParquetExporterTest extends TestCase
{
    // DatabaseTruncation, ne RefreshDatabase: export běží jako samostatný proces
    // s vlastním připojením a data v nezacommitované transakci by neviděl.
    use DatabaseTruncation;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';
    private const int YEAR = 2019;

    protected function tearDown(): void
    {
        $path = App::make(ParquetExporter::class)->pathForYear(self::YEAR);

        if (is_file($path) === true) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testExportYear(): void
    {
        $this->bars();

        $path = App::make(ParquetExporter::class)->exportYear(self::YEAR);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }

    public function testPathForYearUsesSharedPath(): void
    {
        Config::set('market-data.shared_path', '/shared');

        $path = App::make(ParquetExporter::class)->pathForYear(self::YEAR);

        $this->assertSame('/shared/daily/year=2019/part.parquet', $path);
    }

    public function testExportYearFailingScriptThrow(): void
    {
        Config::set('market-data.export_script', '/neexistuje/export.py');

        $this->expectException(RuntimeException::class);

        App::make(ParquetExporter::class)->exportYear(self::YEAR);
    }

    private function bars(): void
    {
        Instrument::factory()->create(['id' => self::INSTRUMENT]);
        App::make(PartitionManager::class)->ensureDailyYear(self::YEAR);

        DailyBar::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'date' => '2019-03-13',
        ]);
    }
}

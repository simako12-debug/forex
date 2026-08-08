<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Export;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Export\SnapshotExporter;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TruncatesDatabaseAfterEachTest;
use Tests\TestCase;

#[CoversClass(SnapshotExporter::class)]
final class SnapshotExporterTest extends TestCase
{
    use DatabaseTruncation;
    use TruncatesDatabaseAfterEachTest;

    private const string INSTRUMENT = '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        $path = App::make(SnapshotExporter::class)->manifestPath();

        if (is_file($path) === true) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testExportWritesManifest(): void
    {
        $this->bars();

        $manifest = App::make(SnapshotExporter::class)->export([2019]);

        $this->assertSame(AdjustmentFactorCalculator::LOGIC_VERSION, $manifest->adjustmentLogicVersion);
        $this->assertSame([2019], $manifest->years);
        $this->assertFileExists(App::make(SnapshotExporter::class)->manifestPath());
    }

    public function testExportManifestCountsBars(): void
    {
        $this->bars();

        $manifest = App::make(SnapshotExporter::class)->export([2019]);

        $this->assertSame(1, $manifest->rowCounts['instruments']);
        $this->assertArrayHasKey('market_days', $manifest->rowCounts);
    }

    public function testExportManifestIsReadableJson(): void
    {
        $this->bars();
        App::make(SnapshotExporter::class)->export([2019]);

        $contents = file_get_contents(App::make(SnapshotExporter::class)->manifestPath());
        $this->assertIsString($contents);

        /** @var array<string,mixed> $payload */
        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AdjustmentFactorCalculator::LOGIC_VERSION, $payload['adjustment_logic_version']);
    }

    private function bars(): void
    {
        Instrument::factory()->create(['id' => self::INSTRUMENT]);
        App::make(PartitionManager::class)->ensureDailyYear(2019);

        DailyBar::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'date' => '2019-03-13',
        ]);
    }
}

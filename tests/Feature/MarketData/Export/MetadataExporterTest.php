<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Export;

use App\MarketData\Data\UniverseRulesData;
use App\MarketData\Export\MetadataExporter;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\UniverseDefinition;
use App\MarketData\Models\UniverseMember;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(MetadataExporter::class)]
final class MetadataExporterTest extends TestCase
{
    // DatabaseTruncation, ne RefreshDatabase: export běží jako samostatný proces
    // s vlastním připojením a data v nezacommitované transakci by neviděl.
    use DatabaseTruncation;

    private const string INSTRUMENT = '22222222-2222-4222-8222-222222222222';

    protected function tearDown(): void
    {
        foreach (['instruments', 'universe_members', 'market_days'] as $table) {
            $path = App::make(MetadataExporter::class)->pathFor($table);

            if (is_file($path) === true) {
                unlink($path);
            }
        }

        // DatabaseTruncation truncá jen před testem, ne po něm — bez tohoto zůstanou
        // commitnuté řádky v DB a rozbijí navazující RefreshDatabase testy.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function testExportWritesAllThreeFiles(): void
    {
        $this->seedFixtures();

        $counts = App::make(MetadataExporter::class)->export();

        $exporter = App::make(MetadataExporter::class);
        $this->assertFileExists($exporter->pathFor('instruments'));
        $this->assertFileExists($exporter->pathFor('universe_members'));
        $this->assertFileExists($exporter->pathFor('market_days'));
        $this->assertSame(1, $counts['instruments']);
        $this->assertSame(1, $counts['universe_members']);
        $this->assertSame(1, $counts['market_days']);
    }

    public function testExportEmptyDatabase(): void
    {
        $counts = App::make(MetadataExporter::class)->export();

        $this->assertSame(0, $counts['instruments']);
    }

    public function testExportFailingScriptThrow(): void
    {
        Config::set('market-data.metadata_script', '/neexistuje/meta.py');

        $this->expectException(RuntimeException::class);

        App::make(MetadataExporter::class)->export();
    }

    private function seedFixtures(): void
    {
        Instrument::factory()->create(['id' => self::INSTRUMENT]);
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);

        $definition = UniverseDefinition::query()->create([
            'name' => 'liquid_us',
            'version' => 1,
            'rules' => UniverseRulesData::fake(),
        ]);
        UniverseMember::query()->create([
            'definition_id' => $definition->id,
            'date' => '2019-03-13',
            'instrument_id' => self::INSTRUMENT,
        ]);
    }
}

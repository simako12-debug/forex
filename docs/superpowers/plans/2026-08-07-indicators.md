# Indicators Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Postavit v Pythonu indikátorovou vrstvu, která ze snapshotu podprojektu 1 spočítá per-instrument i cross-sectional featury tak, že u každé chybějící hodnoty je odlišitelné, jestli instrument nebyl listovaný, běží warm-up, nebo je v datech mezera.

**Architecture:** Široká matice (dny × instrumenty) v pandas. Snapshot z podprojektu 1 se rozšíří o metadatové Parquety a manifest s verzí adjustmentu, takže Python čte výhradně soubory a backtest je reprodukovatelný ze složky. Indikátory jsou vlastní tenké implementace s golden testy, které mají vzorec rozepsaný ve svém těle — konvence je tím zafixovaná a nemůže se tiše změnit.

**Tech Stack:** PHP 8.5 / Laravel 13 (rozšíření exportu), Python 3.13, pandas, numpy, pyarrow, DuckDB, pytest, ruff, mypy.

## Global Constraints

Platí pro každý task, i když ho task nezmiňuje.

### PHP strana (Etapa 2a)

- Platí **beze změny všechny Global Constraints z plánu podprojektu 1** (`docs/superpowers/plans/2026-08-06-market-data.md`): `declare(strict_types=1)`, typované parametry i návratové typy, 120 znaků, `=== false` místo `!`, žádné vnořené `if`, `null|string` pořadí, `{ClassName}Test` s `#[CoversClass]`, žádná síť v testech.
- V testech **nikdy** helper `app()` — vždy fasáda `App` (`App::make`, `App::instance`, `App::environment`).
- Po každé změně `vendor/bin/phpstan analyse` a `vendor/bin/phpcs`, obojí bez chyb.

### Python strana (Etapy 2b–2d)

- Cílem je stejná úroveň přísnosti jako na PHP straně, protože Python je od tohoto podprojektu produkčně kritický, ne research hračka.
- **Type hinty na všech veřejných funkcích a metodách**, ověřeno `mypy --strict` nad balíčkem `forx`.
- **Lint `ruff check`** bez chyb, maximální délka řádku **120 znaků** (shodně s PHP stranou).
- Veřejné datové struktury jsou `@dataclass(frozen=True)` — panel ani požadavek na featuru se po vytvoření nemění.
- **Žádná síť v testech, žádná závislost na reálném exportu.** Testy si Parquet fixture vygenerují samy.
- **Žádné `assert` v produkčním kódu** jako kontrola vstupu — chyba vstupu je vlastní výjimka s vysvětlením.
- Všechny indikátory jsou **kauzální**: hodnota k datu *D* používá jen data s datem ≤ *D*. Žádné centrované okno, žádný `shift(-1)`, žádná interpolace přes budoucí hodnoty.
- Peněžní a cenové porovnání v testech přes `pytest.approx` s explicitní tolerancí, nikdy `==` nad floaty.
- Příkazy se spouštějí z rootu projektu přes kontejner:
  `docker compose exec app <php příkaz>`, `docker compose exec research sh -c 'cd /app/research && <python příkaz>'`.

## Rozhodnutí učiněná při plánování

Specifikace tři věci neřešila a plán je musel dořešit.

1. **Snapshot se rozšiřuje o metadata.** Export z podprojektu 1 nese jen bary, ale `listed_mask` potřebuje `listed_at`/`delisted_at`, `cs_rank` potřebuje členství v univerzu k datu a rozlišení mezery od warm-upu potřebuje kalendář. Zadavatel zvolil rozšíření Parquet snapshotu (proti čtení živého Postgresu), protože backtest musí být reprodukovatelný ze složky — metadata se pod živou databází mohou změnit a starý výsledek by nešlo zopakovat.

2. **`validation_findings` se neexportují.** Specifikace u třetího druhu chybějící hodnoty píše, že mezeru „potvrzuje `MissingBarOnTradingDay` finding". Mezera jde ale odvodit soběstačně: instrument je listovaný, den je podle kalendáře otevřený, a bar chybí. Odvození nezávisí na tom, jestli ingest v té době běžel s daným pravidlem, takže je robustnější než čtení historie nálezů.

3. **Verze adjustmentu je ruční konstanta, ne hash.** Riziko ze specifikace zní „změní se logika adjustmentu v PHP a Parquet se nepřegeneruje". Obsahový hash faktorů by hlásil i neškodné přepočty nad novými daty. Konstanta `AdjustmentFactorCalculator::LOGIC_VERSION`, kterou zvedne ten, kdo mění vzorec, hlídá přesně to riziko a nic jiného. Python má očekávanou hodnotu u sebe a nesoulad je chyba, ne varování.

## File Structure

```
# Etapa 2a — PHP, rozšíření snapshotu
app/MarketData/Export/SnapshotExporter.php        orchestrace: bary + metadata + manifest
app/MarketData/Export/MetadataExporter.php        instruments, universe_members, market_days do Parquetu
app/MarketData/Export/SnapshotManifest.php        readonly DTO manifestu
app/MarketData/Console/ExportSnapshotCommand.php  market-data:export-snapshot
research/export_metadata.py                       DuckDB skript pro metadatové tabulky

# Etapa 2b–2d — Python, indikátorová vrstva
research/pyproject.toml                           balíček forx, ruff, mypy, pytest
research/forx/__init__.py                         veřejné API znovu-exportované
research/forx/errors.py                           AdjustmentVersionMismatch, InsufficientHistory, UnknownFeature
research/forx/request.py                          FeatureRequest + feature_id
research/forx/panel.py                            BarPanel, load_panel, listed_mask
research/forx/missing.py                          MissingReason a jeho odvození
research/forx/features/__init__.py                registr featur
research/forx/features/moving.py                  sma, ema
research/forx/features/wilder.py                  atr, rsi
research/forx/features/window.py                  rolling_high, rolling_low, dollar_volume_ma
research/forx/features/relative.py                relative_strength
research/forx/features/cross_section.py           cs_rank
research/forx/compute.py                          FeatureSet, compute()

research/tests/fixtures.py                        generátor kanonického Parquet snapshotu
research/tests/test_request.py
research/tests/test_panel.py
research/tests/test_missing.py
research/tests/test_moving.py
research/tests/test_wilder.py
research/tests/test_window.py
research/tests/test_relative.py
research/tests/test_cross_section.py
research/tests/test_compute.py
research/tests/test_causality.py
```

---

# Etapa 2a — rozšíření snapshotu

### Task 1: Verze adjustmentu a manifest snapshotu

Bez verze nejde splnit mitigaci rizika „divergence adjustmentu", které specifikace označuje za chybu, ne varování.

**Files:**
- Modify: `app/MarketData/Adjustment/AdjustmentFactorCalculator.php` (přidat konstantu)
- Create: `app/MarketData/Export/SnapshotManifest.php`
- Test: `tests/Unit/MarketData/Export/SnapshotManifestTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `AdjustmentFactorCalculator::LOGIC_VERSION` — `public const int`, hodnota `1`
  - `SnapshotManifest` — `final readonly`, konstruktor `(int $adjustmentLogicVersion, string $exportedAt, array $years, array $rowCounts)`, metoda `toArray(): array<string,mixed>` a statická `fromArray(array $payload): self`

- [ ] **Step 1: Napsat failující test**

`tests/Unit/MarketData/Export/SnapshotManifestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Export;

use App\MarketData\Export\SnapshotManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SnapshotManifest::class)]
final class SnapshotManifestTest extends TestCase
{
    public function testToArray(): void
    {
        $manifest = new SnapshotManifest(
            adjustmentLogicVersion: 1,
            exportedAt: '2026-08-07T10:00:00+00:00',
            years: [2019, 2020],
            rowCounts: ['daily_bars' => 42, 'instruments' => 5],
        );

        $this->assertSame([
            'adjustment_logic_version' => 1,
            'exported_at' => '2026-08-07T10:00:00+00:00',
            'years' => [2019, 2020],
            'row_counts' => ['daily_bars' => 42, 'instruments' => 5],
        ], $manifest->toArray());
    }

    public function testFromArray(): void
    {
        $manifest = SnapshotManifest::fromArray([
            'adjustment_logic_version' => 3,
            'exported_at' => '2026-08-07T10:00:00+00:00',
            'years' => [2019],
            'row_counts' => ['daily_bars' => 7],
        ]);

        $this->assertSame(3, $manifest->adjustmentLogicVersion);
        $this->assertSame([2019], $manifest->years);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new SnapshotManifest(1, '2026-08-07T10:00:00+00:00', [2019], ['daily_bars' => 1]);

        $this->assertEquals($original, SnapshotManifest::fromArray($original->toArray()));
    }
}
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec app vendor/bin/phpunit --filter=SnapshotManifestTest`
Expected: FAIL — `Class "App\MarketData\Export\SnapshotManifest" not found`

- [ ] **Step 3: Implementovat SnapshotManifest**

`app/MarketData/Export/SnapshotManifest.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Export;

final readonly class SnapshotManifest
{
    /**
     * @param array<int,int> $years
     * @param array<string,int> $rowCounts
     */
    public function __construct(
        public int $adjustmentLogicVersion,
        public string $exportedAt,
        public array $years,
        public array $rowCounts,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'adjustment_logic_version' => $this->adjustmentLogicVersion,
            'exported_at' => $this->exportedAt,
            'years' => $this->years,
            'row_counts' => $this->rowCounts,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var array<int,int> $years */
        $years = $payload['years'];
        /** @var array<string,int> $rowCounts */
        $rowCounts = $payload['row_counts'];

        return new self(
            adjustmentLogicVersion: (int) $payload['adjustment_logic_version'],
            exportedAt: (string) $payload['exported_at'],
            years: $years,
            rowCounts: $rowCounts,
        );
    }
}
```

- [ ] **Step 4: Přidat konstantu verze do kalkulátoru**

Do `app/MarketData/Adjustment/AdjustmentFactorCalculator.php`, hned za `class AdjustmentFactorCalculator` a před `recalculate()`:

```php
    /**
     * Zvedni tuhle konstantu VŽDY, když se změní vzorec adjustmentu. Python má
     * očekávanou hodnotu u sebe a při nesouladu odmítne snapshot načíst — což je
     * jediná ochrana proti tomu, aby indikátory tiše jely nad starými cenami.
     */
    public const int LOGIC_VERSION = 1;
```

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `docker compose exec app vendor/bin/phpunit --filter=SnapshotManifestTest`
Expected: PASS, 3 testy

- [ ] **Step 6: Statická analýza, code style, commit**

```bash
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: verze logiky adjustmentu a manifest snapshotu"
```

---

### Task 2: Export metadatových tabulek do Parquetu

**Files:**
- Create: `research/export_metadata.py`
- Create: `app/MarketData/Export/MetadataExporter.php`
- Test: `tests/Feature/MarketData/Export/MetadataExporterTest.php`

**Interfaces:**
- Consumes: `ParquetExporter` z podprojektu 1 (vzor pro spouštění skriptu), `Instrument`, `UniverseDefinition`, `MarketDay`
- Produces:
  - `MetadataExporter::export(): array<string,int>` — vrátí počty řádků po tabulkách, klíče `instruments`, `universe_members`, `market_days`
  - `MetadataExporter::pathFor(string $table): string` — `{shared}/meta/{table}.parquet`
  - Python `export_metadata.py --out DIR --dsn DSN` — vypíše JSON s počty řádků

- [ ] **Step 1: Napsat Python skript**

`research/export_metadata.py`:

```python
"""Vyexportuje metadatové tabulky z Postgresu do Parquetu pomocí DuckDB.

Metadata jsou malá (jednotky MB), takže se exportují celá, bez dělení po letech.
Univerzum se exportuje rozbalené o jméno a verzi definice, aby Python nemusel
dělat join — snapshot má být čitelný sám o sobě.
"""

import argparse
import json
import os
import sys

import duckdb

QUERIES = {
    "instruments": """
        SELECT id, name, asset_class, primary_exchange, sector,
               listed_at, delisted_at, delisting_reason
        FROM pg.public.instruments
        ORDER BY id
    """,
    "universe_members": """
        SELECT d.name AS definition_name, d.version AS definition_version,
               m.date, m.instrument_id
        FROM pg.public.universe_members AS m
        JOIN pg.public.universe_definitions AS d ON d.id = m.definition_id
        ORDER BY d.name, d.version, m.date, m.instrument_id
    """,
    "market_days": """
        SELECT exchange, date, is_open, is_early_close
        FROM pg.public.market_days
        ORDER BY exchange, date
    """,
}


def export_metadata(out_dir: str, dsn: str) -> dict[str, int]:
    os.makedirs(out_dir, exist_ok=True)

    con = duckdb.connect()
    con.execute("INSTALL postgres; LOAD postgres;")
    con.execute(f"ATTACH '{dsn}' AS pg (TYPE POSTGRES, READ_ONLY)")

    counts: dict[str, int] = {}

    for table, query in QUERIES.items():
        out_path = os.path.join(out_dir, f"{table}.parquet")
        tmp_path = f"{out_path}.tmp"

        con.execute(f"COPY ({query}) TO '{tmp_path}' (FORMAT PARQUET, COMPRESSION ZSTD)")
        counts[table] = int(
            con.execute(f"SELECT count(*) FROM read_parquet('{tmp_path}')").fetchone()[0]
        )
        os.replace(tmp_path, out_path)

    con.close()

    return counts


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--dsn", required=True)
    args = parser.parse_args()

    print(json.dumps(export_metadata(args.out, args.dsn)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 2: Napsat failující test PHP exportéru**

`tests/Feature/MarketData/Export/MetadataExporterTest.php`:

```php
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
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(MetadataExporter::class)]
final class MetadataExporterTest extends TestCase
{
    // DatabaseTruncation, ne RefreshDatabase: export běží jako samostatný proces
    // s vlastním připojením a data v nezacommitované transakci by neviděl.
    use DatabaseTruncation;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    protected function tearDown(): void
    {
        foreach (['instruments', 'universe_members', 'market_days'] as $table) {
            $path = App::make(MetadataExporter::class)->pathFor($table);

            if (is_file($path) === true) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testExportWritesAllThreeFiles(): void
    {
        $this->seed();

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
        \Illuminate\Support\Facades\Config::set('market-data.metadata_script', '/neexistuje/meta.py');

        $this->expectException(RuntimeException::class);

        App::make(MetadataExporter::class)->export();
    }

    private function seed(): void
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
```

- [ ] **Step 3: Spustit test a ověřit selhání**

Run: `docker compose exec app vendor/bin/phpunit --filter=MetadataExporterTest`
Expected: FAIL — `Class "App\MarketData\Export\MetadataExporter" not found`

- [ ] **Step 4: Doplnit konfiguraci**

Do `config/market-data.php`, před uzavírací `];`:

```php
    'metadata_script' => env('MARKET_DATA_METADATA_SCRIPT', base_path('research/export_metadata.py')),
```

- [ ] **Step 5: Implementovat MetadataExporter**

`app/MarketData/Export/MetadataExporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Export;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class MetadataExporter
{
    private const int TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly string $sharedPath,
        private readonly string $scriptPath,
        private readonly string $pythonBinary,
        private readonly string $dsn,
    ) {
    }

    /** @return array<string,int> */
    public function export(): array
    {
        $process = new Process([
            $this->pythonBinary,
            $this->scriptPath,
            '--out',
            $this->metaDirectory(),
            '--dsn',
            $this->dsn,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if ($process->isSuccessful() === false) {
            throw new RuntimeException(sprintf(
                'Export metadat selhal (exit %s): %s',
                (string) $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }

        return $this->decodeCounts($process->getOutput());
    }

    public function pathFor(string $table): string
    {
        return sprintf('%s/%s.parquet', $this->metaDirectory(), $table);
    }

    private function metaDirectory(): string
    {
        return rtrim($this->sharedPath, '/') . '/meta';
    }

    /** @return array<string,int> */
    private function decodeCounts(string $output): array
    {
        try {
            /** @var array<string,int> $counts */
            $counts = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Export metadat nevrátil JSON: %s', $output),
                previous: $exception,
            );
        }

        return $counts;
    }
}
```

- [ ] **Step 6: Zaregistrovat v kontejneru**

Do `app/Providers/AppServiceProvider.php`, do `register()` hned za binding `ParquetExporter::class`:

```php
        $this->app->bind(MetadataExporter::class, fn (): MetadataExporter => new MetadataExporter(
            sharedPath: Config::string('market-data.shared_path'),
            scriptPath: Config::string('market-data.metadata_script'),
            pythonBinary: Config::string('market-data.python_binary'),
            dsn: $this->postgresDsn(),
        ));
```

A na začátek souboru mezi ostatní importy:

```php
use App\MarketData\Export\MetadataExporter;
```

- [ ] **Step 7: Spustit test a ověřit zelenou**

Run: `docker compose exec app vendor/bin/phpunit --filter=MetadataExporterTest`
Expected: PASS, 3 testy

- [ ] **Step 8: Statická analýza, code style, commit**

```bash
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/phpcs
git add app config research tests
git commit -m "feat: export metadatových tabulek do Parquetu"
```

---

### Task 3: SnapshotExporter a příkaz market-data:export-snapshot

Deliverable je jedna složka, ze které Python přečte všechno, co potřebuje.

**Files:**
- Create: `app/MarketData/Export/SnapshotExporter.php`
- Create: `app/MarketData/Console/ExportSnapshotCommand.php`
- Modify: `bootstrap/app.php` (registrace příkazu)
- Test: `tests/Feature/MarketData/Export/SnapshotExporterTest.php`

**Interfaces:**
- Consumes: `ParquetExporter` (podprojekt 1), `MetadataExporter` (Task 2), `SnapshotManifest` (Task 1)
- Produces:
  - `SnapshotExporter::export(array $years): SnapshotManifest` — vyexportuje bary za zadané roky, metadata a zapíše `manifest.json`
  - `SnapshotExporter::manifestPath(): string`
  - Command `market-data:export-snapshot {--from-year=} {--to-year=}`

- [ ] **Step 1: Napsat failující test**

`tests/Feature/MarketData/Export/SnapshotExporterTest.php`:

```php
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
use Tests\TestCase;

#[CoversClass(SnapshotExporter::class)]
final class SnapshotExporterTest extends TestCase
{
    use DatabaseTruncation;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

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
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec app vendor/bin/phpunit --filter=SnapshotExporterTest`
Expected: FAIL — `Class "App\MarketData\Export\SnapshotExporter" not found`

- [ ] **Step 3: Implementovat SnapshotExporter**

`app/MarketData/Export/SnapshotExporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Export;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Models\DailyBar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Snapshot je jedna složka, ze které Python přečte všechno potřebné:
 *
 *   {shared}/manifest.json
 *   {shared}/daily/year=YYYY/part.parquet
 *   {shared}/meta/{instruments,universe_members,market_days}.parquet
 *
 * Manifest se zapisuje jako poslední. Kdyby se export v půlce rozbil, chybí
 * manifest a Python snapshot odmítne — místo aby počítal nad polovinou dat.
 */
class SnapshotExporter
{
    public function __construct(
        private readonly ParquetExporter $bars,
        private readonly MetadataExporter $metadata,
        private readonly string $sharedPath,
    ) {
    }

    /** @param array<int,int> $years */
    public function export(array $years): SnapshotManifest
    {
        foreach ($years as $year) {
            $this->bars->exportYear($year);
        }

        $rowCounts = $this->metadata->export();
        $rowCounts['daily_bars'] = DailyBar::query()->count();

        $manifest = new SnapshotManifest(
            adjustmentLogicVersion: AdjustmentFactorCalculator::LOGIC_VERSION,
            exportedAt: CarbonImmutable::now()->toIso8601String(),
            years: array_values($years),
            rowCounts: $rowCounts,
        );

        File::ensureDirectoryExists(dirname($this->manifestPath()));
        File::put(
            $this->manifestPath(),
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $manifest;
    }

    public function manifestPath(): string
    {
        return rtrim($this->sharedPath, '/') . '/manifest.json';
    }
}
```

- [ ] **Step 4: Zaregistrovat v kontejneru**

Do `app/Providers/AppServiceProvider.php`, do `register()` za binding `MetadataExporter::class`:

```php
        $this->app->bind(SnapshotExporter::class, fn (): SnapshotExporter => new SnapshotExporter(
            bars: $this->app->make(ParquetExporter::class),
            metadata: $this->app->make(MetadataExporter::class),
            sharedPath: Config::string('market-data.shared_path'),
        ));
```

A import `use App\MarketData\Export\SnapshotExporter;`.

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `docker compose exec app vendor/bin/phpunit --filter=SnapshotExporterTest`
Expected: PASS, 3 testy

- [ ] **Step 6: Napsat příkaz**

`app/MarketData/Console/ExportSnapshotCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Export\SnapshotExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExportSnapshotCommand extends Command
{
    private const int DEFAULT_FROM_YEAR = 2000;

    /** @var string */
    protected $signature = 'market-data:export-snapshot {--from-year=} {--to-year=}';

    /** @var string */
    protected $description = 'Vyexportuje kompletní snapshot pro Python: bary, metadata a manifest';

    public function handle(SnapshotExporter $exporter): int
    {
        $fromYear = $this->yearOption('from-year', self::DEFAULT_FROM_YEAR);
        $toYear = $this->yearOption('to-year', CarbonImmutable::now()->year);

        $manifest = $exporter->export(range($fromYear, $toYear));

        $this->info(sprintf(
            'Snapshot zapsán: roky %d–%d, %d barů, verze adjustmentu %d.',
            $fromYear,
            $toYear,
            $manifest->rowCounts['daily_bars'] ?? 0,
            $manifest->adjustmentLogicVersion,
        ));

        return self::SUCCESS;
    }

    private function yearOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_numeric($value) === false) {
            return $default;
        }

        return (int) $value;
    }
}
```

Do `bootstrap/app.php` přidat import `use App\MarketData\Console\ExportSnapshotCommand;` a do pole `->withCommands([...])` řádek `ExportSnapshotCommand::class,` (pole je řazené abecedně, patří za `EnsurePartitionsCommand::class`).

- [ ] **Step 7: Ověřit příkaz, statická analýza, code style, commit**

```bash
docker compose exec app php artisan list market-data
docker compose exec app php artisan test
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/phpcs
git add app bootstrap tests
git commit -m "feat: export kompletního snapshotu pro Python"
```

Expected: `market-data:export-snapshot` je ve výpisu, všechny testy zelené.

---

# Etapa 2b — základ Python vrstvy

### Task 4: Balíček forx, nástroje kvality a kanonický fixture

Bez fixture nejde napsat jediný test dalších tasků, takže patří sem.

**Files:**
- Modify: `research/pyproject.toml`
- Create: `research/forx/__init__.py`, `research/forx/errors.py`
- Create: `research/tests/fixtures.py`
- Test: `research/tests/test_fixtures.py`

**Interfaces:**
- Consumes: snapshot z Tasku 3 (tvar složky)
- Produces:
  - `forx.errors.AdjustmentVersionMismatch`, `forx.errors.InsufficientHistory`, `forx.errors.UnknownFeature` — všechny dědí z `forx.errors.ForxError`
  - `tests.fixtures.write_snapshot(root: Path) -> SnapshotSpec` — zapíše kanonický snapshot a vrátí popis toho, co v něm je
  - `tests.fixtures.SnapshotSpec` — `@dataclass(frozen=True)` s poli `dates`, `instrument_ids`, `delisted_id`, `latecomer_id`, `gap_id`, `gap_dates`, `benchmark_id`

- [ ] **Step 1: Rozšířit pyproject.toml**

`research/pyproject.toml` — nahradit celý obsah:

```toml
[project]
name = "forx-research"
version = "0.2.0"
description = "Python vrstva pro Forx — indikátory, export Parquetu a kontraktní testy schématu"
requires-python = ">=3.13"
dependencies = [
    "duckdb>=1.1",
    "pyarrow>=17",
    "pandas>=2.2",
    "numpy>=2.0",
    "psycopg[binary]>=3.2",
]

[project.optional-dependencies]
dev = ["pytest>=8.3", "ruff>=0.6", "mypy>=1.11", "pandas-stubs>=2.2"]

[build-system]
requires = ["setuptools>=68"]
build-backend = "setuptools.build_meta"

[tool.setuptools]
packages = ["forx", "forx.features"]

[tool.pytest.ini_options]
testpaths = ["tests"]

[tool.ruff]
line-length = 120
target-version = "py313"

[tool.ruff.lint]
select = ["E", "F", "I", "N", "UP", "B", "SIM", "RET"]

[tool.mypy]
python_version = "3.13"
strict = true
files = ["forx"]
```

- [ ] **Step 2: Doinstalovat nástroje do research image**

Do `.docker/research/Dockerfile` nahradit řádek s `pip install`:

```dockerfile
RUN pip install --no-cache-dir duckdb pyarrow pandas numpy pytest ruff mypy pandas-stubs psycopg[binary]
```

Pak přestavět:

```bash
docker compose build research
docker compose up -d --force-recreate research
```

- [ ] **Step 3: Napsat modul výjimek**

`research/forx/errors.py`:

```python
"""Chybové stavy indikátorové vrstvy.

Každý z nich je hlasité odmítnutí, ne tiché NaN. Specifikace to vyžaduje
u warm-upu i u nesouladu verze adjustmentu.
"""


class ForxError(Exception):
    """Základ pro všechny chyby této vrstvy."""


class AdjustmentVersionMismatch(ForxError):
    """Snapshot byl vyexportován jinou verzí adjustment logiky, než Python očekává."""

    def __init__(self, expected: int, found: int) -> None:
        super().__init__(
            f"Snapshot nese verzi adjustmentu {found}, očekává se {expected}. "
            "Přegeneruj snapshot příkazem market-data:export-snapshot."
        )
        self.expected = expected
        self.found = found


class InsufficientHistory(ForxError):
    """Featura potřebuje delší warm-up, než kolik je v panelu dní."""

    def __init__(self, feature_id: str, required: int, available: int) -> None:
        super().__init__(
            f"Featura {feature_id} potřebuje {required} dní historie, panel jich má {available}."
        )
        self.feature_id = feature_id
        self.required = required
        self.available = available


class UnknownFeature(ForxError):
    """Požadavek se odkazuje na featuru, která není v registru."""

    def __init__(self, name: str) -> None:
        super().__init__(f"Neznámá featura: {name}")
        self.name = name
```

`research/forx/__init__.py`:

```python
"""Indikátorová vrstva Forx."""

from forx.errors import AdjustmentVersionMismatch, ForxError, InsufficientHistory, UnknownFeature

__all__ = ["AdjustmentVersionMismatch", "ForxError", "InsufficientHistory", "UnknownFeature"]
```

- [ ] **Step 4: Napsat failující test fixture**

`research/tests/test_fixtures.py`:

```python
from pathlib import Path

import pyarrow.parquet as pq

from tests.fixtures import write_snapshot


def test_write_snapshot_creates_layout(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    assert (tmp_path / "manifest.json").exists()
    assert (tmp_path / "meta" / "instruments.parquet").exists()
    assert (tmp_path / "meta" / "universe_members.parquet").exists()
    assert (tmp_path / "meta" / "market_days.parquet").exists()
    assert len(spec.dates) == 250
    assert len(spec.instrument_ids) == 4


def test_write_snapshot_bars_have_expected_columns(tmp_path: Path) -> None:
    write_snapshot(tmp_path)

    year_dirs = sorted((tmp_path / "daily").glob("year=*"))
    schema = pq.read_schema(year_dirs[0] / "part.parquet")

    assert {"instrument_id", "date", "close", "volume", "adj_close", "adj_volume"} <= set(schema.names)


def test_write_snapshot_gap_instrument_is_missing_bars(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    year_dirs = sorted((tmp_path / "daily").glob("year=*"))
    table = pq.read_table(year_dirs[0] / "part.parquet").to_pandas()
    gap_rows = table[(table["instrument_id"] == spec.gap_id) & (table["date"].isin(spec.gap_dates))]

    assert len(gap_rows) == 0
```

- [ ] **Step 5: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_fixtures.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'tests.fixtures'`

- [ ] **Step 6: Implementovat generátor fixture**

`research/tests/fixtures.py`:

```python
"""Kanonický snapshot pro testy indikátorové vrstvy.

Je záměrně jiný než fixture podprojektu 1: menší (4 instrumenty × 250 dní) a
zaměřený na to, co potřebuje indikátorová vrstva — delisting v polovině, pozdní
vstup, třídenní mezera a benchmark s plnou historií.

Ceny jsou deterministické, počítané ze vzorce, ne náhodné. Golden testy potřebují
znát hodnoty předem.
"""

import json
import math
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path

import pandas as pd

ADJUSTMENT_LOGIC_VERSION = 1

FULL_ID = "11111111-1111-1111-1111-111111111111"
DELISTED_ID = "22222222-2222-2222-2222-222222222222"
LATECOMER_ID = "33333333-3333-3333-3333-333333333333"
BENCHMARK_ID = "44444444-4444-4444-4444-444444444444"

START = date(2019, 1, 1)
TRADING_DAYS = 250
DELISTING_INDEX = 125
LATECOMER_INDEX = 50
GAP_INDEXES = (200, 201, 202)


@dataclass(frozen=True)
class SnapshotSpec:
    """Popis toho, co ve fixture je — testy proti němu tvrdí, ne proti magickým konstantám."""

    dates: tuple[date, ...]
    instrument_ids: tuple[str, ...]
    delisted_id: str
    delisted_last_date: date
    latecomer_id: str
    latecomer_first_date: date
    gap_id: str
    gap_dates: tuple[date, ...]
    benchmark_id: str


def _trading_dates() -> tuple[date, ...]:
    dates: list[date] = []
    day = START

    while len(dates) < TRADING_DAYS:
        if day.weekday() < 5:
            dates.append(day)

        day += timedelta(days=1)

    return tuple(dates)


def _close_for(instrument_id: str, index: int) -> float:
    """Deterministická, hladká a nezáporná řada. Sinus dá lokální maxima i minima,
    takže rolling_high a rolling_low mají co najít."""
    base = {FULL_ID: 100.0, DELISTED_ID: 50.0, LATECOMER_ID: 20.0, BENCHMARK_ID: 200.0}[instrument_id]

    return round(base * (1.0 + 0.05 * math.sin(index / 7.0)) + index * 0.01, 4)


def _is_active(instrument_id: str, index: int) -> bool:
    if instrument_id == DELISTED_ID:
        return index <= DELISTING_INDEX

    if instrument_id == LATECOMER_ID:
        return index >= LATECOMER_INDEX

    return True


def write_snapshot(root: Path) -> SnapshotSpec:
    dates = _trading_dates()
    instrument_ids = (FULL_ID, DELISTED_ID, LATECOMER_ID, BENCHMARK_ID)

    _write_bars(root, dates, instrument_ids)
    _write_metadata(root, dates, instrument_ids)
    _write_manifest(root, dates)

    return SnapshotSpec(
        dates=dates,
        instrument_ids=instrument_ids,
        delisted_id=DELISTED_ID,
        delisted_last_date=dates[DELISTING_INDEX],
        latecomer_id=LATECOMER_ID,
        latecomer_first_date=dates[LATECOMER_INDEX],
        gap_id=FULL_ID,
        gap_dates=tuple(dates[i] for i in GAP_INDEXES),
        benchmark_id=BENCHMARK_ID,
    )


def _write_bars(root: Path, dates: tuple[date, ...], instrument_ids: tuple[str, ...]) -> None:
    rows: list[dict[str, object]] = []

    for index, day in enumerate(dates):
        for instrument_id in instrument_ids:
            if not _is_active(instrument_id, index):
                continue

            if instrument_id == FULL_ID and index in GAP_INDEXES:
                continue

            close = _close_for(instrument_id, index)
            rows.append(
                {
                    "instrument_id": instrument_id,
                    "date": day,
                    "open": close,
                    "high": close + 1.0,
                    "low": close - 1.0,
                    "close": close,
                    "volume": 1_000_000 + index * 1_000,
                    "adj_open": close,
                    "adj_high": close + 1.0,
                    "adj_low": close - 1.0,
                    "adj_close": close,
                    "adj_volume": 1_000_000 + index * 1_000,
                    "cum_split_factor": 1.0,
                    "cum_div_factor": 1.0,
                    "source": "fixture",
                }
            )

    frame = pd.DataFrame(rows)
    frame["date"] = pd.to_datetime(frame["date"])

    for year, group in frame.groupby(frame["date"].dt.year):
        year_dir = root / "daily" / f"year={year}"
        year_dir.mkdir(parents=True, exist_ok=True)
        group.to_parquet(year_dir / "part.parquet", index=False)


def _write_metadata(root: Path, dates: tuple[date, ...], instrument_ids: tuple[str, ...]) -> None:
    meta_dir = root / "meta"
    meta_dir.mkdir(parents=True, exist_ok=True)

    instruments = pd.DataFrame(
        [
            {
                "id": instrument_id,
                "name": f"Fixture {instrument_id[:8]}",
                "asset_class": "us_equity",
                "primary_exchange": "NYSE",
                "sector": "Industrials",
                "listed_at": dates[LATECOMER_INDEX] if instrument_id == LATECOMER_ID else dates[0],
                "delisted_at": dates[DELISTING_INDEX] if instrument_id == DELISTED_ID else None,
                "delisting_reason": "acquired" if instrument_id == DELISTED_ID else None,
            }
            for instrument_id in instrument_ids
        ]
    )
    instruments["listed_at"] = pd.to_datetime(instruments["listed_at"])
    instruments["delisted_at"] = pd.to_datetime(instruments["delisted_at"])
    instruments.to_parquet(meta_dir / "instruments.parquet", index=False)

    members = pd.DataFrame(
        [
            {
                "definition_name": "liquid_us",
                "definition_version": 1,
                "date": day,
                "instrument_id": instrument_id,
            }
            for index, day in enumerate(dates)
            for instrument_id in instrument_ids
            if _is_active(instrument_id, index)
        ]
    )
    members["date"] = pd.to_datetime(members["date"])
    members.to_parquet(meta_dir / "universe_members.parquet", index=False)

    market_days = pd.DataFrame(
        [{"exchange": "XNYS", "date": day, "is_open": True, "is_early_close": False} for day in dates]
    )
    market_days["date"] = pd.to_datetime(market_days["date"])
    market_days.to_parquet(meta_dir / "market_days.parquet", index=False)


def _write_manifest(root: Path, dates: tuple[date, ...]) -> None:
    payload = {
        "adjustment_logic_version": ADJUSTMENT_LOGIC_VERSION,
        "exported_at": "2026-08-07T10:00:00+00:00",
        "years": sorted({day.year for day in dates}),
        "row_counts": {"daily_bars": 0},
    }
    (root / "manifest.json").write_text(json.dumps(payload), encoding="utf-8")
```

- [ ] **Step 7: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_fixtures.py -q'`
Expected: PASS, 3 testy

- [ ] **Step 8: Ověřit lint a typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research .docker
git commit -m "feat: python balíček forx, nástroje kvality a kanonický fixture"
```

Expected: `ruff` i `mypy` bez chyb.

---

### Task 5: FeatureRequest a deterministické feature_id

`feature_id` se ukládá do záznamu o backtest běhu, takže musí být stabilní napříč pořadím parametrů.

**Files:**
- Create: `research/forx/request.py`
- Modify: `research/forx/__init__.py`
- Test: `research/tests/test_request.py`

**Interfaces:**
- Consumes: nic
- Produces:
  - `forx.request.FeatureRequest` — `@dataclass(frozen=True)` s poli `name: str`, `params: Mapping[str, object]`, `input: str = "adj_close"`
  - `FeatureRequest.feature_id: str` — property, formát `name(input=...,param=...)` s parametry řazenými podle názvu

- [ ] **Step 1: Napsat failující test**

`research/tests/test_request.py`:

```python
import pytest

from forx.request import FeatureRequest


def test_feature_id_sorts_params_by_name() -> None:
    request = FeatureRequest(name="sma", params={"window": 20})

    assert request.feature_id == "sma(input=adj_close,window=20)"


def test_feature_id_is_stable_across_param_order() -> None:
    first = FeatureRequest(name="relative_strength", params={"window": 20, "benchmark": "SPY"})
    second = FeatureRequest(name="relative_strength", params={"benchmark": "SPY", "window": 20})

    assert first.feature_id == second.feature_id


def test_feature_id_includes_non_default_input() -> None:
    request = FeatureRequest(name="sma", params={"window": 5}, input="adj_volume")

    assert request.feature_id == "sma(input=adj_volume,window=5)"


def test_feature_id_without_params() -> None:
    request = FeatureRequest(name="dollar_volume", params={})

    assert request.feature_id == "dollar_volume(input=adj_close)"


def test_request_is_frozen() -> None:
    request = FeatureRequest(name="sma", params={"window": 5})

    with pytest.raises(AttributeError):
        request.name = "ema"  # type: ignore[misc]
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_request.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.request'`

- [ ] **Step 3: Implementovat FeatureRequest**

`research/forx/request.py`:

```python
"""Požadavek na featuru a jeho deterministický identifikátor."""

from collections.abc import Mapping
from dataclasses import dataclass, field

VALID_INPUTS = frozenset(
    {"adj_open", "adj_high", "adj_low", "adj_close", "adj_volume", "close", "volume", "dollar_volume"}
)


@dataclass(frozen=True)
class FeatureRequest:
    """Co se má spočítat.

    feature_id není kosmetika — ukládá se do záznamu o backtest běhu, aby šlo
    po měsících zjistit, co přesně se počítalo. Parametry se proto řadí podle
    názvu, aby stejná featura měla vždy stejné ID nezávisle na pořadí zápisu.
    """

    name: str
    params: Mapping[str, object] = field(default_factory=dict)
    input: str = "adj_close"

    @property
    def feature_id(self) -> str:
        parts = [f"input={self.input}"]
        parts.extend(f"{key}={self.params[key]}" for key in sorted(self.params))

        return f"{self.name}({','.join(parts)})"
```

Do `research/forx/__init__.py` přidat:

```python
from forx.request import FeatureRequest
```

a doplnit `"FeatureRequest"` do `__all__`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_request.py -q'`
Expected: PASS, 5 testů

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: FeatureRequest s deterministickým feature_id"
```

---

### Task 6: load_panel, BarPanel a listed_mask

**Files:**
- Create: `research/forx/panel.py`
- Modify: `research/forx/__init__.py`
- Test: `research/tests/test_panel.py`

**Interfaces:**
- Consumes: `forx.errors.AdjustmentVersionMismatch` (Task 4), fixture z Tasku 4
- Produces:
  - `forx.panel.EXPECTED_ADJUSTMENT_LOGIC_VERSION: int` — hodnota `1`
  - `forx.panel.BarPanel` — `@dataclass(frozen=True)` s `adj_open`, `adj_high`, `adj_low`, `adj_close`, `adj_volume`, `close`, `volume`, `listed_mask`, `universe_mask`, všechno `pd.DataFrame` se stejným indexem i sloupci
  - `forx.panel.load_panel(start, end, instrument_ids, parquet_root, universe=("liquid_us", 1)) -> BarPanel`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_panel.py`:

```python
import json
from pathlib import Path

import pytest

from forx.errors import AdjustmentVersionMismatch
from forx.panel import load_panel
from tests.fixtures import write_snapshot


def test_load_panel_shape(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    assert panel.adj_close.shape == (len(spec.dates), len(spec.instrument_ids))
    assert list(panel.adj_close.columns) == sorted(spec.instrument_ids)


def test_load_panel_listed_mask_excludes_delisted(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    mask = panel.listed_mask[spec.delisted_id]

    assert bool(mask.loc[str(spec.delisted_last_date)]) is True
    assert bool(mask.iloc[-1]) is False


def test_load_panel_listed_mask_excludes_before_listing(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    mask = panel.listed_mask[spec.latecomer_id]

    assert bool(mask.iloc[0]) is False
    assert bool(mask.loc[str(spec.latecomer_first_date)]) is True


def test_load_panel_exposes_raw_and_adjusted_frames(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    # Indikátory jedou nad upravenými cenami, likvidita nad surovými — panel
    # proto musí nést obojí ve stejném tvaru.
    assert panel.close.shape == panel.adj_close.shape
    assert panel.volume.shape == panel.adj_volume.shape
    assert list(panel.close.columns) == list(panel.adj_close.columns)


def test_load_panel_dollar_volume_uses_raw_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    dollar_volume = panel.frame("dollar_volume")

    expected = panel.close.iloc[100][spec.benchmark_id] * panel.volume.iloc[100][spec.benchmark_id]
    assert dollar_volume.iloc[100][spec.benchmark_id] == pytest.approx(expected)


def test_load_panel_rejects_wrong_adjustment_version(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    manifest_path = tmp_path / "manifest.json"
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    payload["adjustment_logic_version"] = 99
    manifest_path.write_text(json.dumps(payload), encoding="utf-8")

    with pytest.raises(AdjustmentVersionMismatch):
        load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_load_panel_universe_mask_follows_membership(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    assert bool(panel.universe_mask[spec.latecomer_id].iloc[0]) is False
    assert bool(panel.universe_mask[spec.latecomer_id].loc[str(spec.latecomer_first_date)]) is True
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_panel.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.panel'`

- [ ] **Step 3: Implementovat panel**

`research/forx/panel.py`:

```python
"""Načtení snapshotu do širokých matic.

Index jsou obchodní dny z kalendáře, sloupce instrumenty. Cross-sectional operace
je pak jeden řádkový výpočet a per-instrument operace jeden sloupcový.

Panel se staví vždy jen nad zadanými instrumenty, nikdy nad celým katalogem:
1500 instrumentů × 6300 dní je ~75 MB na featuru, 16k instrumentů by bylo ~800 MB.
"""

import json
from dataclasses import dataclass
from datetime import date
from pathlib import Path

import pandas as pd

from forx.errors import AdjustmentVersionMismatch

EXPECTED_ADJUSTMENT_LOGIC_VERSION = 1

DEFAULT_UNIVERSE = ("liquid_us", 1)

_BAR_COLUMNS = ("adj_open", "adj_high", "adj_low", "adj_close", "adj_volume", "close", "volume")


@dataclass(frozen=True)
class BarPanel:
    """Široké matice OHLCV pro jedno období a jednu množinu instrumentů.

    Vedle hodnot nese dvě masky. listed_mask říká, jestli instrument k tomu dni
    vůbec existoval — bez ní by nešlo odlišit „nelistovaný" od warm-upu.
    universe_mask říká, jestli byl k tomu dni členem univerza; cross-sectional
    rank se počítá jen nad členy k danému dni, ne nad dnešním univerzem.
    """

    adj_open: pd.DataFrame
    adj_high: pd.DataFrame
    adj_low: pd.DataFrame
    adj_close: pd.DataFrame
    adj_volume: pd.DataFrame
    close: pd.DataFrame
    volume: pd.DataFrame
    listed_mask: pd.DataFrame
    universe_mask: pd.DataFrame

    def frame(self, name: str) -> pd.DataFrame:
        """Vrátí matici podle jména vstupu z FeatureRequest.input."""
        if name == "dollar_volume":
            return self.close * self.volume

        return getattr(self, name)  # type: ignore[no-any-return]


def load_panel(
    start: date,
    end: date,
    instrument_ids: list[str],
    parquet_root: Path,
    universe: tuple[str, int] = DEFAULT_UNIVERSE,
) -> BarPanel:
    _verify_manifest(parquet_root)

    columns = sorted(instrument_ids)
    trading_days = _trading_days(parquet_root, start, end)
    bars = _read_bars(parquet_root, start, end, columns)

    frames = {
        name: _pivot(bars, name, trading_days, columns) for name in _BAR_COLUMNS
    }

    return BarPanel(
        **frames,
        listed_mask=_listed_mask(parquet_root, trading_days, columns),
        universe_mask=_universe_mask(parquet_root, trading_days, columns, universe),
    )


def _verify_manifest(parquet_root: Path) -> None:
    payload = json.loads((parquet_root / "manifest.json").read_text(encoding="utf-8"))
    found = int(payload["adjustment_logic_version"])

    if found != EXPECTED_ADJUSTMENT_LOGIC_VERSION:
        raise AdjustmentVersionMismatch(EXPECTED_ADJUSTMENT_LOGIC_VERSION, found)


def _trading_days(parquet_root: Path, start: date, end: date) -> pd.DatetimeIndex:
    calendar = pd.read_parquet(parquet_root / "meta" / "market_days.parquet")
    calendar = calendar[calendar["is_open"]]
    days = pd.to_datetime(calendar["date"])
    selected = days[(days >= pd.Timestamp(start)) & (days <= pd.Timestamp(end))]

    return pd.DatetimeIndex(sorted(selected.unique()))


def _read_bars(parquet_root: Path, start: date, end: date, columns: list[str]) -> pd.DataFrame:
    years = range(start.year, end.year + 1)
    parts = [
        pd.read_parquet(parquet_root / "daily" / f"year={year}" / "part.parquet")
        for year in years
        if (parquet_root / "daily" / f"year={year}" / "part.parquet").exists()
    ]

    if not parts:
        return pd.DataFrame(columns=["instrument_id", "date", *_BAR_COLUMNS])

    bars = pd.concat(parts, ignore_index=True)
    bars["date"] = pd.to_datetime(bars["date"])

    return bars[bars["instrument_id"].isin(columns)]


def _pivot(
    bars: pd.DataFrame, value: str, index: pd.DatetimeIndex, columns: list[str]
) -> pd.DataFrame:
    if bars.empty:
        return pd.DataFrame(index=index, columns=columns, dtype="float64")

    wide = bars.pivot_table(index="date", columns="instrument_id", values=value, aggfunc="last")

    return wide.reindex(index=index, columns=columns).astype("float64")


def _listed_mask(parquet_root: Path, index: pd.DatetimeIndex, columns: list[str]) -> pd.DataFrame:
    instruments = pd.read_parquet(parquet_root / "meta" / "instruments.parquet").set_index("id")
    mask = pd.DataFrame(False, index=index, columns=columns)

    for instrument_id in columns:
        if instrument_id not in instruments.index:
            continue

        listed_at = instruments.loc[instrument_id, "listed_at"]
        delisted_at = instruments.loc[instrument_id, "delisted_at"]

        active = pd.Series(True, index=index)

        if pd.notna(listed_at):
            active &= index >= pd.Timestamp(listed_at)

        if pd.notna(delisted_at):
            active &= index <= pd.Timestamp(delisted_at)

        mask[instrument_id] = active

    return mask


def _universe_mask(
    parquet_root: Path, index: pd.DatetimeIndex, columns: list[str], universe: tuple[str, int]
) -> pd.DataFrame:
    members = pd.read_parquet(parquet_root / "meta" / "universe_members.parquet")
    members = members[
        (members["definition_name"] == universe[0]) & (members["definition_version"] == universe[1])
    ]
    members["date"] = pd.to_datetime(members["date"])
    members = members[members["instrument_id"].isin(columns)]

    if members.empty:
        return pd.DataFrame(False, index=index, columns=columns)

    members["member"] = True
    wide = members.pivot_table(
        index="date", columns="instrument_id", values="member", aggfunc="last"
    )

    return wide.reindex(index=index, columns=columns).fillna(False).astype(bool)
```

Do `research/forx/__init__.py` přidat `from forx.panel import BarPanel, load_panel` a doplnit obě jména do `__all__`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_panel.py -q'`
Expected: PASS, 7 testů

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: load_panel s listed_mask, universe_mask a kontrolou verze adjustmentu"
```

---

# Etapa 2c — indikátory

### Task 7: sma a ema

**Files:**
- Create: `research/forx/features/__init__.py`, `research/forx/features/moving.py`
- Test: `research/tests/test_moving.py`

**Interfaces:**
- Consumes: nic
- Produces:
  - `forx.features.moving.sma(frame: pd.DataFrame, window: int) -> pd.DataFrame`
  - `forx.features.moving.ema(frame: pd.DataFrame, window: int) -> pd.DataFrame`
  - `forx.features.REGISTRY: dict[str, FeatureFn]` — zatím `{"sma": ..., "ema": ...}`
  - `forx.features.FeatureFn` — `Callable[..., pd.DataFrame]`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_moving.py`:

```python
import pandas as pd
import pytest

from forx.features.moving import ema, sma


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_sma_golden() -> None:
    result = sma(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[0])
    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(2.0)
    assert result.iloc[3] == pytest.approx(3.0)


def test_ema_first_value_is_sma_of_first_window() -> None:
    result = ema(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(2.0)


def test_ema_recurrence_matches_formula() -> None:
    # alpha = 2/(n+1) = 0.5 pro n=3; EMA_3 = 0.5*4 + 0.5*2 = 3.0
    alpha = 2.0 / (3.0 + 1.0)
    expected = alpha * 4.0 + (1.0 - alpha) * 2.0

    result = ema(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert result.iloc[3] == pytest.approx(expected)


def test_sma_ignores_leading_nan_as_warmup() -> None:
    result = sma(_frame([float("nan"), 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[2])
    assert result.iloc[3] == pytest.approx(3.0)
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_moving.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.features'`

- [ ] **Step 3: Implementovat sma a ema**

`research/forx/features/moving.py`:

```python
"""Klouzavé průměry.

Definice jsou tady explicitní, protože „EMA" bez uvedení, čím se inicializuje,
není zadání. Golden testy mají vzorec rozepsaný ve svém těle, takže jsou zároveň
dokumentací konvence — kdyby ji někdo v budoucnu „opravil", testy spadnou.
"""

import numpy as np
import pandas as pd


def sma(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Aritmetický průměr posledních `window` hodnot včetně aktuální.

    První `window - 1` hodnot je warm-up a zůstává NaN.
    """
    return frame.rolling(window=window, min_periods=window).mean()


def ema(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """alpha = 2/(n+1); první hodnota je SMA prvních n hodnot, dál rekurence.

    pandas .ewm() startuje jinak (od první hodnoty), takže se rekurence počítá
    ručně — jinak by se konvence tiše rozešla se specifikací.
    """
    alpha = 2.0 / (window + 1.0)
    seed = frame.rolling(window=window, min_periods=window).mean()
    result = pd.DataFrame(np.nan, index=frame.index, columns=frame.columns, dtype="float64")

    for column in frame.columns:
        values = frame[column].to_numpy(dtype="float64")
        seeds = seed[column].to_numpy(dtype="float64")
        output = np.full(len(values), np.nan)
        previous = np.nan

        for position in range(len(values)):
            if np.isnan(previous):
                if not np.isnan(seeds[position]):
                    previous = seeds[position]
                    output[position] = previous

                continue

            if np.isnan(values[position]):
                output[position] = previous

                continue

            previous = alpha * values[position] + (1.0 - alpha) * previous
            output[position] = previous

        result[column] = output

    return result
```

`research/forx/features/__init__.py`:

```python
"""Registr featur.

Jméno v registru je totéž jméno, které nese FeatureRequest.name — tím je
zaručeno, že feature_id odkazuje na skutečně existující výpočet.
"""

from collections.abc import Callable

import pandas as pd

from forx.features.moving import ema, sma

FeatureFn = Callable[..., pd.DataFrame]

REGISTRY: dict[str, FeatureFn] = {
    "sma": sma,
    "ema": ema,
}

__all__ = ["REGISTRY", "FeatureFn", "ema", "sma"]
```

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_moving.py -q'`
Expected: PASS, 4 testy

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: indikátory sma a ema s golden testy"
```

---

### Task 8: atr a rsi s Wilderovým vyhlazováním

Nejcitlivější task celé etapy: rozdíl mezi Wilderovým a jednoduchým vyhlazováním je tichý a přepisuje historické backtesty pod rukama.

**Files:**
- Create: `research/forx/features/wilder.py`
- Modify: `research/forx/features/__init__.py`
- Test: `research/tests/test_wilder.py`

**Interfaces:**
- Consumes: nic
- Produces:
  - `forx.features.wilder.atr(high, low, close, window) -> pd.DataFrame`
  - `forx.features.wilder.rsi(close, window) -> pd.DataFrame`
  - registr rozšířen o `"atr"` a `"rsi"`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_wilder.py`:

```python
import pandas as pd
import pytest

from forx.features.wilder import atr, rsi


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_atr_golden_wilder_recurrence() -> None:
    high = _frame([10.0, 11.0, 12.0, 11.0])
    low = _frame([8.0, 10.0, 11.0, 9.0])
    close = _frame([9.0, 10.8, 11.5, 9.5])

    # TR_0 = H-L = 2.0 (bez předchozího close)
    # TR_1 = max(1.0, |11-9|=2.0, |10-9|=1.0)      = 2.0
    # TR_2 = max(1.0, |12-10.8|=1.2, |11-10.8|=0.2) = 1.2
    # TR_3 = max(2.0, |11-11.5|=0.5, |9-11.5|=2.5)  = 2.5
    # ATR_2 = (2.0 + 2.0 + 1.2) / 3                 = 1.7333333
    # ATR_3 = (1.7333333 * 2 + 2.5) / 3             = 1.9888889
    result = atr(high, low, close, window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(1.7333333, abs=1e-6)
    assert result.iloc[3] == pytest.approx(1.9888889, abs=1e-6)


def test_rsi_golden_wilder_recurrence() -> None:
    close = _frame([10.0, 11.0, 10.5, 12.0, 11.5])

    # gain/loss: +1.0/0, 0/0.5, +1.5/0, 0/0.5
    # avg_gain_3 = (1.0 + 0 + 1.5)/3 = 0.8333333
    # avg_loss_3 = (0 + 0.5 + 0)/3   = 0.1666667
    # RSI_3 = 100 - 100/(1 + 5.0) = 83.333333
    # avg_gain_4 = (0.8333333*2 + 0)/3   = 0.5555556
    # avg_loss_4 = (0.1666667*2 + 0.5)/3 = 0.2777778
    # RSI_4 = 100 - 100/(1 + 2.0) = 66.666667
    result = rsi(close, window=3)["A"]

    assert pd.isna(result.iloc[2])
    assert result.iloc[3] == pytest.approx(83.333333, abs=1e-5)
    assert result.iloc[4] == pytest.approx(66.666667, abs=1e-5)


def test_rsi_monotonic_series_is_hundred() -> None:
    result = rsi(_frame([1.0, 2.0, 3.0, 4.0, 5.0]), window=3)["A"]

    assert result.iloc[3] == pytest.approx(100.0)
    assert result.iloc[4] == pytest.approx(100.0)


def test_rsi_zero_loss_does_not_divide_by_zero() -> None:
    result = rsi(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert not pd.isna(result.iloc[3])
    assert result.iloc[3] == pytest.approx(100.0)
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_wilder.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.features.wilder'`

- [ ] **Step 3: Implementovat atr a rsi**

`research/forx/features/wilder.py`:

```python
"""Indikátory s Wilderovým vyhlazováním.

Wilderovo vyhlazování NENÍ jednoduchý klouzavý průměr a NENÍ ani standardní EMA.
Rozdíl je tichý — hodnoty vypadají podobně — ale mění výsledky backtestů. TA
knihovny se v této konvenci liší, proto tenká vlastní implementace a golden testy
s rozepsanou rekurencí.
"""

import numpy as np
import pandas as pd


def _wilder_smooth(values: np.ndarray, window: int) -> np.ndarray:
    """ATR_n = mean(x_1..x_n); ATR_t = (ATR_{t-1} * (n-1) + x_t) / n."""
    output = np.full(len(values), np.nan)

    if len(values) < window:
        return output

    seed = np.mean(values[:window])
    output[window - 1] = seed
    previous = seed

    for position in range(window, len(values)):
        previous = (previous * (window - 1) + values[position]) / window
        output[position] = previous

    return output


def atr(high: pd.DataFrame, low: pd.DataFrame, close: pd.DataFrame, window: int) -> pd.DataFrame:
    """TR_t = max(H_t - L_t, |H_t - C_{t-1}|, |L_t - C_{t-1}|), pak Wilder."""
    result = pd.DataFrame(np.nan, index=close.index, columns=close.columns, dtype="float64")

    for column in close.columns:
        high_values = high[column].to_numpy(dtype="float64")
        low_values = low[column].to_numpy(dtype="float64")
        close_values = close[column].to_numpy(dtype="float64")

        previous_close = np.concatenate(([np.nan], close_values[:-1]))
        true_range = np.nanmax(
            np.vstack(
                [
                    high_values - low_values,
                    np.abs(high_values - previous_close),
                    np.abs(low_values - previous_close),
                ]
            ),
            axis=0,
        )

        result[column] = _wilder_smooth(true_range, window)

    return result


def rsi(close: pd.DataFrame, window: int) -> pd.DataFrame:
    """Wilderovo vyhlazování zisků a ztrát. Při nulové průměrné ztrátě je RSI 100."""
    result = pd.DataFrame(np.nan, index=close.index, columns=close.columns, dtype="float64")

    for column in close.columns:
        values = close[column].to_numpy(dtype="float64")
        change = np.diff(values, prepend=np.nan)
        gains = np.where(change > 0, change, 0.0)
        losses = np.where(change < 0, -change, 0.0)

        # První prvek nemá předchozí hodnotu, takže do vyhlazování nevstupuje.
        average_gain = _wilder_smooth(gains[1:], window)
        average_loss = _wilder_smooth(losses[1:], window)

        with np.errstate(divide="ignore", invalid="ignore"):
            strength = np.where(average_loss == 0.0, np.inf, average_gain / average_loss)
            column_values = np.where(
                np.isnan(average_gain), np.nan, 100.0 - 100.0 / (1.0 + strength)
            )

        result[column] = np.concatenate(([np.nan], column_values))

    return result
```

Do `research/forx/features/__init__.py` přidat import `from forx.features.wilder import atr, rsi` a do `REGISTRY` položky `"atr": atr, "rsi": rsi`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_wilder.py -q'`
Expected: PASS, 4 testy

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: atr a rsi s Wilderovým vyhlazováním a golden testy"
```

---

### Task 9: rolling_high, rolling_low a dollar_volume_ma

**Files:**
- Create: `research/forx/features/window.py`
- Modify: `research/forx/features/__init__.py`
- Test: `research/tests/test_window.py`

**Interfaces:**
- Consumes: nic
- Produces:
  - `forx.features.window.rolling_high(frame, window) -> pd.DataFrame`
  - `forx.features.window.rolling_low(frame, window) -> pd.DataFrame`
  - `forx.features.window.dollar_volume_ma(frame, window) -> pd.DataFrame`
  - registr rozšířen o `"rolling_high"`, `"rolling_low"`, `"dollar_volume_ma"`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_window.py`:

```python
import pandas as pd
import pytest

from forx.features.window import dollar_volume_ma, rolling_high, rolling_low


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_rolling_high_includes_current_value() -> None:
    result = rolling_high(_frame([1.0, 5.0, 3.0, 2.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(5.0)
    assert result.iloc[3] == pytest.approx(5.0)


def test_rolling_low_includes_current_value() -> None:
    result = rolling_low(_frame([4.0, 5.0, 3.0, 6.0]), window=3)["A"]

    assert result.iloc[2] == pytest.approx(3.0)
    assert result.iloc[3] == pytest.approx(3.0)


def test_dollar_volume_ma_averages_input() -> None:
    result = dollar_volume_ma(_frame([100.0, 200.0, 300.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(200.0)
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_window.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.features.window'`

- [ ] **Step 3: Implementovat okenní indikátory**

`research/forx/features/window.py`:

```python
"""Okenní indikátory nad jednou maticí."""

import pandas as pd


def rolling_high(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Maximum posledních `window` hodnot včetně aktuální."""
    return frame.rolling(window=window, min_periods=window).max()


def rolling_low(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Minimum posledních `window` hodnot včetně aktuální."""
    return frame.rolling(window=window, min_periods=window).min()


def dollar_volume_ma(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Klouzavý průměr dollar volume.

    Vstupem MUSÍ být surové close × volume, ne upravené. Likvidita je vlastnost
    skutečně zobchodovaného objemu v tehdejších cenách; adjustovaný objem by ji
    zkreslil. Vstup vybírá FeatureRequest.input="dollar_volume".
    """
    return frame.rolling(window=window, min_periods=window).mean()
```

Do `research/forx/features/__init__.py` přidat import a tři položky do `REGISTRY`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_window.py -q'`
Expected: PASS, 3 testy

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: rolling_high, rolling_low a dollar_volume_ma"
```

---

### Task 10: relative_strength proti benchmarku

**Files:**
- Create: `research/forx/features/relative.py`
- Modify: `research/forx/features/__init__.py`
- Test: `research/tests/test_relative.py`

**Interfaces:**
- Consumes: nic
- Produces:
  - `forx.features.relative.relative_strength(frame, window, benchmark_id) -> pd.DataFrame`
  - registr rozšířen o `"relative_strength"`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_relative.py`:

```python
import pandas as pd
import pytest

from forx.features.relative import relative_strength


def _panel() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "A": [100.0, 110.0, 121.0],
            "BENCH": [100.0, 100.0, 100.0],
        },
        index=pd.RangeIndex(3),
    )


def test_relative_strength_above_one_when_outperforming() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    # (121/100) / (100/100) = 1.21
    assert result["A"].iloc[2] == pytest.approx(1.21)


def test_relative_strength_is_one_for_benchmark_itself() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    assert result["BENCH"].iloc[2] == pytest.approx(1.0)


def test_relative_strength_warmup_is_nan() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    assert pd.isna(result["A"].iloc[0])
    assert pd.isna(result["A"].iloc[1])


def test_relative_strength_missing_benchmark_raises() -> None:
    with pytest.raises(KeyError):
        relative_strength(_panel(), window=2, benchmark_id="NENI")
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_relative.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.features.relative'`

- [ ] **Step 3: Implementovat relative_strength**

`research/forx/features/relative.py`:

```python
"""Relativní síla proti benchmarku."""

import pandas as pd


def relative_strength(frame: pd.DataFrame, window: int, benchmark_id: str) -> pd.DataFrame:
    """(C_t / C_{t-n}) / (B_t / B_{t-n}); hodnota > 1 znamená překonání benchmarku.

    Benchmark musí být sloupcem panelu — panel se proto staví vždy včetně něj,
    i když není členem univerza.
    """
    if benchmark_id not in frame.columns:
        raise KeyError(f"Benchmark {benchmark_id} není v panelu.")

    instrument_return = frame / frame.shift(window)
    benchmark_return = frame[benchmark_id] / frame[benchmark_id].shift(window)

    return instrument_return.div(benchmark_return, axis=0)
```

Do `research/forx/features/__init__.py` přidat import a položku `"relative_strength": relative_strength`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_relative.py -q'`
Expected: PASS, 4 testy

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: relative_strength proti benchmarku"
```

---

### Task 11: cs_rank — cross-sectional percentilový rank

**Files:**
- Create: `research/forx/features/cross_section.py`
- Modify: `research/forx/features/__init__.py`
- Test: `research/tests/test_cross_section.py`

**Interfaces:**
- Consumes: `universe_mask` z `BarPanel` (Task 6)
- Produces:
  - `forx.features.cross_section.cs_rank(frame, universe_mask) -> pd.DataFrame`
  - registr rozšířen o `"cs_rank"`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_cross_section.py`:

```python
import pandas as pd
import pytest

from forx.features.cross_section import cs_rank


def _frame() -> pd.DataFrame:
    return pd.DataFrame(
        {"A": [1.0], "B": [3.0], "C": [3.0], "D": [float("nan")]},
        index=pd.RangeIndex(1),
    )


def _all_members() -> pd.DataFrame:
    return pd.DataFrame({"A": [True], "B": [True], "C": [True], "D": [True]}, index=pd.RangeIndex(1))


def test_cs_rank_is_percentile() -> None:
    result = cs_rank(_frame(), _all_members())

    # tři platné hodnoty: 1.0 → rank 1, 3.0 a 3.0 → průměrný rank 2.5
    assert result["A"].iloc[0] == pytest.approx(1.0 / 3.0)


def test_cs_rank_ties_get_average_rank() -> None:
    result = cs_rank(_frame(), _all_members())

    assert result["B"].iloc[0] == pytest.approx(2.5 / 3.0)
    assert result["C"].iloc[0] == pytest.approx(2.5 / 3.0)


def test_cs_rank_nan_stays_nan_and_is_not_ranked() -> None:
    result = cs_rank(_frame(), _all_members())

    assert pd.isna(result["D"].iloc[0])


def test_cs_rank_excludes_non_members() -> None:
    mask = pd.DataFrame(
        {"A": [True], "B": [True], "C": [False], "D": [True]}, index=pd.RangeIndex(1)
    )

    result = cs_rank(_frame(), mask)

    # C mimo univerzum → rankují se jen A a B, takže A je 1/2 a C je NaN
    assert pd.isna(result["C"].iloc[0])
    assert result["A"].iloc[0] == pytest.approx(0.5)


def test_cs_rank_is_independent_of_universe_size() -> None:
    small = pd.DataFrame({"A": [1.0], "B": [2.0]}, index=pd.RangeIndex(1))
    small_mask = pd.DataFrame({"A": [True], "B": [True]}, index=pd.RangeIndex(1))
    large = pd.DataFrame({"A": [1.0], "B": [2.0], "C": [3.0], "D": [4.0]}, index=pd.RangeIndex(1))
    large_mask = pd.DataFrame(
        {"A": [True], "B": [True], "C": [True], "D": [True]}, index=pd.RangeIndex(1)
    )

    smallest_in_small = cs_rank(small, small_mask)["A"].iloc[0]
    smallest_in_large = cs_rank(large, large_mask)["A"].iloc[0]

    # nejmenší hodnota má v obou případech percentil 1/n, ne absolutní pozici 1
    assert smallest_in_small == pytest.approx(0.5)
    assert smallest_in_large == pytest.approx(0.25)
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_cross_section.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.features.cross_section'`

- [ ] **Step 3: Implementovat cs_rank**

`research/forx/features/cross_section.py`:

```python
"""Cross-sectional operace — počítají se po řádcích, tedy napříč instrumenty k datu."""

import pandas as pd


def cs_rank(frame: pd.DataFrame, universe_mask: pd.DataFrame) -> pd.DataFrame:
    """Percentilový rank napříč členy univerza k danému dni.

    Tři věci, které musí být explicitní:
      - NaN se z rankování vylučují a zůstávají NaN; nedostávají rank 0.
      - Shodné hodnoty dostávají průměrný rank.
      - Percentil, ne absolutní pozice — jinak by výsledek závisel na velikosti
        univerza k danému dni, která se v čase mění.

    Rankuje se jen nad univerzem k datu D, ne nad dnešním univerzem. To je jedna
    z podmínek kauzality ze specifikace.
    """
    eligible = frame.where(universe_mask.reindex_like(frame).fillna(False))

    return eligible.rank(axis=1, method="average", pct=True, na_option="keep")
```

Do `research/forx/features/__init__.py` přidat import a položku `"cs_rank": cs_rank`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_cross_section.py -q'`
Expected: PASS, 5 testů

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: cs_rank s percentilovým rankem nad univerzem k datu"
```

---

# Etapa 2d — kompozice a záruky

### Task 12: FeatureSet a compute() s líným vyhodnocením

**Files:**
- Create: `research/forx/compute.py`
- Modify: `research/forx/__init__.py`
- Test: `research/tests/test_compute.py`

**Interfaces:**
- Consumes: `BarPanel` (Task 6), `FeatureRequest` (Task 5), `REGISTRY` (Tasky 7–11), `InsufficientHistory`, `UnknownFeature` (Task 4)
- Produces:
  - `forx.compute.FeatureSet` — `get(feature_id) -> pd.DataFrame`, `feature_ids() -> Sequence[str]`
  - `forx.compute.compute(panel, requests) -> FeatureSet`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_compute.py`:

```python
from pathlib import Path

import pytest

from forx.compute import compute
from forx.errors import InsufficientHistory, UnknownFeature
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot


def _panel(tmp_path: Path):
    spec = write_snapshot(tmp_path)

    return spec, load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_compute_returns_requested_feature(tmp_path: Path) -> None:
    spec, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])

    frame = features.get("sma(input=adj_close,window=20)")
    assert frame.shape == panel.adj_close.shape


def test_compute_lists_feature_ids(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(
        panel,
        [
            FeatureRequest(name="sma", params={"window": 20}),
            FeatureRequest(name="rsi", params={"window": 14}),
        ],
    )

    assert set(features.feature_ids()) == {
        "sma(input=adj_close,window=20)",
        "rsi(input=adj_close,window=14)",
    }


def test_compute_is_lazy(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])

    assert features.computed_count() == 0
    features.get("sma(input=adj_close,window=20)")
    assert features.computed_count() == 1


def test_compute_caches_result(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])
    first = features.get("sma(input=adj_close,window=20)")
    second = features.get("sma(input=adj_close,window=20)")

    assert first is second


def test_compute_unknown_feature_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    with pytest.raises(UnknownFeature):
        compute(panel, [FeatureRequest(name="neexistuje", params={})])


def test_compute_cs_rank_without_source_request_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    with pytest.raises(UnknownFeature):
        compute(panel, [FeatureRequest(name="cs_rank", params={"source": "sma(input=adj_close,window=99)"})])


def test_compute_warmup_longer_than_history_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5000})])

    with pytest.raises(InsufficientHistory):
        features.get("sma(input=adj_close,window=5000)")
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_compute.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.compute'`

- [ ] **Step 3: Implementovat FeatureSet a compute**

`research/forx/compute.py`:

```python
"""Skládání featur nad panelem.

FeatureSet drží featury líně. Sweep může chtít desítky featur současně a při
75 MB na featuru je 30 featur 2,2 GB — počítat všechny dopředu by byl zbytečný
strop, na který se dá narazit.
"""

from collections.abc import Sequence

import pandas as pd

from forx.errors import InsufficientHistory, UnknownFeature
from forx.features import REGISTRY
from forx.panel import BarPanel
from forx.request import FeatureRequest

_MULTI_INPUT_FEATURES = frozenset({"atr"})
_CROSS_SECTIONAL_FEATURES = frozenset({"cs_rank"})
_BENCHMARK_FEATURES = frozenset({"relative_strength"})


class FeatureSet:
    """Líný kontejner spočítaných featur."""

    def __init__(self, panel: BarPanel, requests: Sequence[FeatureRequest]) -> None:
        self._panel = panel
        self._requests = {request.feature_id: request for request in requests}
        self._cache: dict[str, pd.DataFrame] = {}

    def feature_ids(self) -> Sequence[str]:
        return tuple(self._requests)

    def computed_count(self) -> int:
        return len(self._cache)

    def get(self, feature_id: str) -> pd.DataFrame:
        if feature_id in self._cache:
            return self._cache[feature_id]

        request = self._requests[feature_id]
        self._verify_history(request)
        self._cache[feature_id] = self._evaluate(request)

        return self._cache[feature_id]

    def _verify_history(self, request: FeatureRequest) -> None:
        window = request.params.get("window")

        if not isinstance(window, int):
            return

        available = len(self._panel.adj_close.index)

        if window > available:
            raise InsufficientHistory(request.feature_id, window, available)

    def _evaluate(self, request: FeatureRequest) -> pd.DataFrame:
        function = REGISTRY[request.name]

        if request.name in _MULTI_INPUT_FEATURES:
            return function(
                self._panel.adj_high,
                self._panel.adj_low,
                self._panel.adj_close,
                **request.params,
            )

        if request.name in _CROSS_SECTIONAL_FEATURES:
            source_id = str(request.params["source"])

            return function(self.get(source_id), self._panel.universe_mask)

        if request.name in _BENCHMARK_FEATURES:
            params = dict(request.params)
            benchmark_id = str(params.pop("benchmark"))

            return function(self._panel.frame(request.input), benchmark_id=benchmark_id, **params)

        return function(self._panel.frame(request.input), **request.params)


def compute(panel: BarPanel, requests: Sequence[FeatureRequest]) -> FeatureSet:
    """Ověří, že všechny požadované featury existují, a vrátí líný FeatureSet.

    Neznámá featura selže hned při skládání, ne až při čtení — psát překlep
    v názvu a zjistit to za hodinu uprostřed sweepu je zbytečná ztráta.

    Totéž platí pro zdroj cross-sectional featury: cs_rank se odkazuje na jinou
    featuru přes její feature_id, a ta musí být mezi požadavky.
    """
    for request in requests:
        if request.name not in REGISTRY:
            raise UnknownFeature(request.name)

    known = {request.feature_id for request in requests}

    for request in requests:
        source = request.params.get("source")

        if source is not None and str(source) not in known:
            raise UnknownFeature(str(source))

    return FeatureSet(panel, requests)
```

Do `research/forx/__init__.py` přidat `from forx.compute import FeatureSet, compute` a doplnit obě jména do `__all__`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_compute.py -q'`
Expected: PASS, 7 testů

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: FeatureSet a compute s líným vyhodnocením a warm-up kontraktem"
```

---

### Task 13: Tři druhy chybějící hodnoty

Kritérium hotovosti celého podprojektu. Kdyby se tři důvody slily do jednoho `NaN`, statistika „strategie obchodovala 40× za rok" by byla nedůvěryhodná.

**Files:**
- Create: `research/forx/missing.py`
- Modify: `research/forx/__init__.py`
- Test: `research/tests/test_missing.py`

**Interfaces:**
- Consumes: `BarPanel` (Task 6), `FeatureSet` (Task 12)
- Produces:
  - `forx.missing.MissingReason` — `StrEnum` s členy `PRESENT`, `NOT_LISTED`, `WARMUP`, `DATA_GAP`
  - `forx.missing.missing_reasons(panel, values) -> pd.DataFrame` — matice stejného tvaru s hodnotami `MissingReason`

- [ ] **Step 1: Napsat failující test**

`research/tests/test_missing.py`:

```python
from pathlib import Path

from forx.compute import compute
from forx.missing import MissingReason, missing_reasons
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot


def test_missing_reasons_distinguishes_all_three(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    # 1. nelistovaný: latecomer první den
    assert reasons.loc[str(spec.dates[0]), spec.latecomer_id] == MissingReason.NOT_LISTED
    # 2. warm-up: plný instrument první den, kdy už je listovaný ale nemá 5 hodnot
    assert reasons.loc[str(spec.dates[0]), spec.instrument_ids[0]] == MissingReason.WARMUP
    # 3. mezera v datech: den, kdy bar chybí, ale instrument existoval
    assert reasons.loc[str(spec.gap_dates[0]), spec.gap_id] == MissingReason.DATA_GAP


def test_missing_reasons_marks_present_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    assert reasons.loc[str(spec.dates[100]), spec.benchmark_id] == MissingReason.PRESENT


def test_missing_reasons_after_delisting_is_not_listed(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    assert reasons.iloc[-1][spec.delisted_id] == MissingReason.NOT_LISTED


def test_missing_reasons_shape_matches_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    assert missing_reasons(panel, values).shape == values.shape
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_missing.py -q'`
Expected: FAIL — `ModuleNotFoundError: No module named 'forx.missing'`

- [ ] **Step 3: Implementovat sémantiku chybějících hodnot**

`research/forx/missing.py`:

```python
"""Proč je hodnota chybějící.

Tři důvody se nesmí slít do jednoho NaN. Kdyby se slily, strategie by hlásila
menší počet příležitostí, než reálně byl, a nikdo by nepoznal, jestli za tím je
nedostatek dat nebo skutečná absence signálu.

Mezera v datech se odvozuje soběstačně — instrument je listovaný, den je podle
kalendáře obchodní a bar chybí. Specifikace zmiňovala potvrzení nálezem
MissingBarOnTradingDay z podprojektu 1, ale odvození nezávislé na historii
ingestu je robustnější.
"""

from enum import StrEnum

import numpy as np
import pandas as pd

from forx.panel import BarPanel


class MissingReason(StrEnum):
    PRESENT = "present"
    NOT_LISTED = "not_listed"
    WARMUP = "warmup"
    DATA_GAP = "data_gap"


def missing_reasons(panel: BarPanel, values: pd.DataFrame) -> pd.DataFrame:
    """Matice stejného tvaru jako `values`, kde každá buňka nese svůj důvod."""
    listed = panel.listed_mask.reindex_like(values).fillna(False).to_numpy(dtype=bool)
    has_bar = panel.adj_close.reindex_like(values).notna().to_numpy(dtype=bool)
    has_value = values.notna().to_numpy(dtype=bool)

    reasons = np.full(values.shape, MissingReason.WARMUP.value, dtype=object)
    reasons[~listed] = MissingReason.NOT_LISTED.value
    reasons[listed & ~has_bar] = MissingReason.DATA_GAP.value
    reasons[has_value] = MissingReason.PRESENT.value

    return pd.DataFrame(reasons, index=values.index, columns=values.columns)
```

Do `research/forx/__init__.py` přidat `from forx.missing import MissingReason, missing_reasons` a doplnit obě jména do `__all__`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_missing.py -q'`
Expected: PASS, 4 testy

- [ ] **Step 5: Lint, typy, commit**

```bash
docker compose exec research sh -c 'cd /app/research && ruff check . && mypy'
git add research
git commit -m "feat: rozlišení tří druhů chybějící hodnoty"
```

---

### Task 14: Kauzalita napříč všemi indikátory

Poslední task. Ověřuje vlastnost, kterou žádné čtení kódu nezjistí spolehlivěji než tenhle test.

**Files:**
- Test: `research/tests/test_causality.py`
- Modify: `docs/superpowers/STATUS.md`

**Interfaces:**
- Consumes: všechno z Tasků 4–13
- Produces: nic nového, jen záruku

- [ ] **Step 1: Napsat test kauzality**

`research/tests/test_causality.py`:

```python
"""Featura spočítaná nad zkrácenými daty (≤ D) se musí rovnat featuře spočítané
nad plnými daty a odečtené k datu D. Když se to nerovná, implementace se dívá
dopředu — a žádné čtení kódu to nezjistí spolehlivěji.

Je to stejný trik jako test point-in-time univerza v podprojektu 1.
"""

from pathlib import Path

import pandas as pd
import pytest

from forx.compute import compute
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot

REQUESTS = [
    FeatureRequest(name="sma", params={"window": 20}),
    FeatureRequest(name="ema", params={"window": 20}),
    FeatureRequest(name="atr", params={"window": 14}),
    FeatureRequest(name="rsi", params={"window": 14}),
    FeatureRequest(name="rolling_high", params={"window": 20}),
    FeatureRequest(name="rolling_low", params={"window": 20}),
    FeatureRequest(name="dollar_volume_ma", params={"window": 20}, input="dollar_volume"),
]


@pytest.mark.parametrize("request_spec", REQUESTS, ids=lambda r: r.name)
def test_feature_is_causal(tmp_path: Path, request_spec: FeatureRequest) -> None:
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[180]

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = (
        compute(truncated_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]
    )

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False)


def test_relative_strength_is_causal(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[180]
    request_spec = FeatureRequest(
        name="relative_strength", params={"window": 20, "benchmark": spec.benchmark_id}
    )

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = (
        compute(truncated_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]
    )

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False)


def test_cs_rank_is_causal(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[180]
    requests = [
        FeatureRequest(name="sma", params={"window": 20}),
        FeatureRequest(name="cs_rank", params={"source": "sma(input=adj_close,window=20)"}),
    ]
    feature_id = "cs_rank(input=adj_close,source=sma(input=adj_close,window=20))"

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, requests).get(feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = compute(truncated_panel, requests).get(feature_id).loc[str(cutoff)]

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False)
```

- [ ] **Step 2: Spustit test**

Run: `docker compose exec research sh -c 'cd /app/research && python -m pytest tests/test_causality.py -q'`
Expected: PASS, 9 testů (7 parametrizovaných + 2 samostatné)

Pokud některý spadne, **není to chyba testu** — je to nález. Indikátor, který spadne, se dívá dopředu a musí se opravit, ne test uvolnit.

- [ ] **Step 3: Spustit celou sadu obou stran**

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/phpcs
docker compose exec research sh -c 'cd /app/research && python -m pytest -q && ruff check . && mypy'
```

Expected: všechno zelené.

- [ ] **Step 4: Aktualizovat STATUS.md**

V sekci „Stav k dnešnímu dni" nahradit odstavec o podprojektu 1 tak, aby uváděl i podprojekt 2: indikátorová vrstva v Pythonu, balíček `forx`, sada indikátorů, tři druhy chybějící hodnoty a kauzalitní testy. V sekci „Jak pokračovat" povýšit psaní plánu podprojektu 3 (definice strategie) na první možnost.

- [ ] **Step 5: Commit**

```bash
git add research docs
git commit -m "test: kauzalita napříč všemi indikátory"
```

---

## Souhrn kritických invariantů

Věci, které při implementaci nejde odvodit z kódu a musí zůstat pravdivé. Každá má v plánu vlastní test.

1. **Featura nad zkrácenými daty se rovná featuře nad plnými daty odečtené k témuž datu.** Task 14, pro každý indikátor zvlášť.
2. **Tři druhy chybějící hodnoty jsou odlišitelné.** Nelistovaný, warm-up a mezera v datech se nesmí slít. Task 13.
3. **RSI a ATR používají Wilderovo vyhlazování, ne jednoduchý průměr.** Golden testy mají rekurenci rozepsanou ve svém těle, takže jsou dokumentací konvence. Task 8.
4. **EMA se inicializuje SMA prvních n hodnot,** ne první hodnotou řady jako `pandas.ewm()`. Task 7.
5. **`cs_rank` rankuje jen nad členy univerza k danému dni,** ne nad dnešním univerzem. Task 11.
6. **`cs_rank` vrací percentil, ne absolutní pozici** — jinak by výsledek závisel na velikosti univerza, která se v čase mění. Task 11.
7. **`NaN` se z rankování vylučuje a zůstává `NaN`;** nedostává rank 0. Task 11.
8. **`dollar_volume_ma` počítá nad surovými cenami a objemy,** ne upravenými. Likvidita je vlastnost skutečně zobchodovaného objemu. Task 9.
9. **Warm-up delší než dostupná historie selže hlasitě,** ne tichým `NaN`. Task 12.
10. **Nesoulad verze adjustmentu je chyba, ne varování.** Task 6.
11. **Manifest se zapisuje jako poslední krok exportu.** Rozbitý export nechá snapshot bez manifestu a Python ho odmítne, místo aby počítal nad polovinou dat. Task 3.
12. **Panel se staví jen nad zadanými instrumenty,** nikdy nad celým katalogem — jinak paměť neunese ani jednu featuru. Task 6.

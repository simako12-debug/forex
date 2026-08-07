# Market Data Implementation Plan

> **Stav: dopsáno, 23 tasků, čeká na review.** Vzniklo po dokončení specifikací všech sedmi podprojektů, takže rozhodnutí patřící do podprojektu 3 (logika strategie jen v Pythonu) jsou už zohledněná.
>
> **Jedna část rozsahu specifikace není pokrytá: ingest intradenních 5min barů.** Tabulka `intraday_bars` a její partitions vznikají v Tasku 5, ale žádný task do nich nenalévá data. Důvod: intradenní historie pro 500 nejlikvidnějších tickerů je samostatný nákup a samostatný formát, a její adaptér nemá smysl psát dřív, než bude dump k dispozici a než bude denní cesta ověřená na reálných datech. Patří do navazujícího plánu. **Kritérium hotovosti ze specifikace tím splněné je** — je formulované nad denními bary — ale rozsah specifikace zmiňuje i intradenní data, takže je správné to říct nahlas, ne to nechat vypadat jako opomenutí.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Postavit datový sklad denních a 5min barů pro US akcie s point-in-time univerzem bez survivorship bias, do kterého vedou dvě validované ingest cesty (bulk dump a inkrementální API), a který exportuje Parquet snapshoty pro Python.

**Architecture:** Laravel aplikace v Dockeru, Postgres jako zdroj pravdy, Redis pro locky a rate limiting, `research` kontejner s Pythonem a DuckDB. Surové bary se nikdy nemění; upravené ceny se počítají z odvozených `adjustment_factors`. Ingest je jedna pipeline se dvěma vstupy — bulk přes `COPY FROM STDIN` do staging tabulky, inkrementální přes API adaptér — a oba procházejí stejnou validační vrstvou. Parquet export provádí DuckDB nad Postgresem, nikoliv PHP.

**Tech Stack:** PHP 8.5, Laravel 12+, PHPUnit (ne Pest), `spatie/laravel-data`, `ramsey/uuid`, `hamcrest/hamcrest-php`, Postgres 17, Python 3.13 + DuckDB + pyarrow (až od etapy 1b), PHPStan, phpcs.

**Běhové prostředí:** lokální PHP a lokální Postgres, **žádný Docker**. Kontejnerizace je odložená na poslední task plánu, kde je i důvod. Všechny příkazy v plánu se spouštějí z rootu projektu.

## Rozhodnutí učiněná při plánování

Dvě věci, které specifikace neřešila a plán je musel dořešit:

1. **Parquet nezapisuje PHP.** V PHP neexistuje zralý zapisovač Parquetu. Export je jediný DuckDB příkaz, který přes `postgres` extension čte Postgres a zapisuje Parquet. Adjustment matematika zůstává v PHP (produkuje `adjustment_factors`), DuckDB jen aplikuje násobení — záměr specifikace „Python neimplementuje adjustment logiku" tím zůstává splněn.
2. **`adjustment_factors` obsahuje jen řádky, kde se koeficient liší od 1.** Materializovat koeficient pro každý (instrument, den) by znamenalo druhou stomilionovou tabulku. Čtení používá `LEFT JOIN` s `COALESCE(..., 1)`.

## Global Constraints

Platí pro každý task, i když to task nezmiňuje.

- `declare(strict_types=1);` na začátku každého PHP souboru.
- Type-hint všech parametrů i návratových typů. `readonly` na property, kde to jde.
- Konstanty ve třídách typované: `private const int FOO = 1;`, `protected const array IDS = [...];`.
- Maximálně **120 znaků** na řádek. Ověřeno `vendor/bin/phpcs`.
- Porovnání vždy `=== false`, `=== true`, `=== null` — nikdy `!`, `!!` ani loose check.
- Prázdnost pole: `empty($array) === false` / `empty($array) === true`, nikdy `!== []`.
- Instanciace s voláním metody: `new Class()->method()`, nikoliv `(new Class())->method()`.
- **Žádné vnořené `if`** — kombinovat podmínky `&&`, invertovat s early return, nebo vytáhnout do privátní metody. Bez výjimek.
- Null-union type order: `null|string`, nikdy `string|null` — včetně PHPDoc.
- PHPDoc: property a class-level `@var` jednořádkově `/** @var Type */`; inline anotace lokální proměnné s názvem `/** @var Type $var */`; `@return`, `@param`, `@throws`, `@template` blokově.
- UUID primární klíče s `HasUuids` na business entitách, `getKey()` vrací `$this->id->toString()`. **Výjimka pro časové řady** (`daily_bars`, `intraday_bars`, `adjustment_factors`, `universe_members`): složený přirozený klíč, žádné UUID.
- Migrace: vždy `->index()` vedle `->foreign()` — Postgres index automaticky nevytváří.
- Každá nová třída má `{ClassName}Test` v zrcadleném adresáři s `#[CoversClass()]`.
- Podtypy testů jsou krátká substantiva scénáře (`testCheckEmptyFile`), nikdy verbální prefixy (`Returns`, `Throws`) ani `When X`. Výjimky: `test<Metoda><ExceptionClass>Throw`.
- Žádné AAA komentáře (`// Arrange`, `// Act`, `// Assert`) v testech.
- Deterministická UUID přes `Uuid::fromString('...')` přímo v testovací metodě. Nikdy `Uuid::uuid4()`, nikdy konstanty UUID ve třídě testu.
- UUID do spy expectations jako **string**, ne `UuidInterface`.
- `->with()` s přímými hodnotami nebo matchery; **nikdy `->withArgs(closure)`**.
- Logger jen přes `$this->spyLogger()`, nikdy mock `LoggerInterface`.
- Třídy, které se mají spyovat, nesmí být `final`; `readonly` se přesune na jednotlivé constructor property.
- **Žádná síť v testech.** API adaptéry proti `Http::fake()`.
- **Žádný test nesmí záviset na stáhnutém dumpu.**
- Chyby v datech jsou očekávaná data → řádek v `validation_findings`. Exception jen pro chybu programátora a selhání infrastruktury.
- Intradenní timestampy vždy UTC `timestamptz`. Denní bary jako čistý `date` v pojmu burzy.
- Redis nese jen koordinaci (fronty, locky, progress, rate limity). Nikdy data, o která by byla škoda. V tomto plánu se Redis nepoužívá — locky a queue jedou nad `database` storem; pravidlo platí pro podprojekt 4 a dál.
- Výstupní soubory analýz do `.ai/`, po použití smazat, nikdy necommitovat.
- Po každé změně testů spustit PHPStan a phpcs.

## File Structure

Kontejnerizace (`.docker/`, `docker-compose.yml`) je odložená na poslední task plánu — viz odůvodnění tam.

```
phpstan.neon                        level max, bootstrap, ignore vendor
phpcs.xml                           PSR-12 + line-length 120 + vlastní sniffy
phpunit.xml                         testsuites Unit/Feature, in-memory config

app/Shared/                         napříč moduly použitelné utility
app/MarketData/
  Contracts/
    BarSourcePort.php               fetchDailyBars(), fetchIntradayBars() → Generator<BarData>
    CorporateActionSourcePort.php   fetchCorporateActions() → Generator<CorporateActionData>
    ValidationRule.php              name(), severity(), findings(int $stagingRunId) → Generator
  Data/
    BarData.php                     normalizovaný bar (symbol, date|ts, ohlcv)
    CorporateActionData.php         normalizovaná corporate action
    UniverseRulesData.php           min_price, min_avg_dollar_volume, lookback_days
  Models/
    Instrument.php  InstrumentSymbol.php  MarketDay.php
    DailyBar.php  IntradayBar.php  CorporateAction.php
    AdjustmentFactor.php  UniverseDefinition.php  UniverseMember.php
    IngestRun.php  ValidationFinding.php
  Symbols/
    SymbolResolver.php              symbol + date → null|Instrument
  Calendar/
    AlpacaCalendarSource.php        Alpaca /v2/calendar → Generator<MarketDayData>
    CalendarImporter.php
  Ingest/
    Bulk/
      GenericOhlcvCsvSource.php     streaming CSV → Generator<BarData>
      BulkFileRegistry.php          hash obsahu → idempotence
    Incremental/
      AlpacaBarSource.php           Alpaca /v2/stocks/bars → Generator<BarData>
      ProviderRateLimiter.php       sliding window nad Redis
    StagingTable.php                create/drop unlogged staging, COPY FROM STDIN
    IngestPipeline.php              orchestrace kroků 1-7
    Quarantine.php                  odložení řádků instrumentu se severity error
  Validation/
    Rules/OhlcConsistencyRule.php  DuplicateBarRule.php  BarOnClosedDayRule.php
    Rules/MissingBarOnTradingDayRule.php  PriceJumpWithoutCorporateActionRule.php
    Rules/ZeroOrMissingVolumeRule.php  StaleSeriesRule.php  CrossSourceDivergenceRule.php
    ValidationRunner.php            spustí všechna pravidla, zapíše findings
  Adjustment/
    AdjustmentFactorCalculator.php  celý přepočet pro jeden instrument
  Universe/
    UniverseMemberResolver.php      point-in-time členství
  Export/
    DuckDbParquetExporter.php       staví a spouští DuckDB COPY TO
  Console/
    ImportBulkBarsCommand.php  ImportIncrementalBarsCommand.php
    ImportCalendarCommand.php  RecalculateAdjustmentsCommand.php
    RebuildUniverseCommand.php  ExportParquetCommand.php
    DataHealthCommand.php  DataBenchmarkCommand.php
    EnsurePartitionsCommand.php

database/migrations/                jedna migrace per tabulka, partitioned přes DB::statement
database/factories/                 factory per model
database/seeders/CanonicalFixtureSeeder.php

tests/TestCase.php                  base: spyLogger()
tests/Helpers/Matchers/             EloquentMatcher, CollectionMatcher, DataMatcher
tests/Unit/MarketData/...           zrcadlí app/MarketData
tests/Feature/MarketData/...        ingest pipeline, commandy
research/pyproject.toml             duckdb, pyarrow, pandas, pytest (lokální venv, od etapy 1b)
research/tests/test_parquet_contract.py
```

---

# Etapa 1a — základ, katalog, ingest, validace

### Task 1: Projektový skeleton a enforcement konvencí

Bez tohohle tasku nejde spustit ani jeden test. Setup je proto součástí tasku, jehož deliverable ho potřebuje — a deliverable je „testy a statická analýza běží lokálně a jsou zelené".

**Zjištěný stav stroje** (ověřeno 2026-08-06): PHP 8.5.8, Composer přítomen, Postgres 17.10 běží na **portu 5433**, PHP rozšíření `intl` a `zip` přítomná, **`pdo_pgsql` chybí**, Redis není nainstalovaný.

**Files:**
- Create: `phpstan.neon`, `phpcs.xml`, `phpunit.xml`
- Create: `tests/Unit/SmokeTest.php`
- Modify: `.env`, `.env.example` (Postgres na portu 5433, `database` driver pro cache i queue)
- Modify: `.gitignore` (přidat `/storage/`, `/bootstrap/cache/`)

**Interfaces:**
- Consumes: nic
- Produces: běhové prostředí. Všechny další tasky spouštějí testy přes `php artisan test`, PHPStan přes `vendor/bin/phpstan analyse`, phpcs přes `vendor/bin/phpcs` — vždy z rootu projektu.

- [ ] **Step 1: Doinstalovat chybějící PHP rozšíření a založit databázi**

Tenhle krok vyžaduje `sudo`, takže ho spustí člověk, ne agent. V Claude Code stačí napsat `! <příkaz>`.

```bash
sudo apt-get install -y php8.5-pgsql
psql -p 5433 -c "CREATE ROLE forx LOGIN PASSWORD 'forx' CREATEDB"
psql -p 5433 -c "CREATE DATABASE forx OWNER forx"
psql -p 5433 -c "CREATE DATABASE forx_testing OWNER forx"
```

Kontrola: `php -m | grep pdo_pgsql` musí vypsat `pdo_pgsql`. Bez toho neprojde jediná migrace.

Redis se **záměrně neinstaluje.** Etapa 1a z něj potřebuje jen atomický lock proti dvojímu spuštění ingestu, a ten Laravel umí i nad `database` cache storem. Redis nastupuje až s Python sidecarem a job frontou (podprojekt 4), kde jeho role ze specifikace platí nezměněně.

- [ ] **Step 2: Vygenerovat Laravel skeleton bez Pestu**

```bash
composer create-project laravel/laravel tmp-skeleton --no-interaction
rsync -a --exclude '.git' tmp-skeleton/ ./ && rm -rf tmp-skeleton
```

`rsync` s vylučením `.git` zachová existující `docs/`, `CLAUDE.md`, `.claude/` a historii repa.

Laravel installer nabízí Pest — pokud se objeví interaktivní volba, zvolit **PHPUnit**. Guidelines předepisují `#[CoversClass]` atributy a `{ClassName}Test` konvenci, což je PHPUnit, ne Pest.

- [ ] **Step 3: Nastavit .env na lokální Postgres**

Do `.env` i `.env.example`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=forx
DB_USERNAME=forx
DB_PASSWORD=forx

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database

ALPACA_BASE_URL=https://api.alpaca.markets
ALPACA_DATA_URL=https://data.alpaca.markets
ALPACA_KEY_ID=
ALPACA_SECRET_KEY=
ALPACA_FEED=iex

MARKET_DATA_SHARED_PATH=./storage/shared
```

Port **5433**, ne výchozích 5432 — na tomhle stroji tam Postgres 17 skutečně běží.

V `phpunit.xml` nastavit testovací databázi:

```xml
<env name="DB_DATABASE" value="forx_testing"/>
<env name="CACHE_STORE" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
```

Testy jedou proti reálnému Postgresu, ne SQLite — plán používá partitioning, `COPY FROM STDIN` a Postgres-specifické SQL, takže SQLite by testovala něco jiného, než co běží v produkci.

- [ ] **Step 4: Nakonfigurovat PHPStan**

`phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - app
        - database
        - tests
    checkMissingIterableValueType: true
```

```bash
composer require --dev larastan/larastan phpstan/phpstan
```

- [ ] **Step 5: Nakonfigurovat phpcs s limitem 120 znaků**

`phpcs.xml`:

```xml
<?xml version="1.0"?>
<ruleset name="Forx">
    <file>app</file>
    <file>database</file>
    <file>tests</file>
    <exclude-pattern>*/vendor/*</exclude-pattern>

    <rule ref="PSR12"/>
    <rule ref="Generic.Files.LineLength">
        <properties>
            <property name="lineLimit" value="120"/>
            <property name="absoluteLineLimit" value="120"/>
        </properties>
    </rule>
    <rule ref="Generic.PHP.RequireStrictTypes"/>
</ruleset>
```

```bash
composer require --dev squizlabs/php_codesniffer
```

- [ ] **Step 6: Napsat smoke test**

`tests/Unit/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function testEnvironment(): void
    {
        $this->assertSame('testing', app()->environment());
    }
}
```

- [ ] **Step 7: Spustit vše a ověřit zelenou**

```bash
php artisan migrate
php artisan test
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

Expected: testy PASS, PHPStan bez chyb, phpcs bez chyb. Pokud PHPStan hlásí chyby ve vygenerovaném Laravel skeletonu, přidat je do `phpstan-baseline.neon` a includnout — baseline je pro cizí kód legitimní, pro vlastní nový kód ne.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: projektový skeleton a enforcement konvencí"
```

---

### Task 2: Testovací helpery podle guidelines

Guidelines vyžadují `EloquentMatcher`, `CollectionMatcher`, `DataMatcher` a `$this->spyLogger()`. V Sharry monorepu žijí v `Sharry\Base`, tady neexistují. Musí být hotové **před prvním testem, který je potřebuje**, jinak se konvence začne obcházet.

**Files:**
- Create: `tests/Helpers/Matchers/EloquentMatcher.php`, `CollectionMatcher.php`, `DataMatcher.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Unit/Helpers/Matchers/EloquentMatcherTest.php`, `CollectionMatcherTest.php`, `DataMatcherTest.php`

**Interfaces:**
- Consumes: běhové prostředí z Tasku 1
- Produces:
  - `new EloquentMatcher(Model $expected)` — Hamcrest matcher, porovnává třídu a primární klíč
  - `new CollectionMatcher(Enumerable $expected)` — porovnává počet a hodnoty po klíčích
  - `new DataMatcher(Data $expected)` — porovnává `toArray()` obou Data objektů
  - `TestCase::spyLogger(): TestHandler` — vrací Monolog `TestHandler` s metodami `hasWarningThatContains()`, `hasInfoThatContains()`, `hasDebugThatContains()`, `hasErrorThatContains()`

- [ ] **Step 1: Přidat závislosti**

```bash
composer require --dev hamcrest/hamcrest-php
composer require spatie/laravel-data ramsey/uuid
```

- [ ] **Step 2: Napsat failující test pro EloquentMatcher**

`tests/Unit/Helpers/Matchers/EloquentMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use App\MarketData\Models\Instrument;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Helpers\Matchers\EloquentMatcher;
use Tests\TestCase;

#[CoversClass(EloquentMatcher::class)]
final class EloquentMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $other = new Instrument();
        $other->id = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertTrue(new EloquentMatcher($model)->matches($other));
    }

    public function testMatchesDifferentKey(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $other = new Instrument();
        $other->id = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

        $this->assertFalse(new EloquentMatcher($model)->matches($other));
    }

    public function testMatchesNonModel(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertFalse(new EloquentMatcher($model)->matches('not a model'));
    }
}
```

Tento test závisí na modelu `Instrument` z Tasku 3. Pro zachování TDD cyklu vytvoř v tomto tasku **jen prázdnou třídu modelu** (`class Instrument extends Model {}` s `protected $keyType = 'string';`) — migrace a plná definice přijdou v Tasku 3. Test nesahá na databázi.

- [ ] **Step 3: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=EloquentMatcherTest`
Expected: FAIL — `Class "Tests\Helpers\Matchers\EloquentMatcher" not found`

- [ ] **Step 4: Implementovat EloquentMatcher**

`tests/Helpers/Matchers/EloquentMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Illuminate\Database\Eloquent\Model;

final class EloquentMatcher extends BaseMatcher
{
    public function __construct(private readonly Model $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Model === false) {
            return false;
        }

        return $item::class === $this->expected::class
            && (string) $item->getKey() === (string) $this->expected->getKey();
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(
            sprintf('%s with key %s', $this->expected::class, (string) $this->expected->getKey()),
        );
    }
}
```

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=EloquentMatcherTest`
Expected: PASS

- [ ] **Step 6: Napsat failující test pro CollectionMatcher**

`tests/Unit/Helpers/Matchers/CollectionMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Helpers\Matchers\CollectionMatcher;
use Tests\TestCase;

#[CoversClass(CollectionMatcher::class)]
final class CollectionMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertTrue($matcher->matches(new Collection(['a', 'b'])));
    }

    public function testMatchesDifferentCount(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertFalse($matcher->matches(new Collection(['a'])));
    }

    public function testMatchesDifferentValues(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertFalse($matcher->matches(new Collection(['a', 'c'])));
    }

    public function testMatchesNonCollection(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a']));

        $this->assertFalse($matcher->matches(['a']));
    }
}
```

- [ ] **Step 7: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=CollectionMatcherTest`
Expected: FAIL — class not found

- [ ] **Step 8: Implementovat CollectionMatcher**

`tests/Helpers/Matchers/CollectionMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Illuminate\Support\Enumerable;

final class CollectionMatcher extends BaseMatcher
{
    /** @param Enumerable<array-key,mixed> $expected */
    public function __construct(private readonly Enumerable $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Enumerable === false) {
            return false;
        }

        if ($item->count() !== $this->expected->count()) {
            return false;
        }

        return $item->all() == $this->expected->all();
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(sprintf('collection of %d items', $this->expected->count()));
    }
}
```

Srovnání je `==`, ne `===`, aby fungovalo i pro kolekce objektů se stejným obsahem — u kolekcí modelů se používá `EloquentMatcher` na jednotlivé prvky, tady jde o hodnoty.

- [ ] **Step 9: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=CollectionMatcherTest`
Expected: PASS

- [ ] **Step 10: Napsat failující test pro DataMatcher**

`tests/Unit/Helpers/Matchers/DataMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use PHPUnit\Framework\Attributes\CoversClass;
use Spatie\LaravelData\Data;
use Tests\Helpers\Matchers\DataMatcher;
use Tests\TestCase;

final class DataMatcherFixtureData extends Data
{
    public function __construct(public readonly string $symbol, public readonly int $volume)
    {
    }
}

#[CoversClass(DataMatcher::class)]
final class DataMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertTrue($matcher->matches(new DataMatcherFixtureData('AAPL', 100)));
    }

    public function testMatchesDifferentValue(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertFalse($matcher->matches(new DataMatcherFixtureData('AAPL', 101)));
    }

    public function testMatchesNonData(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertFalse($matcher->matches(['symbol' => 'AAPL', 'volume' => 100]));
    }
}
```

- [ ] **Step 11: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=DataMatcherTest`
Expected: FAIL — class not found

- [ ] **Step 12: Implementovat DataMatcher**

`tests/Helpers/Matchers/DataMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Spatie\LaravelData\Data;

final class DataMatcher extends BaseMatcher
{
    public function __construct(private readonly Data $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Data === false) {
            return false;
        }

        if ($item::class !== $this->expected::class) {
            return false;
        }

        return $item->toArray() === $this->expected->toArray();
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(
            sprintf('%s matching %s', $this->expected::class, json_encode($this->expected->toArray())),
        );
    }
}
```

- [ ] **Step 13: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=DataMatcherTest`
Expected: PASS

- [ ] **Step 14: Napsat failující test pro spyLogger**

`tests/Unit/SpyLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SpyLoggerTest extends TestCase
{
    public function testSpyLoggerCapturesWarning(): void
    {
        $handler = $this->spyLogger();

        Log::warning('Recycled symbol detected for AAPL');

        $this->assertTrue($handler->hasWarningThatContains('Recycled symbol detected'));
    }

    public function testSpyLoggerNoMatch(): void
    {
        $handler = $this->spyLogger();

        Log::info('nothing interesting');

        $this->assertFalse($handler->hasWarningThatContains('Recycled symbol detected'));
    }
}
```

- [ ] **Step 15: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=SpyLoggerTest`
Expected: FAIL — `Call to undefined method ...::spyLogger()`

- [ ] **Step 16: Implementovat spyLogger v base TestCase**

`tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private null|TestHandler $logHandler = null;

    protected function spyLogger(): TestHandler
    {
        $this->logHandler ??= $this->registerLogHandler();

        return $this->logHandler;
    }

    private function registerLogHandler(): TestHandler
    {
        $handler = new TestHandler();
        $logger = new Logger('testing', [$handler]);

        Log::swap($logger);
        app()->instance(LoggerInterface::class, $logger);

        return $handler;
    }
}
```

- [ ] **Step 17: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=SpyLoggerTest`
Expected: PASS

- [ ] **Step 18: Spustit statickou analýzu a code style**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

Expected: bez chyb.

- [ ] **Step 19: Commit**

```bash
git add tests/ composer.json composer.lock app/MarketData/Models/Instrument.php
git commit -m "test: matchery a spyLogger podle guidelines"
```

---

### Task 3: Security master — instruments, symbolová historie a SymbolResolver

Jádro ochrany proti slepení dvou firem do jedné cenové řady. Bez tohohle tasku nemá ingest kam napojit řádky.

**Files:**
- Create: `database/migrations/2026_08_06_000100_create_instruments_table.php`
- Create: `database/migrations/2026_08_06_000200_create_instrument_symbols_table.php`
- Create: `app/MarketData/Models/Instrument.php` (dokončení), `app/MarketData/Models/InstrumentSymbol.php`
- Create: `database/factories/InstrumentFactory.php`, `database/factories/InstrumentSymbolFactory.php`
- Create: `app/MarketData/Symbols/SymbolResolver.php`
- Test: `tests/Unit/MarketData/Symbols/SymbolResolverTest.php`

**Interfaces:**
- Consumes: `TestCase::spyLogger()` z Tasku 2
- Produces:
  - `Instrument` model, PK `id` (uuid), `getKey(): string`
  - `InstrumentSymbol` model s `instrument_id`, `symbol`, `valid_from`, `valid_to` (`null` = stále platné)
  - `SymbolResolver::resolve(string $symbol, CarbonImmutable $date): null|Instrument`
  - `SymbolResolver::resolveOrFail(string $symbol, CarbonImmutable $date): Instrument` — hodí `UnknownSymbolException`

- [ ] **Step 1: Napsat migrace**

`database/migrations/2026_08_06_000100_create_instruments_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('asset_class', 32);
            $table->string('primary_exchange', 32);
            $table->string('sector', 64)->nullable();
            $table->date('listed_at')->nullable();
            $table->date('delisted_at')->nullable();
            $table->string('delisting_reason', 64)->nullable();
            $table->timestamps();

            $table->index('delisted_at');
            $table->index(['asset_class', 'primary_exchange']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
```

`database/migrations/2026_08_06_000200_create_instrument_symbols_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('instrument_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->string('symbol', 16);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'valid_from']);
            $table->index('instrument_id');
            $table->index(['symbol', 'valid_from', 'valid_to']);
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_symbols');
    }
};
```

`->index()` je vedle `->foreign()` záměrně — Postgres index pro FK sám nevytváří.

- [ ] **Step 2: Napsat modely a factory**

`app/MarketData/Models/Instrument.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $asset_class
 * @property string $primary_exchange
 * @property null|string $sector
 * @property null|\Carbon\CarbonImmutable $listed_at
 * @property null|\Carbon\CarbonImmutable $delisted_at
 * @property null|string $delisting_reason
 */
class Instrument extends Model
{
    use HasUuids;

    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'listed_at' => 'immutable_date',
        'delisted_at' => 'immutable_date',
    ];

    /** @return HasMany<InstrumentSymbol, $this> */
    public function symbols(): HasMany
    {
        return $this->hasMany(related: InstrumentSymbol::class);
    }
}
```

`app/MarketData/Models/InstrumentSymbol.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Database\Factories\InstrumentSymbolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $instrument_id
 * @property string $symbol
 * @property \Carbon\CarbonImmutable $valid_from
 * @property null|\Carbon\CarbonImmutable $valid_to
 */
class InstrumentSymbol extends Model
{
    use HasUuids;

    /** @use HasFactory<InstrumentSymbolFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'valid_from' => 'immutable_date',
        'valid_to' => 'immutable_date',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }
}
```

`database/factories/InstrumentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Instrument> */
class InstrumentFactory extends Factory
{
    protected $model = Instrument::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'asset_class' => 'us_equity',
            'primary_exchange' => $this->faker->randomElement(['NYSE', 'NASDAQ']),
            'sector' => $this->faker->randomElement(['Technology', 'Healthcare', 'Energy']),
            'listed_at' => '2000-01-03',
            'delisted_at' => null,
            'delisting_reason' => null,
        ];
    }
}
```

`database/factories/InstrumentSymbolFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\InstrumentSymbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstrumentSymbol> */
class InstrumentSymbolFactory extends Factory
{
    protected $model = InstrumentSymbol::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper($this->faker->lexify('????')),
            'valid_from' => '2000-01-03',
            'valid_to' => null,
        ];
    }
}
```

- [ ] **Step 3: Napsat failující test pro SymbolResolver**

`tests/Unit/MarketData/Symbols/SymbolResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Symbols;

use App\MarketData\Models\Instrument;
use App\MarketData\Symbols\SymbolResolver;
use App\MarketData\Symbols\UnknownSymbolException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    use RefreshDatabase;

    public function testResolve(): void
    {
        $instrument = $this->instrumentWithSymbol(
            '550e8400-e29b-41d4-a716-446655440000',
            'AAPL',
            '2000-01-03',
            null,
        );

        $resolved = app(SymbolResolver::class)->resolve('AAPL', CarbonImmutable::parse('2019-03-15'));

        $this->assertSame($instrument->id, $resolved?->id);
    }

    public function testResolveRecycledSymbol(): void
    {
        $old = $this->instrumentWithSymbol(
            '550e8400-e29b-41d4-a716-446655440000',
            'XYZ',
            '2000-01-03',
            '2012-06-30',
        );
        $new = $this->instrumentWithSymbol(
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'XYZ',
            '2015-01-05',
            null,
        );

        $resolver = app(SymbolResolver::class);

        $this->assertSame(
            $old->id,
            $resolver->resolve('XYZ', CarbonImmutable::parse('2010-05-04'))?->id,
        );
        $this->assertSame(
            $new->id,
            $resolver->resolve('XYZ', CarbonImmutable::parse('2020-05-04'))?->id,
        );
    }

    public function testResolveGapBetweenOwners(): void
    {
        $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'XYZ', '2000-01-03', '2012-06-30');
        $this->instrumentWithSymbol('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'XYZ', '2015-01-05', null);

        $resolved = app(SymbolResolver::class)->resolve('XYZ', CarbonImmutable::parse('2013-08-08'));

        $this->assertNull($resolved);
    }

    public function testResolveUnknownSymbol(): void
    {
        $resolved = app(SymbolResolver::class)->resolve('NOPE', CarbonImmutable::parse('2019-03-15'));

        $this->assertNull($resolved);
    }

    public function testResolveOrFailUnknownSymbolExceptionThrow(): void
    {
        $this->expectException(UnknownSymbolException::class);
        $this->expectExceptionMessage('NOPE');

        app(SymbolResolver::class)->resolveOrFail('NOPE', CarbonImmutable::parse('2019-03-15'));
    }

    private function instrumentWithSymbol(
        string $id,
        string $symbol,
        string $validFrom,
        null|string $validTo,
    ): Instrument {
        $instrument = Instrument::factory()->create(['id' => $id]);
        $instrument->symbols()->create([
            'symbol' => $symbol,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);

        return $instrument;
    }
}
```

Test `testResolveGapBetweenOwners` je důležitý: v mezeře mezi dvěma vlastníky tickeru **nesmí** resolver vrátit ani jednoho. Kdyby vracel toho staršího, bulk import by mu přiřadil cizí bary.

- [ ] **Step 4: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=SymbolResolverTest`
Expected: FAIL — `Class "App\MarketData\Symbols\SymbolResolver" not found`

- [ ] **Step 5: Implementovat SymbolResolver a výjimku**

`app/MarketData/Symbols/UnknownSymbolException.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Symbols;

use Carbon\CarbonImmutable;
use RuntimeException;

final class UnknownSymbolException extends RuntimeException
{
    public static function forSymbolAtDate(string $symbol, CarbonImmutable $date): self
    {
        return new self(sprintf('Unknown symbol "%s" at date %s', $symbol, $date->toDateString()));
    }
}
```

`app/MarketData/Symbols/SymbolResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Symbols;

use App\MarketData\Models\Instrument;
use App\MarketData\Models\InstrumentSymbol;
use Carbon\CarbonImmutable;

class SymbolResolver
{
    public function resolve(string $symbol, CarbonImmutable $date): null|Instrument
    {
        $match = InstrumentSymbol::query()
            ->where('symbol', $symbol)
            ->where('valid_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date->toDateString());
            })
            ->first();

        return $match?->instrument;
    }

    public function resolveOrFail(string $symbol, CarbonImmutable $date): Instrument
    {
        $instrument = $this->resolve($symbol, $date);

        if ($instrument === null) {
            throw UnknownSymbolException::forSymbolAtDate($symbol, $date);
        }

        return $instrument;
    }
}
```

Třída není `final`, aby ji šlo spyovat — `readonly` tady není potřeba, protože nemá stav.

- [ ] **Step 6: Spustit test a ověřit zelenou**

Run: `php artisan migrate && vendor/bin/phpunit --filter=SymbolResolverTest`
Expected: PASS, 5 testů

- [ ] **Step 7: Statická analýza a code style**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

- [ ] **Step 8: Commit**

```bash
git add app/MarketData database/migrations database/factories tests/Unit/MarketData
git commit -m "feat: security master s symbolovou historií a SymbolResolver"
```

---

### Task 4: Burzovní kalendář

Předpoklad validace — bez kalendáře nejde odlišit mezeru v datech od svátku.

**Files:**
- Create: `database/migrations/2026_08_06_000300_create_market_days_table.php`
- Create: `app/MarketData/Models/MarketDay.php`, `database/factories/MarketDayFactory.php`
- Create: `app/MarketData/Data/MarketDayData.php`
- Create: `app/MarketData/Calendar/AlpacaCalendarSource.php`, `app/MarketData/Calendar/CalendarImporter.php`
- Create: `app/MarketData/Console/ImportCalendarCommand.php`
- Test: `tests/Unit/MarketData/Calendar/AlpacaCalendarSourceTest.php`, `tests/Feature/MarketData/Calendar/CalendarImporterTest.php`

**Interfaces:**
- Consumes: `TestCase` z Tasku 2
- Produces:
  - `MarketDayData` — `readonly` Data s `exchange`, `date` (`CarbonImmutable`), `isOpen`, `openAt`, `closeAt`, `isEarlyClose`; má `fake()`
  - `AlpacaCalendarSource::fetch(CarbonImmutable $from, CarbonImmutable $to): Generator<int,MarketDayData>`
  - `CalendarImporter::import(iterable $days): int` — vrací počet upsertovaných dní
  - `MarketDay::isTradingDay(string $exchange, CarbonImmutable $date): bool` (statická helper metoda na modelu)
  - Command `market-data:import-calendar {--from=} {--to=}`

- [ ] **Step 1: Napsat migraci**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('market_days', function (Blueprint $table): void {
            $table->string('exchange', 32);
            $table->date('date');
            $table->boolean('is_open');
            $table->time('open_at')->nullable();
            $table->time('close_at')->nullable();
            $table->boolean('is_early_close')->default(false);
            $table->timestamps();

            $table->primary(['exchange', 'date']);
            $table->index(['exchange', 'is_open', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_days');
    }
};
```

Složený přirozený klíč `(exchange, date)` — kalendář je časová řada, ne business entita, takže výjimka z UUID pravidla platí.

- [ ] **Step 2: Napsat MarketDayData s fake()**

`app/MarketData/Data/MarketDayData.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Data;

final class MarketDayData extends Data
{
    public function __construct(
        public readonly string $exchange,
        public readonly CarbonImmutable $date,
        public readonly bool $isOpen,
        public readonly null|string $openAt,
        public readonly null|string $closeAt,
        public readonly bool $isEarlyClose,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();

        return self::from([
            'exchange' => 'XNYS',
            'date' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'isOpen' => true,
            'openAt' => '09:30',
            'closeAt' => '16:00',
            'isEarlyClose' => false,
            ...$attributes,
        ]);
    }
}
```

- [ ] **Step 3: Napsat failující test pro AlpacaCalendarSource**

`tests/Unit/MarketData/Calendar/AlpacaCalendarSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Calendar;

use App\MarketData\Calendar\AlpacaCalendarSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlpacaCalendarSource::class)]
final class AlpacaCalendarSourceTest extends TestCase
{
    public function testFetch(): void
    {
        Http::fake([
            '*/v2/calendar*' => Http::response([
                ['date' => '2019-11-28', 'open' => '09:30', 'close' => '16:00'],
                ['date' => '2019-11-29', 'open' => '09:30', 'close' => '13:00'],
            ]),
        ]);

        $days = iterator_to_array(
            app(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-11-28'),
                CarbonImmutable::parse('2019-11-29'),
            ),
        );

        $this->assertCount(2, $days);
        $this->assertSame('2019-11-28', $days[0]->date->toDateString());
        $this->assertTrue($days[0]->isOpen);
        $this->assertFalse($days[0]->isEarlyClose);
    }

    public function testFetchEarlyClose(): void
    {
        Http::fake([
            '*/v2/calendar*' => Http::response([
                ['date' => '2019-11-29', 'open' => '09:30', 'close' => '13:00'],
            ]),
        ]);

        $days = iterator_to_array(
            app(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-11-29'),
                CarbonImmutable::parse('2019-11-29'),
            ),
        );

        $this->assertTrue($days[0]->isEarlyClose);
    }

    public function testFetchEmptyResponse(): void
    {
        Http::fake(['*/v2/calendar*' => Http::response([])]);

        $days = iterator_to_array(
            app(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-12-25'),
                CarbonImmutable::parse('2019-12-25'),
            ),
        );

        $this->assertSame([], $days);
    }
}
```

- [ ] **Step 4: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=AlpacaCalendarSourceTest`
Expected: FAIL — class not found

- [ ] **Step 5: Implementovat AlpacaCalendarSource**

`app/MarketData/Calendar/AlpacaCalendarSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Calendar;

use App\MarketData\Data\MarketDayData;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class AlpacaCalendarSource
{
    private const string EXCHANGE = 'XNYS';
    private const string REGULAR_CLOSE = '16:00';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secretKey,
    ) {
    }

    /** @return Generator<int,MarketDayData> */
    public function fetch(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $response = Http::withHeaders([
            'APCA-API-KEY-ID' => $this->keyId,
            'APCA-API-SECRET-KEY' => $this->secretKey,
        ])->get($this->baseUrl . '/v2/calendar', [
            'start' => $from->toDateString(),
            'end' => $to->toDateString(),
        ])->throw();

        /** @var array<int,array{date:string,open:string,close:string}> $rows */
        $rows = $response->json();

        foreach ($rows as $row) {
            yield MarketDayData::from([
                'exchange' => self::EXCHANGE,
                'date' => $row['date'],
                'isOpen' => true,
                'openAt' => $row['open'],
                'closeAt' => $row['close'],
                'isEarlyClose' => $row['close'] !== self::REGULAR_CLOSE,
            ]);
        }
    }
}
```

Alpaca vrací **jen otevřené dny** — zavřené dny se dopočítají v importeru jako doplněk kalendářních dní. Endpoint a názvy polí je potřeba ověřit proti aktuální dokumentaci; test je proti `Http::fake`, takže na změně formátu spadne test adaptéru, ne produkční ingest.

Registrace v service provideru (`app/Providers/AppServiceProvider.php`, metoda `register`):

```php
$this->app->bind(AlpacaCalendarSource::class, fn (): AlpacaCalendarSource => new AlpacaCalendarSource(
    baseUrl: (string) config('services.alpaca.base_url'),
    keyId: (string) config('services.alpaca.key_id'),
    secretKey: (string) config('services.alpaca.secret_key'),
));
```

`config/services.php`:

```php
'alpaca' => [
    'base_url' => env('ALPACA_BASE_URL', 'https://api.alpaca.markets'),
    'data_url' => env('ALPACA_DATA_URL', 'https://data.alpaca.markets'),
    'key_id' => env('ALPACA_KEY_ID', ''),
    'secret_key' => env('ALPACA_SECRET_KEY', ''),
    'feed' => env('ALPACA_FEED', 'iex'),
],
```

- [ ] **Step 6: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=AlpacaCalendarSourceTest`
Expected: PASS, 3 testy

- [ ] **Step 7: Napsat failující test pro CalendarImporter**

`tests/Feature/MarketData/Calendar/CalendarImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Calendar;

use App\MarketData\Calendar\CalendarImporter;
use App\MarketData\Data\MarketDayData;
use App\MarketData\Models\MarketDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CalendarImporter::class)]
final class CalendarImporterTest extends TestCase
{
    use RefreshDatabase;

    public function testImport(): void
    {
        $imported = app(CalendarImporter::class)->import([
            MarketDayData::fake(['date' => '2019-11-28', 'closeAt' => '16:00']),
            MarketDayData::fake(['date' => '2019-11-29', 'closeAt' => '13:00', 'isEarlyClose' => true]),
        ]);

        $this->assertSame(2, $imported);
        $this->assertTrue(MarketDay::isTradingDay('XNYS', CarbonImmutable::parse('2019-11-28')));
    }

    public function testImportIdempotence(): void
    {
        $days = [MarketDayData::fake(['date' => '2019-11-28'])];

        app(CalendarImporter::class)->import($days);
        app(CalendarImporter::class)->import($days);

        $this->assertSame(1, MarketDay::query()->count());
    }

    public function testIsTradingDayUnknownDate(): void
    {
        $this->assertFalse(MarketDay::isTradingDay('XNYS', CarbonImmutable::parse('2019-12-25')));
    }
}
```

- [ ] **Step 8: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=CalendarImporterTest`
Expected: FAIL — class not found

- [ ] **Step 9: Implementovat MarketDay model, factory a CalendarImporter**

`app/MarketData/Models/MarketDay.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MarketDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $exchange
 * @property CarbonImmutable $date
 * @property bool $is_open
 * @property null|string $open_at
 * @property null|string $close_at
 * @property bool $is_early_close
 */
class MarketDay extends Model
{
    /** @use HasFactory<MarketDayFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'date' => 'immutable_date',
        'is_open' => 'boolean',
        'is_early_close' => 'boolean',
    ];

    public static function isTradingDay(string $exchange, CarbonImmutable $date): bool
    {
        return self::query()
            ->where('exchange', $exchange)
            ->where('date', $date->toDateString())
            ->where('is_open', true)
            ->exists();
    }
}
```

`database/factories/MarketDayFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\MarketDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketDay> */
class MarketDayFactory extends Factory
{
    protected $model = MarketDay::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'exchange' => 'XNYS',
            'date' => '2019-03-15',
            'is_open' => true,
            'open_at' => '09:30',
            'close_at' => '16:00',
            'is_early_close' => false,
        ];
    }
}
```

`app/MarketData/Calendar/CalendarImporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Calendar;

use App\MarketData\Data\MarketDayData;
use App\MarketData\Models\MarketDay;
use Illuminate\Support\Facades\DB;

class CalendarImporter
{
    /** @param iterable<int,MarketDayData> $days */
    public function import(iterable $days): int
    {
        $count = 0;

        foreach ($days as $day) {
            MarketDay::query()->upsert(
                [[
                    'exchange' => $day->exchange,
                    'date' => $day->date->toDateString(),
                    'is_open' => $day->isOpen,
                    'open_at' => $day->openAt,
                    'close_at' => $day->closeAt,
                    'is_early_close' => $day->isEarlyClose,
                    'created_at' => DB::raw('now()'),
                    'updated_at' => DB::raw('now()'),
                ]],
                ['exchange', 'date'],
                ['is_open', 'open_at', 'close_at', 'is_early_close', 'updated_at'],
            );
            $count++;
        }

        return $count;
    }
}
```

- [ ] **Step 10: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=CalendarImporterTest`
Expected: PASS, 3 testy

- [ ] **Step 11: Napsat command**

`app/MarketData/Console/ImportCalendarCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Calendar\AlpacaCalendarSource;
use App\MarketData\Calendar\CalendarImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ImportCalendarCommand extends Command
{
    protected $signature = 'market-data:import-calendar {--from=2000-01-01} {--to=}';

    protected $description = 'Naplní burzovní kalendář z Alpaca calendar endpointu';

    public function handle(AlpacaCalendarSource $source, CalendarImporter $importer): int
    {
        $from = CarbonImmutable::parse((string) $this->option('from'));
        $to = CarbonImmutable::parse((string) ($this->option('to') ?? CarbonImmutable::now()->addYear()));

        $imported = $importer->import($source->fetch($from, $to));

        $this->info(sprintf('Importováno %d obchodních dní.', $imported));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 12: Statická analýza, code style, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database/migrations database/factories config tests
git commit -m "feat: burzovní kalendář s Alpaca zdrojem a importérem"
```

---

### Task 5: Tabulky barů s partitioningem a BarData

Laravel Schema builder partitionované tabulky neumí — migrace musí použít `DB::statement`.

**Files:**
- Create: `database/migrations/2026_08_06_000400_create_daily_bars_table.php`
- Create: `database/migrations/2026_08_06_000500_create_intraday_bars_table.php`
- Create: `app/MarketData/Models/DailyBar.php`, `app/MarketData/Models/IntradayBar.php`
- Create: `database/factories/DailyBarFactory.php`
- Create: `app/MarketData/Data/BarData.php`
- Create: `app/MarketData/Console/EnsurePartitionsCommand.php`
- Test: `tests/Unit/MarketData/Data/BarDataTest.php`, `tests/Feature/MarketData/Console/EnsurePartitionsCommandTest.php`

**Interfaces:**
- Consumes: `Instrument` z Tasku 3
- Produces:
  - `BarData` — `symbol`, `date` (`CarbonImmutable`), `open`, `high`, `low`, `close` (`float`), `volume` (`int`), `null|CarbonImmutable $ts` pro intradenní; má `fake()`
  - `DailyBar` model, PK `(instrument_id, date)`
  - `IntradayBar` model, PK `(instrument_id, ts)`
  - Command `market-data:ensure-partitions {--from-year=2000} {--to-year=}`
  - `PartitionManager::ensureDailyYear(int $year): void`, `ensureIntradayMonth(int $year, int $month): void`

- [ ] **Step 1: Napsat migraci daily_bars**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE daily_bars (
                instrument_id uuid NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
                date date NOT NULL,
                open numeric(14,6) NOT NULL,
                high numeric(14,6) NOT NULL,
                low numeric(14,6) NOT NULL,
                close numeric(14,6) NOT NULL,
                volume bigint NOT NULL,
                source varchar(32) NOT NULL,
                ingested_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (instrument_id, date)
            ) PARTITION BY RANGE (date)
        SQL);

        DB::statement('CREATE INDEX daily_bars_date_index ON daily_bars (date)');
        DB::statement('CREATE INDEX daily_bars_instrument_id_index ON daily_bars (instrument_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS daily_bars');
    }
};
```

Partition key `date` **musí** být součástí primárního klíče — Postgres to vyžaduje. `(instrument_id, date)` to splňuje a je zároveň klíč, po kterém se dotazuje.

- [ ] **Step 2: Napsat migraci intraday_bars**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE intraday_bars (
                instrument_id uuid NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
                ts timestamptz NOT NULL,
                open numeric(14,6) NOT NULL,
                high numeric(14,6) NOT NULL,
                low numeric(14,6) NOT NULL,
                close numeric(14,6) NOT NULL,
                volume bigint NOT NULL,
                source varchar(32) NOT NULL,
                ingested_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (instrument_id, ts)
            ) PARTITION BY RANGE (ts)
        SQL);

        DB::statement('CREATE INDEX intraday_bars_ts_index ON intraday_bars (ts)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS intraday_bars');
    }
};
```

- [ ] **Step 3: Napsat failující test pro EnsurePartitionsCommand**

`tests/Feature/MarketData/Console/EnsurePartitionsCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Console;

use App\MarketData\Console\EnsurePartitionsCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EnsurePartitionsCommand::class)]
final class EnsurePartitionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testHandle(): void
    {
        $this->artisan('market-data:ensure-partitions', ['--from-year' => 2019, '--to-year' => 2019])
            ->assertSuccessful();

        $this->assertTrue($this->partitionExists('daily_bars_2019'));
        $this->assertTrue($this->partitionExists('intraday_bars_2019_01'));
        $this->assertTrue($this->partitionExists('intraday_bars_2019_12'));
    }

    public function testHandleIdempotence(): void
    {
        $this->artisan('market-data:ensure-partitions', ['--from-year' => 2019, '--to-year' => 2019])
            ->assertSuccessful();
        $this->artisan('market-data:ensure-partitions', ['--from-year' => 2019, '--to-year' => 2019])
            ->assertSuccessful();

        $this->assertTrue($this->partitionExists('daily_bars_2019'));
    }

    private function partitionExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM pg_class WHERE relname = ?',
            [$name],
        ) !== null;
    }
}
```

- [ ] **Step 4: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=EnsurePartitionsCommandTest`
Expected: FAIL — command `market-data:ensure-partitions` neexistuje

- [ ] **Step 5: Implementovat PartitionManager a command**

`app/MarketData/Ingest/PartitionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use Illuminate\Support\Facades\DB;

class PartitionManager
{
    public function ensureDailyYear(int $year): void
    {
        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS daily_bars_%d PARTITION OF daily_bars '
            . "FOR VALUES FROM ('%d-01-01') TO ('%d-01-01')",
            $year,
            $year,
            $year + 1,
        ));
    }

    public function ensureIntradayMonth(int $year, int $month): void
    {
        $start = sprintf('%d-%02d-01', $year, $month);
        $end = $month === 12
            ? sprintf('%d-01-01', $year + 1)
            : sprintf('%d-%02d-01', $year, $month + 1);

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS intraday_bars_%d_%02d PARTITION OF intraday_bars '
            . "FOR VALUES FROM ('%s') TO ('%s')",
            $year,
            $month,
            $start,
            $end,
        ));
    }
}
```

`app/MarketData/Console/EnsurePartitionsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Ingest\PartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class EnsurePartitionsCommand extends Command
{
    protected $signature = 'market-data:ensure-partitions {--from-year=2000} {--to-year=}';

    protected $description = 'Vytvoří chybějící partitions pro tabulky barů';

    public function handle(PartitionManager $partitions): int
    {
        $fromYear = (int) $this->option('from-year');
        $toYear = (int) ($this->option('to-year') ?? CarbonImmutable::now()->year + 1);

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $partitions->ensureDailyYear($year);

            for ($month = 1; $month <= 12; $month++) {
                $partitions->ensureIntradayMonth($year, $month);
            }
        }

        $this->info(sprintf('Partitions zajištěny pro roky %d–%d.', $fromYear, $toYear));

        return self::SUCCESS;
    }
}
```

`CREATE TABLE IF NOT EXISTS` dělá command idempotentní — pouštět se bude ze scheduleru na začátku roku.

- [ ] **Step 6: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=EnsurePartitionsCommandTest`
Expected: PASS, 2 testy

- [ ] **Step 7: Napsat failující test pro BarData**

`tests/Unit/MarketData/Data/BarDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Data;

use App\MarketData\Data\BarData;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(BarData::class)]
final class BarDataTest extends TestCase
{
    public function testFake(): void
    {
        $bar = BarData::fake([
            'symbol' => 'AAPL',
            'date' => '2019-03-15',
            'open' => 180.5,
            'high' => 182.0,
            'low' => 179.25,
            'close' => 181.75,
            'volume' => 1_500_000,
        ]);

        $this->assertSame('AAPL', $bar->symbol);
        $this->assertSame('2019-03-15', $bar->date->toDateString());
        $this->assertSame(1_500_000, $bar->volume);
        $this->assertNull($bar->ts);
    }

    public function testFakeIntraday(): void
    {
        $bar = BarData::fake(['ts' => '2019-03-15 14:30:00']);

        $this->assertSame('2019-03-15 14:30:00', $bar->ts?->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 8: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=BarDataTest`
Expected: FAIL — class not found

- [ ] **Step 9: Implementovat BarData**

`app/MarketData/Data/BarData.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Data;

final class BarData extends Data
{
    public function __construct(
        public readonly string $symbol,
        public readonly CarbonImmutable $date,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly int $volume,
        public readonly null|CarbonImmutable $ts = null,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();
        $close = $faker->randomFloat(2, 10, 500);

        return self::from([
            'symbol' => strtoupper($faker->lexify('????')),
            'date' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'open' => $close,
            'high' => $close + 1.0,
            'low' => $close - 1.0,
            'close' => $close,
            'volume' => $faker->numberBetween(100_000, 10_000_000),
            'ts' => null,
            ...$attributes,
        ]);
    }
}
```

- [ ] **Step 10: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=BarDataTest`
Expected: PASS, 2 testy

- [ ] **Step 11: Implementovat modely barů**

`app/MarketData/Models/DailyBar.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyBarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $instrument_id
 * @property CarbonImmutable $date
 * @property float $open
 * @property float $high
 * @property float $low
 * @property float $close
 * @property int $volume
 * @property string $source
 */
class DailyBar extends Model
{
    /** @use HasFactory<DailyBarFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'date' => 'immutable_date',
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'volume' => 'integer',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }
}
```

`IntradayBar` je stejná třída s `protected $table = 'intraday_bars';`, castem `'ts' => 'immutable_datetime'` místo `date` a property `$ts` v PHPDoc.

`database/factories/DailyBarFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Models\DailyBar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyBar> */
class DailyBarFactory extends Factory
{
    protected $model = DailyBar::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $close = $this->faker->randomFloat(2, 10, 500);

        return [
            'date' => '2019-03-15',
            'open' => $close,
            'high' => $close + 1.0,
            'low' => $close - 1.0,
            'close' => $close,
            'volume' => $this->faker->numberBetween(100_000, 10_000_000),
            'source' => 'fixture',
        ];
    }
}
```

- [ ] **Step 12: Statická analýza, code style, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database tests
git commit -m "feat: partitionované tabulky barů, BarData a správa partitions"
```

---

### Task 6: Corporate actions

**Files:**
- Create: `database/migrations/2026_08_06_000600_create_corporate_actions_table.php`
- Create: `app/MarketData/Models/CorporateAction.php`, `database/factories/CorporateActionFactory.php`
- Create: `app/MarketData/Enums/CorporateActionTypeEnum.php`
- Create: `app/MarketData/Data/CorporateActionData.php`
- Test: `tests/Unit/MarketData/Data/CorporateActionDataTest.php`

**Interfaces:**
- Consumes: `Instrument` z Tasku 3
- Produces:
  - `CorporateActionTypeEnum` — `SPLIT = 'split'`, `DIVIDEND = 'dividend'`, `SYMBOL_CHANGE = 'symbol_change'`, `SPINOFF = 'spinoff'`
  - `CorporateActionData` s `symbol`, `type`, `exDate`, `null|float $ratio`, `null|float $amount`; má `fake()`
  - `CorporateAction` model s `instrument_id`, `type`, `ex_date`, `ratio`, `amount`, `source`

- [ ] **Step 1: Napsat enum**

`app/MarketData/Enums/CorporateActionTypeEnum.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum CorporateActionTypeEnum: string
{
    case SPLIT = 'split';
    case DIVIDEND = 'dividend';
    case SYMBOL_CHANGE = 'symbol_change';
    case SPINOFF = 'spinoff';
}
```

- [ ] **Step 2: Napsat migraci**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('corporate_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->string('type', 32);
            $table->date('ex_date');
            $table->decimal('ratio', 14, 6)->nullable();
            $table->decimal('amount', 14, 6)->nullable();
            $table->string('source', 32);
            $table->timestamp('ingested_at')->useCurrent();
            $table->timestamps();

            $table->unique(['instrument_id', 'type', 'ex_date']);
            $table->index('instrument_id');
            $table->index('ex_date');
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_actions');
    }
};
```

Unique `(instrument_id, type, ex_date)` dělá ingest corporate actions idempotentní.

- [ ] **Step 3: Napsat failující test pro CorporateActionData**

`tests/Unit/MarketData/Data/CorporateActionDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Data;

use App\MarketData\Data\CorporateActionData;
use App\MarketData\Enums\CorporateActionTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CorporateActionData::class)]
final class CorporateActionDataTest extends TestCase
{
    public function testFakeSplit(): void
    {
        $action = CorporateActionData::fake([
            'symbol' => 'AAPL',
            'type' => CorporateActionTypeEnum::SPLIT,
            'exDate' => '2020-08-31',
            'ratio' => 4.0,
            'amount' => null,
        ]);

        $this->assertSame(CorporateActionTypeEnum::SPLIT, $action->type);
        $this->assertSame('2020-08-31', $action->exDate->toDateString());
        $this->assertSame(4.0, $action->ratio);
        $this->assertNull($action->amount);
    }

    public function testFakeDividend(): void
    {
        $action = CorporateActionData::fake([
            'type' => CorporateActionTypeEnum::DIVIDEND,
            'ratio' => null,
            'amount' => 0.82,
        ]);

        $this->assertSame(0.82, $action->amount);
        $this->assertNull($action->ratio);
    }
}
```

- [ ] **Step 4: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=CorporateActionDataTest`
Expected: FAIL — class not found

- [ ] **Step 5: Implementovat CorporateActionData, model a factory**

`app/MarketData/Data/CorporateActionData.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use App\MarketData\Enums\CorporateActionTypeEnum;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Data;

final class CorporateActionData extends Data
{
    public function __construct(
        public readonly string $symbol,
        public readonly CorporateActionTypeEnum $type,
        public readonly CarbonImmutable $exDate,
        public readonly null|float $ratio,
        public readonly null|float $amount,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();

        return self::from([
            'symbol' => strtoupper($faker->lexify('????')),
            'type' => CorporateActionTypeEnum::SPLIT,
            'exDate' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'ratio' => 2.0,
            'amount' => null,
            ...$attributes,
        ]);
    }
}
```

`app/MarketData/Models/CorporateAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\CorporateActionTypeEnum;
use Carbon\CarbonImmutable;
use Database\Factories\CorporateActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $instrument_id
 * @property CorporateActionTypeEnum $type
 * @property CarbonImmutable $ex_date
 * @property null|float $ratio
 * @property null|float $amount
 * @property string $source
 */
class CorporateAction extends Model
{
    use HasUuids;

    /** @use HasFactory<CorporateActionFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'type' => CorporateActionTypeEnum::class,
        'ex_date' => 'immutable_date',
        'ratio' => 'float',
        'amount' => 'float',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }
}
```

`database/factories/CorporateActionFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CorporateAction> */
class CorporateActionFactory extends Factory
{
    protected $model = CorporateAction::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => '2020-08-31',
            'ratio' => 4.0,
            'amount' => null,
            'source' => 'fixture',
        ];
    }
}
```

- [ ] **Step 6: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=CorporateActionDataTest`
Expected: PASS, 2 testy

- [ ] **Step 7: Statická analýza, code style, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database tests
git commit -m "feat: corporate actions jako samostatná entita"
```

---

### Task 7: Audit ingestu — ingest_runs a validation_findings

**Files:**
- Create: `database/migrations/2026_08_06_000700_create_ingest_runs_table.php`
- Create: `database/migrations/2026_08_06_000800_create_validation_findings_table.php`
- Create: `app/MarketData/Models/IngestRun.php`, `app/MarketData/Models/ValidationFinding.php`
- Create: `database/factories/IngestRunFactory.php`, `database/factories/ValidationFindingFactory.php`
- Create: `app/MarketData/Enums/IngestModeEnum.php`, `app/MarketData/Enums/IngestStatusEnum.php`, `app/MarketData/Enums/FindingSeverityEnum.php`
- Test: `tests/Feature/MarketData/Models/IngestRunTest.php`

**Interfaces:**
- Consumes: `Instrument` z Tasku 3
- Produces:
  - `IngestModeEnum` — `BULK = 'bulk'`, `INCREMENTAL = 'incremental'`
  - `IngestStatusEnum` — `RUNNING = 'running'`, `COMPLETED = 'completed'`, `FAILED = 'failed'`
  - `FindingSeverityEnum` — `ERROR = 'error'`, `WARNING = 'warning'`
  - `IngestRun` model s `source`, `mode`, `file_hash`, `started_at`, `finished_at`, `rows_inserted`, `rows_updated`, `status`, `checkpoint`, `error`
  - `IngestRun::completedForFileHash(string $hash): bool` — základ idempotence bulk importu
  - `ValidationFinding` model s `ingest_run_id`, `instrument_id`, `date`, `rule`, `severity`, `detail`

- [ ] **Step 1: Napsat enumy**

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum IngestModeEnum: string
{
    case BULK = 'bulk';
    case INCREMENTAL = 'incremental';
}
```

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum IngestStatusEnum: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
```

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Enums;

enum FindingSeverityEnum: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
}
```

- [ ] **Step 2: Napsat migrace**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ingest_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 64);
            $table->string('mode', 16);
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('rows_inserted')->default(0);
            $table->unsignedBigInteger('rows_updated')->default(0);
            $table->string('status', 16);
            $table->json('checkpoint')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_runs');
    }
};
```

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('validation_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ingest_run_id');
            $table->uuid('instrument_id')->nullable();
            $table->date('date')->nullable();
            $table->string('rule', 64);
            $table->string('severity', 16);
            $table->text('detail');
            $table->timestamps();

            $table->index('ingest_run_id');
            $table->index('instrument_id');
            $table->index(['rule', 'severity']);
            $table->foreign('ingest_run_id')->references('id')->on('ingest_runs')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_findings');
    }
};
```

`instrument_id` a `date` jsou nullable — strukturální finding (chybějící sloupec v souboru) se k žádnému instrumentu nevztahuje.

- [ ] **Step 3: Napsat failující test**

`tests/Feature/MarketData/Models/IngestRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Models;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(IngestRun::class)]
final class IngestRunTest extends TestCase
{
    use RefreshDatabase;

    public function testCompletedForFileHash(): void
    {
        IngestRun::factory()->create([
            'file_hash' => 'abc123',
            'status' => IngestStatusEnum::COMPLETED,
        ]);

        $this->assertTrue(IngestRun::completedForFileHash('abc123'));
    }

    public function testCompletedForFileHashFailedRun(): void
    {
        IngestRun::factory()->create([
            'file_hash' => 'abc123',
            'status' => IngestStatusEnum::FAILED,
        ]);

        $this->assertFalse(IngestRun::completedForFileHash('abc123'));
    }

    public function testCompletedForFileHashUnknownHash(): void
    {
        $this->assertFalse(IngestRun::completedForFileHash('nothing'));
    }

    public function testFindings(): void
    {
        $run = IngestRun::factory()->create();
        $run->findings()->create([
            'rule' => 'OhlcConsistency',
            'severity' => FindingSeverityEnum::ERROR,
            'detail' => 'low > high',
        ]);

        $this->assertSame(1, $run->findings()->count());
    }
}
```

Rozdíl mezi prvním a druhým testem je pointa idempotence: **jen dokončený běh** blokuje reimport. Spadlý běh se musí dát zopakovat.

- [ ] **Step 4: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=IngestRunTest`
Expected: FAIL — class not found

- [ ] **Step 5: Implementovat modely a factory**

`app/MarketData/Models/IngestRun.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use Carbon\CarbonImmutable;
use Database\Factories\IngestRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $source
 * @property IngestModeEnum $mode
 * @property null|string $file_hash
 * @property CarbonImmutable $started_at
 * @property null|CarbonImmutable $finished_at
 * @property int $rows_inserted
 * @property int $rows_updated
 * @property IngestStatusEnum $status
 * @property null|array<string,mixed> $checkpoint
 * @property null|string $error
 */
class IngestRun extends Model
{
    use HasUuids;

    /** @use HasFactory<IngestRunFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'mode' => IngestModeEnum::class,
        'status' => IngestStatusEnum::class,
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'checkpoint' => 'array',
    ];

    public static function completedForFileHash(string $hash): bool
    {
        return self::query()
            ->where('file_hash', $hash)
            ->where('status', IngestStatusEnum::COMPLETED)
            ->exists();
    }

    /** @return HasMany<ValidationFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(related: ValidationFinding::class);
    }
}
```

`app/MarketData/Models/ValidationFinding.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;
use Database\Factories\ValidationFindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ingest_run_id
 * @property null|string $instrument_id
 * @property null|CarbonImmutable $date
 * @property string $rule
 * @property FindingSeverityEnum $severity
 * @property string $detail
 */
class ValidationFinding extends Model
{
    use HasUuids;

    /** @use HasFactory<ValidationFindingFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'severity' => FindingSeverityEnum::class,
        'date' => 'immutable_date',
    ];

    /** @return BelongsTo<IngestRun, $this> */
    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(related: IngestRun::class);
    }
}
```

`database/factories/IngestRunFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngestRun> */
class IngestRunFactory extends Factory
{
    protected $model = IngestRun::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'source' => 'fixture',
            'mode' => IngestModeEnum::BULK,
            'file_hash' => null,
            'started_at' => '2026-08-06 08:00:00',
            'finished_at' => '2026-08-06 08:05:00',
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'status' => IngestStatusEnum::COMPLETED,
            'checkpoint' => null,
            'error' => null,
        ];
    }
}
```

`database/factories/ValidationFindingFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ValidationFinding> */
class ValidationFindingFactory extends Factory
{
    protected $model = ValidationFinding::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'instrument_id' => null,
            'date' => null,
            'rule' => 'OhlcConsistency',
            'severity' => FindingSeverityEnum::WARNING,
            'detail' => 'fixture finding',
        ];
    }
}
```

- [ ] **Step 6: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=IngestRunTest`
Expected: PASS, 4 testy

- [ ] **Step 7: Statická analýza, code style, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database tests
git commit -m "feat: audit ingestu — ingest_runs a validation_findings"
```

---

### Task 8: BarSourcePort a bulk CSV zdroj

**Files:**
- Create: `app/MarketData/Contracts/BarSourcePort.php`
- Create: `app/MarketData/Ingest/Bulk/GenericOhlcvCsvSource.php`
- Create: `app/MarketData/Ingest/Bulk/InvalidCsvHeaderException.php`
- Create: `app/MarketData/Ingest/Bulk/BulkFileRegistry.php`
- Test: `tests/Unit/MarketData/Ingest/Bulk/GenericOhlcvCsvSourceTest.php`, `BulkFileRegistryTest.php`
- Test fixture: `tests/fixtures/market-data/daily-sample.csv`

**Interfaces:**
- Consumes: `BarData` z Tasku 5, `IngestRun` z Tasku 7
- Produces:
  - `BarSourcePort::name(): string` a `BarSourcePort::dailyBars(): Generator<int,BarData>`
  - `GenericOhlcvCsvSource` — konstruktor bere cestu k souboru; podporuje `.csv` a `.csv.gz`
  - `BulkFileRegistry::hash(string $path): string` (sha256, streamovaně) a `alreadyImported(string $hash): bool`

**Poznámka k formátu:** zdroj čte **jeden CSV stream**. Vendor dumpy přicházejí jako ZIP a rozbalují se jednou, ručně, před importem. Streamované čtení ZIPu by přidalo netriviální kód pro jednorázovou operátorskou činnost — proto ne.

- [ ] **Step 1: Napsat fixture se skutečným výřezem formátu**

`tests/fixtures/market-data/daily-sample.csv`:

```csv
symbol,date,open,high,low,close,volume
AAPL,2019-03-13,182.25,183.30,181.46,181.71,31032530
AAPL,2019-03-14,183.90,184.10,182.56,183.73,23579500
XYZ,2019-03-13,14.10,14.55,13.98,14.22,412300
XYZ,2019-03-14,14.25,14.30,13.55,13.61,988100
```

- [ ] **Step 2: Napsat failující test**

`tests/Unit/MarketData/Ingest/Bulk/GenericOhlcvCsvSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Bulk;

use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\Bulk\InvalidCsvHeaderException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(GenericOhlcvCsvSource::class)]
final class GenericOhlcvCsvSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../../fixtures/market-data/daily-sample.csv';

    public function testDailyBars(): void
    {
        $bars = iterator_to_array(new GenericOhlcvCsvSource(self::FIXTURE)->dailyBars());

        $this->assertCount(4, $bars);
        $this->assertSame('AAPL', $bars[0]->symbol);
        $this->assertSame('2019-03-13', $bars[0]->date->toDateString());
        $this->assertEqualsWithDelta(181.71, $bars[0]->close, 0.0001);
        $this->assertSame(31032530, $bars[0]->volume);
    }

    public function testDailyBarsEmptyFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bars');
        file_put_contents($path, "symbol,date,open,high,low,close,volume\n");

        $bars = iterator_to_array(new GenericOhlcvCsvSource($path)->dailyBars());

        unlink($path);
        $this->assertSame([], $bars);
    }

    public function testDailyBarsInvalidCsvHeaderExceptionThrow(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bars');
        file_put_contents($path, "ticker,day,o,h,l,c,v\nAAPL,2019-03-13,1,1,1,1,1\n");

        $this->expectException(InvalidCsvHeaderException::class);

        try {
            iterator_to_array(new GenericOhlcvCsvSource($path)->dailyBars());
        } finally {
            unlink($path);
        }
    }

    public function testName(): void
    {
        $this->assertSame('bulk:daily-sample.csv', new GenericOhlcvCsvSource(self::FIXTURE)->name());
    }
}
```

- [ ] **Step 3: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=GenericOhlcvCsvSourceTest`
Expected: FAIL — class not found

- [ ] **Step 4: Implementovat kontrakt a zdroj**

`app/MarketData/Contracts/BarSourcePort.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Data\BarData;
use Generator;

interface BarSourcePort
{
    public function name(): string;

    /** @return Generator<int,BarData> */
    public function dailyBars(): Generator;
}
```

`app/MarketData/Ingest/Bulk/InvalidCsvHeaderException.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use RuntimeException;

final class InvalidCsvHeaderException extends RuntimeException
{
    /** @param array<int,string> $expected */
    public static function forHeader(string $path, array $expected): self
    {
        return new self(sprintf(
            'Soubor %s nemá očekávanou hlavičku: %s',
            $path,
            implode(',', $expected),
        ));
    }
}
```

`app/MarketData/Ingest/Bulk/GenericOhlcvCsvSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use App\MarketData\Contracts\BarSourcePort;
use App\MarketData\Data\BarData;
use Generator;

class GenericOhlcvCsvSource implements BarSourcePort
{
    /** @var array<int,string> */
    private const array HEADER = ['symbol', 'date', 'open', 'high', 'low', 'close', 'volume'];

    public function __construct(private readonly string $path)
    {
    }

    public function name(): string
    {
        return 'bulk:' . basename($this->path);
    }

    /** @return Generator<int,BarData> */
    public function dailyBars(): Generator
    {
        $handle = str_ends_with($this->path, '.gz')
            ? gzopen($this->path, 'rb')
            : fopen($this->path, 'rb');

        if ($handle === false) {
            throw InvalidCsvHeaderException::forHeader($this->path, self::HEADER);
        }

        $header = fgetcsv($handle);

        if ($header !== self::HEADER) {
            fclose($handle);

            throw InvalidCsvHeaderException::forHeader($this->path, self::HEADER);
        }

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }

            yield BarData::from([
                'symbol' => (string) $row[0],
                'date' => (string) $row[1],
                'open' => (float) $row[2],
                'high' => (float) $row[3],
                'low' => (float) $row[4],
                'close' => (float) $row[5],
                'volume' => (int) $row[6],
                'ts' => null,
            ]);
        }

        fclose($handle);
    }
}
```

`Generator` je tam kvůli tomu, že dump má miliony řádků — `fgetcsv` ve smyčce drží v paměti jeden řádek, ne soubor.

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=GenericOhlcvCsvSourceTest`
Expected: PASS, 4 testy

- [ ] **Step 6: Napsat failující test pro BulkFileRegistry**

`tests/Unit/MarketData/Ingest/Bulk/BulkFileRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Bulk;

use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\Bulk\BulkFileRegistry;
use App\MarketData\Models\IngestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(BulkFileRegistry::class)]
final class BulkFileRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURE = __DIR__ . '/../../../../fixtures/market-data/daily-sample.csv';

    public function testHash(): void
    {
        $registry = app(BulkFileRegistry::class);

        $this->assertSame($registry->hash(self::FIXTURE), $registry->hash(self::FIXTURE));
        $this->assertSame(64, strlen($registry->hash(self::FIXTURE)));
    }

    public function testAlreadyImported(): void
    {
        $registry = app(BulkFileRegistry::class);
        $hash = $registry->hash(self::FIXTURE);

        IngestRun::factory()->create(['file_hash' => $hash, 'status' => IngestStatusEnum::COMPLETED]);

        $this->assertTrue($registry->alreadyImported($hash));
    }

    public function testAlreadyImportedFailedRun(): void
    {
        $registry = app(BulkFileRegistry::class);
        $hash = $registry->hash(self::FIXTURE);

        IngestRun::factory()->create(['file_hash' => $hash, 'status' => IngestStatusEnum::FAILED]);

        $this->assertFalse($registry->alreadyImported($hash));
    }
}
```

- [ ] **Step 7: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=BulkFileRegistryTest`
Expected: FAIL — class not found

- [ ] **Step 8: Implementovat BulkFileRegistry**

`app/MarketData/Ingest/Bulk/BulkFileRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use App\MarketData\Models\IngestRun;

class BulkFileRegistry
{
    public function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw InvalidCsvHeaderException::forHeader($path, []);
        }

        return $hash;
    }

    public function alreadyImported(string $hash): bool
    {
        return IngestRun::completedForFileHash($hash);
    }
}
```

`hash_file` čte streamovaně, takže ani u několikagigového dumpu nenaroste paměť.

- [ ] **Step 9: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=BulkFileRegistryTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: BarSourcePort a bulk CSV zdroj s hashem pro idempotenci"
```

---

### Task 9: Staging tabulka a COPY writer

**Files:**
- Create: `app/MarketData/Ingest/StagingTable.php`
- Test: `tests/Feature/MarketData/Ingest/StagingTableTest.php`

**Interfaces:**
- Consumes: `BarData` z Tasku 5
- Produces:
  - `StagingTable::create(string $runId): string` — vytvoří unlogged tabulku, vrátí její jméno
  - `StagingTable::write(string $table, iterable $bars): int` — `COPY FROM STDIN` v dávkách, vrací počet řádků
  - `StagingTable::drop(string $table): void`

- [ ] **Step 1: Napsat failující test**

`tests/Feature/MarketData/Ingest/StagingTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Ingest;

use App\MarketData\Data\BarData;
use App\MarketData\Ingest\StagingTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(StagingTable::class)]
final class StagingTableTest extends TestCase
{
    use RefreshDatabase;

    public function testWrite(): void
    {
        $staging = app(StagingTable::class);
        $table = $staging->create('550e8400-e29b-41d4-a716-446655440000');

        $written = $staging->write($table, [
            BarData::fake(['symbol' => 'AAPL', 'date' => '2019-03-13', 'close' => 181.71, 'volume' => 100]),
            BarData::fake(['symbol' => 'XYZ', 'date' => '2019-03-13', 'close' => 14.22, 'volume' => 200]),
        ]);

        $this->assertSame(2, $written);
        $this->assertSame(2, (int) DB::table($table)->count());
        $this->assertSame('AAPL', DB::table($table)->orderBy('symbol')->first()?->symbol);

        $staging->drop($table);
    }

    public function testWriteEmpty(): void
    {
        $staging = app(StagingTable::class);
        $table = $staging->create('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame(0, $staging->write($table, []));

        $staging->drop($table);
    }

    public function testDrop(): void
    {
        $staging = app(StagingTable::class);
        $table = $staging->create('550e8400-e29b-41d4-a716-446655440000');

        $staging->drop($table);

        $this->assertNull(DB::selectOne('SELECT 1 AS found FROM pg_class WHERE relname = ?', [$table]));
    }
}
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=StagingTableTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat StagingTable**

`app/MarketData/Ingest/StagingTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Data\BarData;
use Illuminate\Support\Facades\DB;

class StagingTable
{
    private const int CHUNK = 20000;

    public function create(string $runId): string
    {
        $table = 'staging_bars_' . str_replace('-', '', $runId);

        DB::statement(sprintf(
            'CREATE UNLOGGED TABLE %s ('
            . 'symbol varchar(16) NOT NULL, date date NOT NULL, '
            . 'open numeric(14,6) NOT NULL, high numeric(14,6) NOT NULL, '
            . 'low numeric(14,6) NOT NULL, close numeric(14,6) NOT NULL, '
            . 'volume bigint NOT NULL, instrument_id uuid NULL)',
            $table,
        ));

        return $table;
    }

    /** @param iterable<int,BarData> $bars */
    public function write(string $table, iterable $bars): int
    {
        $pdo = DB::connection()->getPdo();
        $total = 0;
        $chunk = [];

        foreach ($bars as $bar) {
            $chunk[] = implode("\t", [
                $bar->symbol,
                $bar->date->toDateString(),
                $bar->open,
                $bar->high,
                $bar->low,
                $bar->close,
                $bar->volume,
            ]);

            if (count($chunk) < self::CHUNK) {
                continue;
            }

            $pdo->pgsqlCopyFromArray($table, $chunk);
            $total += count($chunk);
            $chunk = [];
        }

        if (empty($chunk) === false) {
            $pdo->pgsqlCopyFromArray($table, $chunk);
            $total += count($chunk);
        }

        return $total;
    }

    public function drop(string $table): void
    {
        DB::statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    }
}
```

Tři rozhodnutí, která stojí za vysvětlení.

**`UNLOGGED`** vypíná WAL pro staging tabulku — u stomilionového importu je to řádový rozdíl a data v ní jsou stejně jednorázová.

**Dávka 20 000 řádků** je kompromis: `pgsqlCopyFromArray` chce pole v paměti, takže neomezená dávka by paměť sežrala, a příliš malá by ztratila výhodu `COPY`.

**`instrument_id` je nullable a při zápisu se neplní.** Specifikace předepisuje pořadí *resolve → stage → validate*; plán ho mění na *stage → resolve v SQL → validate*, protože resolvovat sto milionů řádků po jednom v PHP je zbytečné, když to Postgres udělá jedním `UPDATE ... FROM` join (Task 10). Karanténa se tím zjednoduší na „řádky, kde `instrument_id` zůstal `NULL`" a je to množinová operace, ne cyklus. Chování zůstává stejné — neznámý symbol se nikdy nehádá.

- [ ] **Step 4: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=StagingTableTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: unlogged staging tabulka a COPY writer"
```

---

### Task 10: Množinové párování symbolů a karanténa

**Files:**
- Create: `app/MarketData/Ingest/StagingResolver.php`
- Test: `tests/Feature/MarketData/Ingest/StagingResolverTest.php`

**Interfaces:**
- Consumes: `StagingTable` z Tasku 9, `InstrumentSymbol` z Tasku 3, `ValidationFinding` z Tasku 7
- Produces:
  - `StagingResolver::resolve(string $table): int` — doplní `instrument_id`, vrátí počet napárovaných řádků
  - `StagingResolver::quarantine(string $table, string $runId): int` — zapíše nálezy za nenapárované řádky, smaže je ze staging a vrátí počet smazaných

- [ ] **Step 1: Napsat failující test**

`tests/Feature/MarketData/Ingest/StagingResolverTest.php`:

```php
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

        $resolved = app(StagingResolver::class)->resolve($table);

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

        app(StagingResolver::class)->resolve($table);

        $this->assertSame($old->id, DB::table($table)->where('date', '2010-05-04')->first()?->instrument_id);
        $this->assertSame($new->id, DB::table($table)->where('date', '2020-05-04')->first()?->instrument_id);
    }

    public function testResolveGapBetweenOwners(): void
    {
        $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'XYZ', '2000-01-03', '2012-06-30');
        $table = $this->stagedBars([BarData::fake(['symbol' => 'XYZ', 'date' => '2013-08-08'])]);

        $resolved = app(StagingResolver::class)->resolve($table);

        $this->assertSame(0, $resolved);
        $this->assertNull(DB::table($table)->first()?->instrument_id);
    }

    public function testQuarantine(): void
    {
        $run = IngestRun::factory()->create();
        $table = $this->stagedBars([
            BarData::fake(['symbol' => 'NOPE', 'date' => '2019-03-13']),
            BarData::fake(['symbol' => 'NOPE', 'date' => '2019-03-14']),
        ]);

        $removed = app(StagingResolver::class)->quarantine($table, $run->id);

        $this->assertSame(2, $removed);
        $this->assertSame(0, (int) DB::table($table)->count());

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
        app(StagingResolver::class)->resolve($table);

        $this->assertSame(0, app(StagingResolver::class)->quarantine($table, $run->id));
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
        $staging = app(StagingTable::class);
        $table = $staging->create('550e8400-e29b-41d4-a716-446655440000');
        $staging->write($table, $bars);

        return $table;
    }
}
```

`testQuarantine` ověřuje důležitou věc: nálezy se agregují **per symbol**, ne per řádek. Dva řádky neznámého symbolu dají jeden nález s počtem, ne dva nálezy. U rozbitého dumpu by per-řádkové nálezy vyrobily miliony záznamů.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=StagingResolverTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat StagingResolver**

`app/MarketData/Ingest/StagingResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;
use Illuminate\Support\Facades\DB;

class StagingResolver
{
    private const string RULE = 'UnknownSymbol';

    public function resolve(string $table): int
    {
        return DB::update(sprintf(
            'UPDATE %s AS s SET instrument_id = m.instrument_id '
            . 'FROM instrument_symbols AS m '
            . 'WHERE m.symbol = s.symbol '
            . 'AND m.valid_from <= s.date '
            . 'AND (m.valid_to IS NULL OR m.valid_to >= s.date)',
            $table,
        ));
    }

    public function quarantine(string $table, string $runId): int
    {
        /** @var array<int,object{symbol:string,rows:int,first_date:string,last_date:string}> $groups */
        $groups = DB::select(sprintf(
            'SELECT symbol, count(*) AS rows, min(date) AS first_date, max(date) AS last_date '
            . 'FROM %s WHERE instrument_id IS NULL GROUP BY symbol ORDER BY symbol',
            $table,
        ));

        foreach ($groups as $group) {
            ValidationFinding::query()->create([
                'ingest_run_id' => $runId,
                'instrument_id' => null,
                'date' => null,
                'rule' => self::RULE,
                'severity' => FindingSeverityEnum::ERROR,
                'detail' => sprintf(
                    'Symbol %s nenapárován: %d řádků, %s..%s',
                    $group->symbol,
                    $group->rows,
                    $group->first_date,
                    $group->last_date,
                ),
            ]);
        }

        return DB::delete(sprintf('DELETE FROM %s WHERE instrument_id IS NULL', $table));
    }
}
```

- [ ] **Step 4: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=StagingResolverTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: množinové párování symbolů a karanténa nenapárovaných řádků"
```

---

### Task 11: Kontrakt validačního pravidla a pravidla se severitou error

**Files:**
- Create: `app/MarketData/Contracts/ValidationRule.php`
- Create: `app/MarketData/Data/ValidationFindingData.php`
- Create: `app/MarketData/Validation/Rules/OhlcConsistencyRule.php`, `DuplicateBarRule.php`, `BarOnClosedDayRule.php`
- Test: `tests/Feature/MarketData/Validation/Rules/OhlcConsistencyRuleTest.php`, `DuplicateBarRuleTest.php`, `BarOnClosedDayRuleTest.php`

**Interfaces:**
- Consumes: staging tabulka s doplněným `instrument_id` z Tasku 10, `MarketDay` z Tasku 4
- Produces:
  - `ValidationRule::name(): string`, `severity(): FindingSeverityEnum`, `findings(string $stagingTable): Generator<int,ValidationFindingData>`
  - `ValidationFindingData` s `instrumentId`, `date`, `detail`
  - `ValidationRule::FINDING_CAP = 1000` — strop nálezů na pravidlo a běh, s povinným souhrnným nálezem při jeho dosažení

- [ ] **Step 1: Napsat kontrakt a DTO**

`app/MarketData/Data/ValidationFindingData.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ValidationFindingData extends Data
{
    public function __construct(
        public readonly null|string $instrumentId,
        public readonly null|CarbonImmutable $date,
        public readonly string $detail,
    ) {
    }
}
```

`app/MarketData/Contracts/ValidationRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Generator;

interface ValidationRule
{
    public const int FINDING_CAP = 1000;

    public function name(): string;

    public function severity(): FindingSeverityEnum;

    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator;
}
```

Strop je součástí kontraktu, protože rozbitý soubor by jinak vyrobil miliony nálezů. Při jeho dosažení **musí** pravidlo vydat souhrnný nález — tiché zahození by vypadalo jako „našlo se přesně tisíc problémů".

- [ ] **Step 2: Napsat failující test pro OhlcConsistencyRule**

`tests/Feature/MarketData/Validation/Rules/OhlcConsistencyRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Validation\Rules\OhlcConsistencyRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Tests\Support\StagingFixture;

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

        for ($i = 0; $i < 1005; $i++) {
            $rows[] = [
                'symbol' => 'AAPL',
                'date' => sprintf('2019-%02d-%02d', 1 + intdiv($i, 28), 1 + $i % 28),
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
```

Potřebuješ pomocníka `Tests\Support\StagingFixture`, který vytvoří staging tabulku s doplněným `instrument_id` — vytvoř ho v tomto tasku:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\MarketData\Ingest\StagingTable;
use App\MarketData\Models\Instrument;
use Illuminate\Support\Facades\DB;

final class StagingFixture
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function withRows(array $rows, string $instrumentId = '550e8400-e29b-41d4-a716-446655440000'): string
    {
        Instrument::factory()->create(['id' => $instrumentId]);
        $table = app(StagingTable::class)->create('11111111-2222-3333-4444-555555555555');

        foreach ($rows as $row) {
            DB::table($table)->insert([
                'symbol' => $row['symbol'],
                'date' => $row['date'],
                'open' => $row['open'],
                'high' => $row['high'],
                'low' => $row['low'],
                'close' => $row['close'],
                'volume' => $row['volume'] ?? 1000,
                'instrument_id' => $row['instrument_id'] ?? $instrumentId,
            ]);
        }

        return $table;
    }
}
```

Vkládá se přes `insert`, ne přes `COPY` — jde o desítky řádků a test má být čitelný.

- [ ] **Step 3: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=OhlcConsistencyRuleTest`
Expected: FAIL — class not found

- [ ] **Step 4: Implementovat OhlcConsistencyRule**

`app/MarketData/Validation/Rules/OhlcConsistencyRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Generator;
use Illuminate\Support\Facades\DB;

class OhlcConsistencyRule implements ValidationRule
{
    public function name(): string
    {
        return 'OhlcConsistency';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator
    {
        $rows = DB::cursor(sprintf(
            'SELECT instrument_id, date, open, high, low, close FROM %s '
            . 'WHERE low > least(open, close) OR high < greatest(open, close) '
            . 'OR low > high OR open <= 0 OR high <= 0 OR low <= 0 OR close <= 0 '
            . 'ORDER BY instrument_id, date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        ));

        $emitted = 0;

        foreach ($rows as $row) {
            if ($emitted === self::FINDING_CAP) {
                yield new ValidationFindingData(
                    instrumentId: null,
                    date: null,
                    detail: sprintf('Dosažen strop %d nálezů pravidla %s, další nezapsány', self::FINDING_CAP, $this->name()),
                );

                return;
            }

            yield new ValidationFindingData(
                instrumentId: (string) $row->instrument_id,
                date: CarbonImmutable::parse((string) $row->date),
                detail: sprintf(
                    'Nekonzistentní OHLC k %s: o=%s h=%s l=%s c=%s',
                    (string) $row->date,
                    (string) $row->open,
                    (string) $row->high,
                    (string) $row->low,
                    (string) $row->close,
                ),
            );

            $emitted++;
        }
    }
}
```

`DB::cursor` místo `DB::select` — u velkého dumpu by `select` natáhl všechny nálezy do paměti. `LIMIT cap + 1` je trik, který dovolí poznat, že se strop překročil, bez druhého dotazu.

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=OhlcConsistencyRuleTest`
Expected: PASS, 6 testů

- [ ] **Step 6: Napsat failující test pro DuplicateBarRule**

`tests/Feature/MarketData/Validation/Rules/DuplicateBarRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Validation\Rules\DuplicateBarRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(DuplicateBarRule::class)]
final class DuplicateBarRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
            ['symbol' => 'AAPL', 'date' => '2019-03-14', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $this->assertSame([], iterator_to_array(new DuplicateBarRule()->findings($table)));
    }

    public function testFindingsDuplicate(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 12],
        ]);

        $findings = iterator_to_array(new DuplicateBarRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-13', $findings[0]->detail);
        $this->assertStringContainsString('2', $findings[0]->detail);
    }

    public function testFindingsEmptyTable(): void
    {
        $this->assertSame([], iterator_to_array(new DuplicateBarRule()->findings(StagingFixture::withRows([]))));
    }
}
```

- [ ] **Step 7: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=DuplicateBarRuleTest`
Expected: FAIL — class not found

- [ ] **Step 8: Implementovat DuplicateBarRule**

Struktura je stejná jako u `OhlcConsistencyRule` (včetně stropu a `DB::cursor`), mění se dotaz a text nálezu:

```php
$rows = DB::cursor(sprintf(
    'SELECT instrument_id, date, count(*) AS occurrences FROM %s '
    . 'GROUP BY instrument_id, date HAVING count(*) > 1 '
    . 'ORDER BY instrument_id, date LIMIT %d',
    $stagingTable,
    self::FINDING_CAP + 1,
));

// v cyklu:
yield new ValidationFindingData(
    instrumentId: (string) $row->instrument_id,
    date: CarbonImmutable::parse((string) $row->date),
    detail: sprintf('Duplicitní bar k %s: %d výskytů', (string) $row->date, (int) $row->occurrences),
);
```

`name()` vrací `'DuplicateBar'`, `severity()` vrací `FindingSeverityEnum::ERROR`.

- [ ] **Step 9: Napsat failující test pro BarOnClosedDayRule**

`tests/Feature/MarketData/Validation/Rules/BarOnClosedDayRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\BarOnClosedDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(BarOnClosedDayRule::class)]
final class BarOnClosedDayRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $this->assertSame([], iterator_to_array(new BarOnClosedDayRule()->findings($table)));
    }

    public function testFindingsClosedDay(): void
    {
        MarketDay::factory()->create(['date' => '2019-12-25', 'is_open' => false]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-12-25', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $findings = iterator_to_array(new BarOnClosedDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-12-25', $findings[0]->detail);
    }

    public function testFindingsUnknownDay(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $findings = iterator_to_array(new BarOnClosedDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('kalendář', $findings[0]->detail);
    }
}
```

`testFindingsUnknownDay` je záměrný: den, který v kalendáři **není vůbec**, je taky nález. Kdyby se bral jako v pořádku, nenaplněný kalendář by celé pravidlo tiše vypnul.

- [ ] **Step 10: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=BarOnClosedDayRuleTest`
Expected: FAIL — class not found

- [ ] **Step 11: Implementovat BarOnClosedDayRule**

Struktura opět stejná, dotaz je `LEFT JOIN` na kalendář:

```php
$rows = DB::cursor(sprintf(
    'SELECT s.instrument_id, s.date, m.is_open FROM %s AS s '
    . "LEFT JOIN market_days AS m ON m.date = s.date AND m.exchange = 'XNYS' "
    . 'WHERE m.date IS NULL OR m.is_open = false '
    . 'ORDER BY s.instrument_id, s.date LIMIT %d',
    $stagingTable,
    self::FINDING_CAP + 1,
));

// v cyklu:
$detail = $row->is_open === null
    ? sprintf('Bar k %s, ale den není v kalendáři', (string) $row->date)
    : sprintf('Bar k %s, ale burza byla zavřená', (string) $row->date);
```

`name()` vrací `'BarOnClosedDay'`, `severity()` vrací `FindingSeverityEnum::ERROR`.

- [ ] **Step 12: Spustit všechny tři testy, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='OhlcConsistencyRuleTest|DuplicateBarRuleTest|BarOnClosedDayRuleTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: kontrakt validačního pravidla a pravidla se severitou error"
```

---

### Task 12: Pokrytí a svěžest dat — warning pravidla nad kalendářem

**Files:**
- Create: `app/MarketData/Validation/Rules/AbstractStagingRule.php`
- Modify: `app/MarketData/Validation/Rules/OhlcConsistencyRule.php`, `DuplicateBarRule.php`, `BarOnClosedDayRule.php` (přepojit na základní třídu)
- Create: `app/MarketData/Validation/Rules/MissingBarOnTradingDayRule.php`, `ZeroOrMissingVolumeRule.php`, `StaleSeriesRule.php`
- Test: `tests/Feature/MarketData/Validation/Rules/MissingBarOnTradingDayRuleTest.php`, `ZeroOrMissingVolumeRuleTest.php`, `StaleSeriesRuleTest.php`

**Interfaces:**
- Consumes: `ValidationRule` z Tasku 11, `MarketDay` z Tasku 4, `Instrument` z Tasku 3
- Produces:
  - `AbstractStagingRule` — drží smyčku se stropem; potomek implementuje `query(string $table): string` a `detail(object $row): string`
  - Tři pravidla se severitou `WARNING`

- [ ] **Step 1: Vytáhnout smyčku se stropem do základní třídy**

`app/MarketData/Validation/Rules/AbstractStagingRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Data\ValidationFindingData;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

abstract class AbstractStagingRule implements ValidationRule
{
    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator
    {
        $emitted = 0;

        foreach (DB::cursor($this->query($stagingTable)) as $row) {
            if ($emitted === self::FINDING_CAP) {
                yield new ValidationFindingData(
                    instrumentId: null,
                    date: null,
                    detail: sprintf(
                        'Dosažen strop %d nálezů pravidla %s, další nezapsány',
                        self::FINDING_CAP,
                        $this->name(),
                    ),
                );

                return;
            }

            yield new ValidationFindingData(
                instrumentId: isset($row->instrument_id) ? (string) $row->instrument_id : null,
                date: isset($row->date) ? CarbonImmutable::parse((string) $row->date) : null,
                detail: $this->detail($row),
            );

            $emitted++;
        }
    }

    /** Dotaz MUSÍ končit `LIMIT self::FINDING_CAP + 1`, aby šlo poznat překročení stropu. */
    abstract protected function query(string $stagingTable): string;

    abstract protected function detail(object $row): string;
}
```

Přepoj na ni všechna tři pravidla z Tasku 11 — zůstane v nich jen `name()`, `severity()`, `query()` a `detail()`.

- [ ] **Step 2: Ověřit, že refactoring nic nerozbil**

Run: `vendor/bin/phpunit --filter='OhlcConsistencyRuleTest|DuplicateBarRuleTest|BarOnClosedDayRuleTest'`
Expected: PASS, stejný počet testů jako před refactoringem

- [ ] **Step 3: Commitnout refactoring samostatně**

```bash
git add app/MarketData/Validation
git commit -m "refactor: vytažení smyčky se stropem do AbstractStagingRule"
```

- [ ] **Step 4: Napsat failující test pro MissingBarOnTradingDayRule**

`tests/Feature/MarketData/Validation/Rules/MissingBarOnTradingDayRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\MissingBarOnTradingDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(MissingBarOnTradingDayRule::class)]
final class MissingBarOnTradingDayRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14']);
        $table = StagingFixture::withRows([
            $this->row('2019-03-13'),
            $this->row('2019-03-14'),
        ], self::INSTRUMENT);

        $this->assertSame([], iterator_to_array(new MissingBarOnTradingDayRule()->findings($table)));
    }

    public function testFindingsMissingDay(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14', '2019-03-15']);
        $table = StagingFixture::withRows([
            $this->row('2019-03-13'),
            $this->row('2019-03-15'),
        ], self::INSTRUMENT);

        $findings = iterator_to_array(new MissingBarOnTradingDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-14', $findings[0]->detail);
    }

    public function testFindingsDelistedBeforeGap(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14', '2019-03-15']);
        Instrument::query()->whereKey(self::INSTRUMENT)->update(['delisted_at' => '2019-03-13']);
        $table = StagingFixture::withRows([$this->row('2019-03-13')], self::INSTRUMENT);
        Instrument::query()->whereKey(self::INSTRUMENT)->update(['delisted_at' => '2019-03-13']);

        $this->assertSame([], iterator_to_array(new MissingBarOnTradingDayRule()->findings($table)));
    }

    /** @param array<int,string> $dates */
    private function calendar(array $dates): void
    {
        foreach ($dates as $date) {
            MarketDay::factory()->create(['date' => $date, 'is_open' => true]);
        }
    }

    /** @return array<string,mixed> */
    private function row(string $date): array
    {
        return ['symbol' => 'AAPL', 'date' => $date, 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10];
    }
}
```

`testFindingsDelistedBeforeGap` hlídá to podstatné: po delistingu **nesmí** chybějící bary hlásit, jinak by každý delistovaný ticker vygeneroval nález za každý zbývající obchodní den v historii.

- [ ] **Step 5: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=MissingBarOnTradingDayRuleTest`
Expected: FAIL — class not found

- [ ] **Step 6: Implementovat MissingBarOnTradingDayRule**

```php
protected function query(string $stagingTable): string
{
    return sprintf(
        'SELECT s.instrument_id, m.date FROM ('
        . '  SELECT instrument_id, min(date) AS first_date, max(date) AS last_date FROM %s'
        . '  GROUP BY instrument_id'
        . ') AS s '
        . 'JOIN instruments AS i ON i.id = s.instrument_id '
        . "JOIN market_days AS m ON m.exchange = 'XNYS' AND m.is_open = true "
        . '  AND m.date BETWEEN s.first_date AND s.last_date '
        . '  AND (i.delisted_at IS NULL OR m.date <= i.delisted_at) '
        . '  AND (i.listed_at IS NULL OR m.date >= i.listed_at) '
        . 'LEFT JOIN %s AS b ON b.instrument_id = s.instrument_id AND b.date = m.date '
        . 'WHERE b.date IS NULL '
        . 'ORDER BY s.instrument_id, m.date LIMIT %d',
        $stagingTable,
        $stagingTable,
        self::FINDING_CAP + 1,
    );
}

protected function detail(object $row): string
{
    return sprintf('Chybí bar k obchodnímu dni %s', (string) $row->date);
}
```

`name()` vrací `'MissingBarOnTradingDay'`, `severity()` vrací `FindingSeverityEnum::WARNING`.

Pravidlo hledá mezery **jen v rozsahu, který soubor pokrývá** (`BETWEEN first_date AND last_date` per instrument). Bez toho by inkrementální import jednoho dne hlásil chybějící bary za dvacet let historie.

- [ ] **Step 7: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=MissingBarOnTradingDayRuleTest`
Expected: PASS, 3 testy

- [ ] **Step 8: Napsat failující test pro ZeroOrMissingVolumeRule**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\ZeroOrMissingVolumeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(ZeroOrMissingVolumeRule::class)]
final class ZeroOrMissingVolumeRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 1000],
        ]);

        $this->assertSame([], iterator_to_array(new ZeroOrMissingVolumeRule()->findings($table)));
    }

    public function testFindingsZeroVolume(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 0],
        ]);

        $findings = iterator_to_array(new ZeroOrMissingVolumeRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('IEX', $findings[0]->detail);
    }
}
```

- [ ] **Step 9: Spustit test, ověřit selhání, implementovat**

Run: `vendor/bin/phpunit --filter=ZeroOrMissingVolumeRuleTest` → FAIL

```php
protected function query(string $stagingTable): string
{
    return sprintf(
        'SELECT s.instrument_id, s.date FROM %s AS s '
        . "JOIN market_days AS m ON m.exchange = 'XNYS' AND m.date = s.date AND m.is_open = true "
        . 'WHERE s.volume = 0 '
        . 'ORDER BY s.instrument_id, s.date LIMIT %d',
        $stagingTable,
        self::FINDING_CAP + 1,
    );
}

protected function detail(object $row): string
{
    return sprintf(
        'Nulový objem k %s v otevřený den — typický příznak IEX-only feedu',
        (string) $row->date,
    );
}
```

`name()` vrací `'ZeroOrMissingVolume'`, `severity()` vrací `FindingSeverityEnum::WARNING`.

- [ ] **Step 10: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=ZeroOrMissingVolumeRuleTest`
Expected: PASS, 2 testy

- [ ] **Step 11: Napsat failující test pro StaleSeriesRule**

```php
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
```

`CarbonImmutable::setTestNow` je tady nutné — pravidlo porovnává proti dnešku a bez fixního času by test za pět dní začal padat.

- [ ] **Step 12: Spustit test, ověřit selhání, implementovat**

Run: `vendor/bin/phpunit --filter=StaleSeriesRuleTest` → FAIL

`StaleSeriesRule` má konstruktor `public function __construct(private readonly int $staleAfterDays = 5)`.

```php
protected function query(string $stagingTable): string
{
    return sprintf(
        'SELECT s.instrument_id, max(s.date) AS date FROM %s AS s '
        . 'JOIN instruments AS i ON i.id = s.instrument_id AND i.delisted_at IS NULL '
        . 'GROUP BY s.instrument_id '
        . 'HAVING max(s.date) < ('
        . "  SELECT max(date) FROM market_days WHERE exchange = 'XNYS' AND is_open = true"
        . '   AND date <= %s) - INTERVAL \'%d days\' '
        . 'ORDER BY s.instrument_id LIMIT %d',
        $stagingTable,
        DB::getPdo()->quote(CarbonImmutable::now()->toDateString()),
        $this->staleAfterDays,
        self::FINDING_CAP + 1,
    );
}

protected function detail(object $row): string
{
    return sprintf(
        'Poslední bar k %s, ale instrument není delistovaný (práh %d dní)',
        (string) $row->date,
        $this->staleAfterDays,
    );
}
```

`name()` vrací `'StaleSeries'`, `severity()` vrací `FindingSeverityEnum::WARNING`.

- [ ] **Step 13: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='MissingBarOnTradingDayRuleTest|ZeroOrMissingVolumeRuleTest|StaleSeriesRuleTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: warning pravidla pro pokrytí a svěžest dat"
```

---

### Task 13: Pravidla porovnávající staging s uloženými daty

**Files:**
- Create: `app/MarketData/Validation/Rules/PriceJumpWithoutCorporateActionRule.php`, `CrossSourceDivergenceRule.php`
- Test: `tests/Feature/MarketData/Validation/Rules/PriceJumpWithoutCorporateActionRuleTest.php`, `CrossSourceDivergenceRuleTest.php`

**Interfaces:**
- Consumes: `AbstractStagingRule` z Tasku 12, `CorporateAction` z Tasku 6, `DailyBar` z Tasku 5
- Produces: dvě pravidla se severitou `WARNING`; `PriceJumpWithoutCorporateActionRule` má konstruktor `__construct(private readonly float $thresholdPct = 0.4)`, `CrossSourceDivergenceRule` má `__construct(private readonly float $thresholdPct = 0.01)`

- [ ] **Step 1: Napsat failující test pro PriceJumpWithoutCorporateActionRule**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Validation\Rules\PriceJumpWithoutCorporateActionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(PriceJumpWithoutCorporateActionRule::class)]
final class PriceJumpWithoutCorporateActionRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $table = $this->staged(100.0, 102.0);

        $this->assertSame([], iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table)));
    }

    public function testFindingsJumpWithoutAction(): void
    {
        $table = $this->staged(100.0, 25.0);

        $findings = iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-14', $findings[0]->detail);
    }

    public function testFindingsJumpWithSplit(): void
    {
        $table = $this->staged(100.0, 25.0);
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => '2019-03-14',
            'ratio' => 4.0,
        ]);

        $this->assertSame([], iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table)));
    }

    private function staged(float $firstClose, float $secondClose): string
    {
        return StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => $firstClose, 'high' => $firstClose,
                'low' => $firstClose, 'close' => $firstClose],
            ['symbol' => 'AAPL', 'date' => '2019-03-14', 'open' => $secondClose, 'high' => $secondClose,
                'low' => $secondClose, 'close' => $secondClose],
        ], self::INSTRUMENT);
    }
}
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=PriceJumpWithoutCorporateActionRuleTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat PriceJumpWithoutCorporateActionRule**

```php
protected function query(string $stagingTable): string
{
    return sprintf(
        'SELECT j.instrument_id, j.date, j.close, j.prev_close FROM ('
        . '  SELECT instrument_id, date, close,'
        . '    lag(close) OVER (PARTITION BY instrument_id ORDER BY date) AS prev_close'
        . '  FROM %s'
        . ') AS j '
        . 'LEFT JOIN corporate_actions AS ca ON ca.instrument_id = j.instrument_id AND ca.ex_date = j.date '
        . 'WHERE j.prev_close IS NOT NULL AND ca.id IS NULL '
        . '  AND abs(ln(j.close / j.prev_close)) > %F '
        . 'ORDER BY j.instrument_id, j.date LIMIT %d',
        $stagingTable,
        $this->thresholdPct,
        self::FINDING_CAP + 1,
    );
}

protected function detail(object $row): string
{
    return sprintf(
        'Skok ceny k %s bez corporate action: %s → %s',
        (string) $row->date,
        (string) $row->prev_close,
        (string) $row->close,
    );
}
```

`name()` vrací `'PriceJumpWithoutCorporateAction'`, `severity()` vrací `FindingSeverityEnum::WARNING`.

Práh je na **logaritmickém** výnosu, ne na procentu — logaritmus je symetrický, takže pokles na čtvrtinu a nárůst na čtyřnásobek dají stejnou absolutní hodnotu. S procentem by pravidlo hlásilo poklesy ochotněji než nárůsty.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=PriceJumpWithoutCorporateActionRuleTest`
Expected: PASS, 3 testy

- [ ] **Step 5: Napsat failující test pro CrossSourceDivergenceRule**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\DailyBar;
use App\MarketData\Validation\Rules\CrossSourceDivergenceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        DailyBar::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'date' => '2019-03-13',
            'close' => $storedClose,
            'source' => 'other',
        ]);

        return $table;
    }
}
```

`testFindingsNoOverlap` je ta věc, na kterou specifikace upozorňuje: bez překryvu dvou zdrojů pravidlo **nic nekontroluje** a nesmí se na něj v takovém případě spoléhat.

- [ ] **Step 6: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=CrossSourceDivergenceRuleTest`
Expected: FAIL — class not found

- [ ] **Step 7: Implementovat CrossSourceDivergenceRule**

```php
protected function query(string $stagingTable): string
{
    return sprintf(
        'SELECT s.instrument_id, s.date, s.close AS staged_close, b.close AS stored_close, b.source '
        . 'FROM %s AS s '
        . 'JOIN daily_bars AS b ON b.instrument_id = s.instrument_id AND b.date = s.date '
        . 'WHERE b.source <> \'\' AND abs(s.close - b.close) / b.close > %F '
        . 'ORDER BY s.instrument_id, s.date LIMIT %d',
        $stagingTable,
        $this->thresholdPct,
        self::FINDING_CAP + 1,
    );
}

protected function detail(object $row): string
{
    return sprintf(
        'Zdroje se rozcházejí k %s: nový %s vs. uložený %s (zdroj %s)',
        (string) $row->date,
        (string) $row->staged_close,
        (string) $row->stored_close,
        (string) $row->source,
    );
}
```

`name()` vrací `'CrossSourceDivergence'`, `severity()` vrací `FindingSeverityEnum::WARNING`.

- [ ] **Step 8: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='PriceJumpWithoutCorporateActionRuleTest|CrossSourceDivergenceRuleTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: pravidla na skok ceny a rozpor mezi zdroji"
```

---

### Task 14: ValidationRunner

**Files:**
- Create: `app/MarketData/Validation/ValidationRunner.php`
- Create: `app/MarketData/Validation/ValidationOutcome.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrace seznamu pravidel)
- Create: `app/MarketData/Console/ListValidationRulesCommand.php`
- Test: `tests/Feature/MarketData/Validation/ValidationRunnerTest.php`

**Interfaces:**
- Consumes: všechna pravidla z Tasků 11–13, `ValidationFinding` z Tasku 7
- Produces:
  - `ValidationRunner::run(string $stagingTable, string $runId): ValidationOutcome`
  - `ValidationOutcome` s `errorCount`, `warningCount`, `quarantinedInstrumentIds` (`array<int,string>`)
  - Command `market-data:list-validation-rules` — vypíše názvy a severity, aby bylo vždy vidět, co se kontroluje

- [ ] **Step 1: Napsat failující test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation;

use App\MarketData\Models\IngestRun;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\ValidationFinding;
use App\MarketData\Validation\ValidationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(ValidationRunner::class)]
final class ValidationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testRun(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ], self::INSTRUMENT);

        $outcome = app(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(0, $outcome->errorCount);
        $this->assertSame([], $outcome->quarantinedInstrumentIds);
    }

    public function testRunErrorQuarantinesInstrument(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 9, 'low' => 11, 'close' => 10],
        ], self::INSTRUMENT);

        $outcome = app(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(1, $outcome->errorCount);
        $this->assertSame([self::INSTRUMENT], $outcome->quarantinedInstrumentIds);
        $this->assertSame(1, ValidationFinding::query()->where('rule', 'OhlcConsistency')->count());
    }

    public function testRunWarningDoesNotQuarantine(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 0],
        ], self::INSTRUMENT);

        $outcome = app(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(0, $outcome->errorCount);
        $this->assertSame(1, $outcome->warningCount);
        $this->assertSame([], $outcome->quarantinedInstrumentIds);
    }
}
```

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=ValidationRunnerTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat ValidationOutcome a ValidationRunner**

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Validation;

final readonly class ValidationOutcome
{
    /** @param array<int,string> $quarantinedInstrumentIds */
    public function __construct(
        public int $errorCount,
        public int $warningCount,
        public array $quarantinedInstrumentIds,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Validation;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;

class ValidationRunner
{
    /** @param array<int,ValidationRule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public function run(string $stagingTable, string $runId): ValidationOutcome
    {
        $errors = 0;
        $warnings = 0;
        $quarantined = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->findings($stagingTable) as $finding) {
                ValidationFinding::query()->create([
                    'ingest_run_id' => $runId,
                    'instrument_id' => $finding->instrumentId,
                    'date' => $finding->date?->toDateString(),
                    'rule' => $rule->name(),
                    'severity' => $rule->severity(),
                    'detail' => $finding->detail,
                ]);

                if ($rule->severity() === FindingSeverityEnum::ERROR) {
                    $errors++;

                    if ($finding->instrumentId !== null) {
                        $quarantined[$finding->instrumentId] = true;
                    }

                    continue;
                }

                $warnings++;
            }
        }

        return new ValidationOutcome($errors, $warnings, array_keys($quarantined));
    }
}
```

Klíčové v tomto kódu je, co tam **není**: žádná exception. Nález je datový záznam, ne výjimečný stav — přesně jak předepisuje specifikace.

Registraci seznamu pravidel v kontejneru napiš podle Step 4 tohoto tasku — sdílí ji `ValidationRunner` i výpisový příkaz.

- [ ] **Step 4: Napsat command na výpis pravidel**

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Contracts\ValidationRule;
use Illuminate\Console\Command;

final class ListValidationRulesCommand extends Command
{
    protected $signature = 'market-data:list-validation-rules';

    protected $description = 'Vypíše validační pravidla a jejich severity';

    /** @param array<int,ValidationRule> $rules */
    public function handle(): int
    {
        /** @var array<int,ValidationRule> $rules */
        $rules = app('market-data.validation.rules');

        $this->table(
            ['Pravidlo', 'Severita'],
            array_map(
                fn (ValidationRule $rule): array => [$rule->name(), $rule->severity()->value],
                $rules,
            ),
        );

        return self::SUCCESS;
    }
}
```

Seznam pravidel musí existovat **na jednom místě**. Registruj ho v `AppServiceProvider::register` jako `market-data.validation.rules` a `ValidationRunner` ho z něj dostane injektovaný:

```php
$this->app->singleton('market-data.validation.rules', fn (): array => [
    new OhlcConsistencyRule(),
    new DuplicateBarRule(),
    new BarOnClosedDayRule(),
    new MissingBarOnTradingDayRule(),
    new PriceJumpWithoutCorporateActionRule(),
    new ZeroOrMissingVolumeRule(),
    new StaleSeriesRule(),
    new CrossSourceDivergenceRule(),
]);

$this->app->bind(
    ValidationRunner::class,
    fn (Application $app): ValidationRunner => new ValidationRunner($app->make('market-data.validation.rules')),
);
```

Tím `market-data:list-validation-rules` vypisuje přesně ten seznam, který `ValidationRunner` spouští — jinak by příkaz mohl tvrdit něco jiného, než se skutečně kontroluje.

- [ ] **Step 5: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=ValidationRunnerTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData app/Providers tests
git commit -m "feat: ValidationRunner s karanténou po instrumentu"
```

---

### Task 15: IngestPipeline a příkaz pro bulk import

Tady se skládá dohromady všechno z Tasků 8–14. Deliverable je funkční import dumpu.

**Files:**
- Create: `app/MarketData/Ingest/IngestPipeline.php`, `app/MarketData/Ingest/BarMerger.php`, `app/MarketData/Ingest/MergeResult.php`
- Create: `app/MarketData/Console/ImportBulkBarsCommand.php`
- Test: `tests/Feature/MarketData/Ingest/IngestPipelineTest.php`, `tests/Feature/MarketData/Console/ImportBulkBarsCommandTest.php`

**Interfaces:**
- Consumes: `BarSourcePort` (Task 8), `BulkFileRegistry` (8), `StagingTable` (9), `StagingResolver` (10), `ValidationRunner` (14), `IngestRun` (7)
- Produces:
  - `IngestPipeline::run(BarSourcePort $source, IngestModeEnum $mode, null|string $fileHash): IngestRun`
  - `MergeResult` s `inserted`, `updated`
  - Command `market-data:import-bulk {path} {--force}`

- [ ] **Step 1: Napsat failující test pipeline**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Ingest;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\IngestPipeline;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(IngestPipeline::class)]
final class IngestPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURE = __DIR__ . '/../../../fixtures/market-data/daily-sample.csv';

    public function testRun(): void
    {
        $this->seedCatalogue();

        $run = app(IngestPipeline::class)->run(
            new GenericOhlcvCsvSource(self::FIXTURE),
            IngestModeEnum::BULK,
            'hash-1',
        );

        $this->assertSame(IngestStatusEnum::COMPLETED, $run->status);
        $this->assertSame(4, $run->rows_inserted);
        $this->assertSame(4, DailyBar::query()->count());
    }

    public function testRunIdempotence(): void
    {
        $this->seedCatalogue();
        $source = new GenericOhlcvCsvSource(self::FIXTURE);

        app(IngestPipeline::class)->run($source, IngestModeEnum::BULK, 'hash-1');
        $second = app(IngestPipeline::class)->run($source, IngestModeEnum::BULK, 'hash-1');

        $this->assertSame(0, $second->rows_inserted);
        $this->assertSame(4, DailyBar::query()->count());
    }

    public function testRunUnknownSymbolQuarantined(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        MarketDay::factory()->create(['date' => '2019-03-14', 'is_open' => true]);
        $this->instrument('550e8400-e29b-41d4-a716-446655440000', 'AAPL');

        $run = app(IngestPipeline::class)->run(
            new GenericOhlcvCsvSource(self::FIXTURE),
            IngestModeEnum::BULK,
            'hash-1',
        );

        $this->assertSame(2, $run->rows_inserted);
        $this->assertSame(1, $run->findings()->where('rule', 'UnknownSymbol')->count());
    }

    private function seedCatalogue(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        MarketDay::factory()->create(['date' => '2019-03-14', 'is_open' => true]);
        $this->instrument('550e8400-e29b-41d4-a716-446655440000', 'AAPL');
        $this->instrument('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'XYZ');
    }

    private function instrument(string $id, string $symbol): void
    {
        $instrument = Instrument::factory()->create(['id' => $id]);
        $instrument->symbols()->create(['symbol' => $symbol, 'valid_from' => '2000-01-03', 'valid_to' => null]);
    }
}
```

`testRunUnknownSymbolQuarantined` ověřuje to nejdůležitější chování celé pipeline: jeden nenapárovaný ticker **neshodí import ostatních**.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=IngestPipelineTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat IngestPipeline**

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Contracts\BarSourcePort;
use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use App\MarketData\Validation\ValidationRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class IngestPipeline
{
    public function __construct(
        private readonly StagingTable $staging,
        private readonly StagingResolver $resolver,
        private readonly ValidationRunner $validation,
        private readonly BarMerger $merger,
        private readonly Bulk\BulkFileRegistry $registry,
    ) {
    }

    public function run(BarSourcePort $source, IngestModeEnum $mode, null|string $fileHash): IngestRun
    {
        if ($fileHash !== null && $this->registry->alreadyImported($fileHash) === true) {
            return $this->skippedRun($source, $mode, $fileHash);
        }

        $run = IngestRun::query()->create([
            'source' => $source->name(),
            'mode' => $mode,
            'file_hash' => $fileHash,
            'started_at' => CarbonImmutable::now(),
            'status' => IngestStatusEnum::RUNNING,
        ]);

        $table = $this->staging->create($run->id);

        try {
            $this->staging->write($table, $source->dailyBars());
            $this->resolver->resolve($table);
            $this->resolver->quarantine($table, $run->id);

            $outcome = $this->validation->run($table, $run->id);
            $merged = $this->merger->merge($table, $outcome->quarantinedInstrumentIds, $source->name());

            $run->update([
                'rows_inserted' => $merged->inserted,
                'rows_updated' => $merged->updated,
                'status' => IngestStatusEnum::COMPLETED,
                'finished_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => IngestStatusEnum::FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => CarbonImmutable::now(),
            ]);

            throw $exception;
        } finally {
            $this->staging->drop($table);
        }

        return $run->fresh() ?? $run;
    }

    private function skippedRun(BarSourcePort $source, IngestModeEnum $mode, string $fileHash): IngestRun
    {
        return IngestRun::query()->create([
            'source' => $source->name(),
            'mode' => $mode,
            'file_hash' => $fileHash,
            'started_at' => CarbonImmutable::now(),
            'finished_at' => CarbonImmutable::now(),
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'status' => IngestStatusEnum::COMPLETED,
            'error' => null,
        ]);
    }
}
```

`finally` s `drop` je tam proto, že staging tabulky jsou reálné tabulky v databázi — spadlý import by je jinak nechal ležet a po pár týdnech by jich bylo sto.

- [ ] **Step 4: Implementovat BarMerger**

`app/MarketData/Ingest/BarMerger.php`:

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use Illuminate\Support\Facades\DB;

class BarMerger
{
    /** @param array<int,string> $excludedInstrumentIds */
    public function merge(string $stagingTable, array $excludedInstrumentIds, string $source): MergeResult
    {
        $exclusion = empty($excludedInstrumentIds) === true
            ? ''
            : sprintf(
                'AND s.instrument_id NOT IN (%s)',
                implode(',', array_map(
                    fn (string $id): string => DB::getPdo()->quote($id),
                    $excludedInstrumentIds,
                )),
            );

        $before = (int) DB::scalar('SELECT count(*) FROM daily_bars');

        DB::statement(sprintf(
            'INSERT INTO daily_bars '
            . '(instrument_id, date, open, high, low, close, volume, source, ingested_at) '
            . 'SELECT s.instrument_id, s.date, s.open, s.high, s.low, s.close, s.volume, %s, now() '
            . 'FROM %s AS s WHERE s.instrument_id IS NOT NULL %s '
            . 'ON CONFLICT (instrument_id, date) DO UPDATE SET '
            . 'open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low, '
            . 'close = EXCLUDED.close, volume = EXCLUDED.volume, '
            . 'source = EXCLUDED.source, ingested_at = EXCLUDED.ingested_at',
            DB::getPdo()->quote($source),
            $stagingTable,
            $exclusion,
        ));

        $after = (int) DB::scalar('SELECT count(*) FROM daily_bars');
        $affected = (int) DB::scalar(sprintf(
            'SELECT count(*) FROM %s WHERE instrument_id IS NOT NULL',
            $stagingTable,
        ));

        return new MergeResult(inserted: $after - $before, updated: $affected - ($after - $before));
    }
}
```

`MergeResult` je `final readonly` s `public int $inserted` a `public int $updated`.

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=IngestPipelineTest`
Expected: PASS, 3 testy

- [ ] **Step 6: Napsat příkaz a jeho test**

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Ingest\Bulk\BulkFileRegistry;
use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\IngestPipeline;
use Illuminate\Console\Command;

final class ImportBulkBarsCommand extends Command
{
    protected $signature = 'market-data:import-bulk {path} {--force}';

    protected $description = 'Naimportuje denní bary z CSV dumpu';

    public function handle(IngestPipeline $pipeline, BulkFileRegistry $registry): int
    {
        $path = (string) $this->argument('path');

        if (is_readable($path) === false) {
            $this->error(sprintf('Soubor %s nelze přečíst.', $path));

            return self::FAILURE;
        }

        $hash = $this->option('force') === true ? null : $registry->hash($path);
        $run = $pipeline->run(new GenericOhlcvCsvSource($path), IngestModeEnum::BULK, $hash);

        $this->info(sprintf(
            'Běh %s: vloženo %d, aktualizováno %d, nálezů %d.',
            $run->id,
            $run->rows_inserted,
            $run->rows_updated,
            $run->findings()->count(),
        ));

        return self::SUCCESS;
    }
}
```

Test příkazu ověří tři věci: úspěšný import, nečitelný soubor vrátí `FAILURE`, a druhé spuštění bez `--force` nevloží nic.

- [ ] **Step 7: Spustit testy, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='IngestPipelineTest|ImportBulkBarsCommandTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: ingest pipeline a příkaz pro bulk import"
```

---

### Task 16: Inkrementální ingest z Alpaky

**Files:**
- Create: `app/MarketData/Ingest/Incremental/AlpacaBarSource.php`, `ProviderRateLimiter.php`
- Create: `app/MarketData/Console/ImportIncrementalBarsCommand.php`
- Modify: `routes/console.php` (scheduler)
- Test: `tests/Unit/MarketData/Ingest/Incremental/AlpacaBarSourceTest.php`, `ProviderRateLimiterTest.php`, `tests/Feature/MarketData/Console/ImportIncrementalBarsCommandTest.php`

**Interfaces:**
- Consumes: `BarSourcePort` (Task 8), `IngestPipeline` (15), `MarketDay` (4)
- Produces:
  - `AlpacaBarSource` — konstruktor bere seznam symbolů a rozsah dat; implementuje `BarSourcePort`; stránkuje přes `next_page_token`
  - `ProviderRateLimiter::throttle(string $key, int $perMinute, Closure $callback): mixed`
  - Command `market-data:import-incremental {--from=} {--to=}` s lockem

- [ ] **Step 1: Napsat failující test zdroje včetně stránkování**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Incremental;

use App\MarketData\Ingest\Incremental\AlpacaBarSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlpacaBarSource::class)]
final class AlpacaBarSourceTest extends TestCase
{
    public function testDailyBars(): void
    {
        Http::fake([
            '*/v2/stocks/bars*' => Http::response([
                'bars' => [
                    'AAPL' => [
                        ['t' => '2019-03-13T04:00:00Z', 'o' => 182.25, 'h' => 183.3,
                            'l' => 181.46, 'c' => 181.71, 'v' => 31032530],
                    ],
                ],
                'next_page_token' => null,
            ]),
        ]);

        $bars = iterator_to_array(
            new AlpacaBarSource(
                symbols: ['AAPL'],
                from: CarbonImmutable::parse('2019-03-13'),
                to: CarbonImmutable::parse('2019-03-13'),
                baseUrl: 'https://data.alpaca.markets',
                keyId: 'k',
                secretKey: 's',
                feed: 'iex',
            )->dailyBars(),
        );

        $this->assertCount(1, $bars);
        $this->assertSame('AAPL', $bars[0]->symbol);
        $this->assertSame('2019-03-13', $bars[0]->date->toDateString());
    }

    public function testDailyBarsPagination(): void
    {
        Http::fakeSequence()
            ->push(['bars' => ['AAPL' => [['t' => '2019-03-13T04:00:00Z', 'o' => 1, 'h' => 1,
                'l' => 1, 'c' => 1, 'v' => 1]]], 'next_page_token' => 'tok'])
            ->push(['bars' => ['AAPL' => [['t' => '2019-03-14T04:00:00Z', 'o' => 1, 'h' => 1,
                'l' => 1, 'c' => 1, 'v' => 1]]], 'next_page_token' => null]);

        $bars = iterator_to_array($this->source()->dailyBars());

        $this->assertCount(2, $bars);
    }

    public function testDailyBarsEmptyResponse(): void
    {
        Http::fake(['*/v2/stocks/bars*' => Http::response(['bars' => [], 'next_page_token' => null])]);

        $this->assertSame([], iterator_to_array($this->source()->dailyBars()));
    }

    private function source(): AlpacaBarSource
    {
        return new AlpacaBarSource(
            symbols: ['AAPL'],
            from: CarbonImmutable::parse('2019-03-13'),
            to: CarbonImmutable::parse('2019-03-14'),
            baseUrl: 'https://data.alpaca.markets',
            keyId: 'k',
            secretKey: 's',
            feed: 'iex',
        );
    }
}
```

Test na stránkování je povinný — bez něj by se při 1500 symbolech tiše naimportoval jen první stránka a nikdo by to nepoznal.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=AlpacaBarSourceTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat AlpacaBarSource**

Podstatné části implementace:

```php
/** @return Generator<int,BarData> */
public function dailyBars(): Generator
{
    $token = null;

    do {
        $response = Http::withHeaders([
            'APCA-API-KEY-ID' => $this->keyId,
            'APCA-API-SECRET-KEY' => $this->secretKey,
        ])->get($this->baseUrl . '/v2/stocks/bars', array_filter([
            'symbols' => implode(',', $this->symbols),
            'timeframe' => '1Day',
            'start' => $this->from->toDateString(),
            'end' => $this->to->toDateString(),
            'adjustment' => 'raw',
            'feed' => $this->feed,
            'limit' => 10000,
            'page_token' => $token,
        ], fn (mixed $value): bool => $value !== null))->throw();

        /** @var array{bars:array<string,array<int,array<string,mixed>>>,next_page_token:null|string} $payload */
        $payload = $response->json();

        foreach ($payload['bars'] as $symbol => $rows) {
            foreach ($rows as $row) {
                yield BarData::from([
                    'symbol' => $symbol,
                    'date' => CarbonImmutable::parse((string) $row['t'])->toDateString(),
                    'open' => (float) $row['o'],
                    'high' => (float) $row['h'],
                    'low' => (float) $row['l'],
                    'close' => (float) $row['c'],
                    'volume' => (int) $row['v'],
                    'ts' => null,
                ]);
            }
        }

        $token = $payload['next_page_token'];
    } while ($token !== null);
}
```

`adjustment=raw` je zásadní: sklad ukládá **neupravené** ceny a adjustment se počítá z corporate actions. Kdyby zdroj vracel upravené hodnoty, dvakrát by se aplikoval.

`name()` vrací `'alpaca:bars'`.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=AlpacaBarSourceTest`
Expected: PASS, 3 testy

- [ ] **Step 5: Napsat test a implementaci ProviderRateLimiter**

Test ověří, že po vyčerpání limitu se volání odloží (přes `RateLimiter::attempt` a `Cache` v testovacím store), a že pod limitem projde bez čekání.

```php
<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Incremental;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

class ProviderRateLimiter
{
    private const int SLEEP_MICROSECONDS = 200000;

    public function throttle(string $key, int $perMinute, Closure $callback): mixed
    {
        while (RateLimiter::remaining($key, $perMinute) <= 0) {
            usleep(self::SLEEP_MICROSECONDS);
        }

        RateLimiter::hit($key, 60);

        return $callback();
    }
}
```

- [ ] **Step 6: Napsat příkaz s lockem a zařadit ho do scheduleru**

```php
public function handle(IngestPipeline $pipeline): int
{
    $lock = Cache::lock('market-data:ingest:incremental', 3600);

    if ($lock->get() === false) {
        $this->warn('Inkrementální ingest už běží, přeskakuji.');

        return self::SUCCESS;
    }

    try {
        $to = CarbonImmutable::parse((string) ($this->option('to') ?? CarbonImmutable::now()->toDateString()));
        $from = CarbonImmutable::parse((string) ($this->option('from') ?? $to->subDays(5)->toDateString()));

        if (MarketDay::isTradingDay('XNYS', $to) === false) {
            $this->info('Dnes nebyl obchodní den, nic k importu.');

            return self::SUCCESS;
        }

        // symboly = aktuální členové univerza; do doby, než existuje Task 20,
        // se berou všechny instrumenty bez delisted_at
        $symbols = InstrumentSymbol::query()
            ->whereNull('valid_to')
            ->get()
            ->pluck(fn (InstrumentSymbol $symbol): string => $symbol->symbol)
            ->all();

        $run = $pipeline->run(
            new AlpacaBarSource(/* ... */),
            IngestModeEnum::INCREMENTAL,
            null,
        );

        $this->info(sprintf('Běh %s: vloženo %d, aktualizováno %d.', $run->id, $run->rows_inserted, $run->rows_updated));

        return self::SUCCESS;
    } finally {
        $lock->release();
    }
}
```

`Cache::lock` nad `database` storem je to, co v tomto plánu zastupuje Redis — chování je stejné, jen bez další služby.

Scheduler v `routes/console.php`:

```php
Schedule::command('market-data:import-incremental')->dailyAt('23:00')->withoutOverlapping();
Schedule::command('market-data:ensure-partitions')->yearlyOn(12, 1, '00:00');
```

Čas `23:00` je zde provizorní — od podprojektu 6 se cyklus řídí burzovním kalendářem, ne pevnou hodinou. Poznámka v kódu na to musí upozorňovat.

- [ ] **Step 7: Spustit testy, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='AlpacaBarSourceTest|ProviderRateLimiterTest|ImportIncrementalBarsCommandTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData routes tests
git commit -m "feat: inkrementální ingest z Alpaky s rate limitem a lockem"
```

---

### Task 17: Kanonický fixture seeder

**Files:**
- Create: `database/seeders/CanonicalFixtureSeeder.php`
- Test: `tests/Feature/Database/CanonicalFixtureSeederTest.php`

**Interfaces:**
- Consumes: všechny modely z Tasků 3–7
- Produces: `CanonicalFixtureSeeder` — 5 instrumentů × ~60 obchodních dní s po jedné z každé pasti; použitelný v testech i pro ruční zkoumání

Fixture obsahuje: instrument delistovaný v polovině období; instrument, který se stane likvidním až v průběhu; ticker recyklovaný mezi dvěma instrumenty; jeden split a jednu dividendu; jednu mezeru v datech; jeden bar porušující OHLC invariant; jeden svátek a jeden zkrácený obchodní den.

- [ ] **Step 1: Napsat failující test**

Test ověří přítomnost každé pasti jedním konkrétním tvrzením — například že existuje instrument s `delisted_at` uvnitř období, že existují dva instrumenty se stejným symbolem v nepřekrývajících se intervalech, že v kalendáři je jeden `is_open = false` den a jeden `is_early_close = true`.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=CanonicalFixtureSeederTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat seeder**

Deterministicky, s pevnými UUID a pevnými datumy — **žádný `faker` pro strukturu**, faker jen pro cenové hodnoty, a i ty se seedují pevným seedem, aby byl fixture reprodukovatelný.

- [ ] **Step 4: Spustit test, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=CanonicalFixtureSeederTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add database tests
git commit -m "test: kanonický fixture se všemi datovými pastmi"
```

---

# Etapa 1b — adjustment, univerzum, export

### Task 18: Adjustment faktory

**Files:**
- Create: `database/migrations/2026_08_06_000900_create_adjustment_factors_table.php`
- Create: `app/MarketData/Models/AdjustmentFactor.php`
- Create: `app/MarketData/Adjustment/AdjustmentFactorCalculator.php`
- Create: `app/MarketData/Console/RecalculateAdjustmentsCommand.php`
- Test: `tests/Feature/MarketData/Adjustment/AdjustmentFactorCalculatorTest.php`

**Interfaces:**
- Consumes: `CorporateAction` (Task 6), `DailyBar` (5)
- Produces:
  - `AdjustmentFactorCalculator::recalculate(string $instrumentId): int` — smaže a přepočítá **všechny** faktory instrumentu, vrátí počet zapsaných řádků
  - Command `market-data:recalculate-adjustments {--instrument=}`

**Vzorce** (bez nich není task zadání):

```
cum_split_factor(d) = Π ratio_i        pro všechny splity s ex_date > d
cum_div_factor(d)   = Π (1 − amount_i / close(den před ex_date_i))
                                      pro všechny dividendy s ex_date > d

adj_price(d)  = raw_price(d)  / cum_split_factor(d) × cum_div_factor(d)
adj_volume(d) = raw_volume(d) × cum_split_factor(d)
```

Ukládají se **jen řádky, kde se aspoň jeden faktor liší od 1.** Materializovat faktor pro každý (instrument, den) by znamenalo druhou stomilionovou tabulku; čtení používá `LEFT JOIN` s `COALESCE(..., 1)`.

- [ ] **Step 1: Napsat migraci**

```php
Schema::create('adjustment_factors', function (Blueprint $table): void {
    $table->uuid('instrument_id');
    $table->date('date');
    $table->decimal('cum_split_factor', 20, 10)->default(1);
    $table->decimal('cum_div_factor', 20, 10)->default(1);

    $table->primary(['instrument_id', 'date']);
    $table->index('instrument_id');
    $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
});
```

- [ ] **Step 2: Napsat golden test na split**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Adjustment;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\AdjustmentFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AdjustmentFactorCalculator::class)]
final class AdjustmentFactorCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testRecalculateNoActions(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);

        $this->assertSame(0, app(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT));
    }

    public function testRecalculateSplitOnly(): void
    {
        $this->bars(['2020-08-27' => 500.0, '2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);

        app(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $before = AdjustmentFactor::query()->where('date', '2020-08-28')->firstOrFail();
        $this->assertEqualsWithDelta(4.0, (float) $before->cum_split_factor, 0.0000001);
        $this->assertNull(AdjustmentFactor::query()->where('date', '2020-08-31')->first());
    }

    public function testRecalculateSplitContinuity(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);

        app(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $adjustedBefore = 500.0 / 4.0;
        $adjustedAfter = 125.0;

        $this->assertEqualsWithDelta(0.0, log($adjustedAfter / $adjustedBefore), 0.0000001);
    }

    public function testRecalculateSplitAndDividend(): void
    {
        $this->bars(['2020-08-27' => 500.0, '2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::DIVIDEND,
            'ex_date' => '2020-08-28',
            'ratio' => null,
            'amount' => 5.0,
        ]);

        app(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $row = AdjustmentFactor::query()->where('date', '2020-08-27')->firstOrFail();
        $this->assertEqualsWithDelta(4.0, (float) $row->cum_split_factor, 0.0000001);
        $this->assertEqualsWithDelta(1.0 - 5.0 / 500.0, (float) $row->cum_div_factor, 0.0000001);
    }

    public function testRecalculateIdempotence(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);
        $calculator = app(AdjustmentFactorCalculator::class);

        $first = $calculator->recalculate(self::INSTRUMENT);
        $second = $calculator->recalculate(self::INSTRUMENT);

        $this->assertSame($first, $second);
        $this->assertSame($first, AdjustmentFactor::query()->count());
    }

    /** @param array<string,float> $closes */
    private function bars(array $closes): void
    {
        Instrument::factory()->create(['id' => self::INSTRUMENT]);

        foreach ($closes as $date => $close) {
            DailyBar::factory()->create([
                'instrument_id' => self::INSTRUMENT,
                'date' => $date,
                'open' => $close,
                'high' => $close,
                'low' => $close,
                'close' => $close,
            ]);
        }
    }

    private function split(string $exDate, float $ratio): void
    {
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => $exDate,
            'ratio' => $ratio,
        ]);
    }
}
```

`testRecalculateSplitContinuity` je ten golden test, který specifikace vyžaduje: upravená řada nesmí mít v den ex-date nespojitost. Kdyby se faktor aplikoval obráceně (násobil místo dělil), tenhle test spadne, zatímco všechny ostatní by prošly.

- [ ] **Step 3: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=AdjustmentFactorCalculatorTest`
Expected: FAIL — class not found

- [ ] **Step 4: Implementovat AdjustmentFactorCalculator**

Postup je vždy **celý přepočet** pro instrument, nikdy inkrementální — pro jeden instrument jsou to stovky corporate actions, takže je to levné, a inkrementální dopočítávání kumulativních koeficientů je místo, kde vznikají tiché chyby.

```php
public function recalculate(string $instrumentId): int
{
    return DB::transaction(function () use ($instrumentId): int {
        AdjustmentFactor::query()->where('instrument_id', $instrumentId)->delete();

        /** @var Collection<int,CorporateAction> $actions */
        $actions = CorporateAction::query()
            ->where('instrument_id', $instrumentId)
            ->whereIn('type', [CorporateActionTypeEnum::SPLIT, CorporateActionTypeEnum::DIVIDEND])
            ->orderByDesc('ex_date')
            ->get();

        if ($actions->isEmpty() === true) {
            return 0;
        }

        // pro každou akci potřebujeme close dne PŘED ex_date (kvůli dividendě)
        // jde se od nejnovější akce k nejstarší a kumuluje se
        // výsledkem je seznam (datum_od, datum_do, split_faktor, div_faktor)
        // a zapisují se jen dny, kde je aspoň jeden faktor != 1

        // ... implementace podle vzorců v hlavičce tasku
    });
}
```

Zápis probíhá po intervalech, ne po dnech: mezi dvěma po sobě jdoucími corporate actions je faktor konstantní, takže se `INSERT ... SELECT` nad `daily_bars` v daném rozsahu provede jednou na interval.

- [ ] **Step 5: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=AdjustmentFactorCalculatorTest`
Expected: PASS, 5 testů

- [ ] **Step 6: Napsat command, statická analýza, code style, commit**

`market-data:recalculate-adjustments` bez `--instrument` přepočítá všechny instrumenty, které mají aspoň jednu corporate action; s `--instrument` jeden.

```bash
vendor/bin/phpunit --filter=AdjustmentFactorCalculatorTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database tests
git commit -m "feat: přepočet adjustment faktorů z corporate actions"
```

---

### Task 19: Tabulky univerza

**Files:**
- Create: `database/migrations/2026_08_06_001000_create_universe_definitions_table.php`, `..._001100_create_universe_members_table.php`
- Create: `app/MarketData/Models/UniverseDefinition.php`, `UniverseMember.php`
- Create: `app/MarketData/Data/UniverseRulesData.php`
- Create: `database/factories/UniverseDefinitionFactory.php`
- Test: `tests/Unit/MarketData/Data/UniverseRulesDataTest.php`

**Interfaces:**
- Produces:
  - `UniverseRulesData` s `minPrice`, `minAvgDollarVolume`, `dollarVolumeLookbackDays`; má `fake()`
  - `UniverseDefinition` model s `name`, `version`, `rules` (cast na `UniverseRulesData`), unique `(name, version)`
  - `UniverseMember` model, PK `(definition_id, date, instrument_id)`

- [ ] **Step 1: Napsat migrace**

```php
Schema::create('universe_definitions', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name', 64);
    $table->unsignedInteger('version');
    $table->json('rules');
    $table->timestamps();

    $table->unique(['name', 'version']);
});

Schema::create('universe_members', function (Blueprint $table): void {
    $table->uuid('definition_id');
    $table->date('date');
    $table->uuid('instrument_id');

    $table->primary(['definition_id', 'date', 'instrument_id']);
    $table->index(['definition_id', 'date']);
    $table->index('instrument_id');
    $table->foreign('definition_id')->references('id')->on('universe_definitions')->cascadeOnDelete();
    $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
});
```

- [ ] **Step 2: Napsat test pro UniverseRulesData**

Test ověří `fake()` s přepsanými hodnotami a to, že `UniverseDefinition::$rules` se castuje na `UniverseRulesData`, ne na pole.

- [ ] **Step 3: Spustit test, ověřit selhání, implementovat, ověřit zelenou**

Run: `vendor/bin/phpunit --filter=UniverseRulesDataTest`

```php
final class UniverseRulesData extends Data
{
    public function __construct(
        public readonly float $minPrice,
        public readonly float $minAvgDollarVolume,
        public readonly int $dollarVolumeLookbackDays,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        return self::from([
            'minPrice' => 5.0,
            'minAvgDollarVolume' => 5_000_000.0,
            'dollarVolumeLookbackDays' => 20,
            ...$attributes,
        ]);
    }
}
```

Cast na modelu: `'rules' => UniverseRulesData::class` (`spatie/laravel-data` to umí přes svůj Eloquent cast).

- [ ] **Step 4: Statická analýza, code style, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database tests
git commit -m "feat: verzované definice univerza a tabulka členství"
```

---

### Task 20: Point-in-time členství v univerzu

Nejdůležitější task celé etapy 1b — tady se rozhoduje, jestli budou backtesty čestné.

**Files:**
- Create: `app/MarketData/Universe/UniverseMemberResolver.php`
- Create: `app/MarketData/Console/RebuildUniverseCommand.php`
- Test: `tests/Feature/MarketData/Universe/UniverseMemberResolverTest.php`

**Interfaces:**
- Consumes: `UniverseDefinition` (Task 19), `DailyBar` (5), `MarketDay` (4), `Instrument` (3)
- Produces:
  - `UniverseMemberResolver::rebuild(UniverseDefinition $definition, CarbonImmutable $from, CarbonImmutable $to): int`
  - `UniverseMemberResolver::membersAt(UniverseDefinition $definition, CarbonImmutable $date): Collection<int,string>`
  - Command `market-data:rebuild-universe {name} {version} {--from=} {--to=}`

**Pravidlo členství k datu D:** instrument je členem, pokud
- `listed_at <= D` a (`delisted_at IS NULL` nebo `delisted_at >= D`),
- `close(D) >= minPrice`,
- průměr `close × volume` za posledních `dollarVolumeLookbackDays` **obchodních** dní končících D je `>= minAvgDollarVolume`.

Všechny tři podmínky se vyhodnocují **jen z dat s datem ≤ D**.

- [ ] **Step 1: Napsat failující test včetně testu na look-ahead**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Universe;

use App\MarketData\Data\UniverseRulesData;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\UniverseDefinition;
use App\MarketData\Universe\UniverseMemberResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(UniverseMemberResolver::class)]
final class UniverseMemberResolverTest extends TestCase
{
    use RefreshDatabase;

    private const string LIQUID = '550e8400-e29b-41d4-a716-446655440000';
    private const string PENNY = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    private const string DELISTED = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    public function testRebuild(): void
    {
        $definition = $this->definition();
        $this->scenario();

        app(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse('2019-02-01'),
            CarbonImmutable::parse('2019-03-29'),
        );

        $members = app(UniverseMemberResolver::class)
            ->membersAt($definition, CarbonImmutable::parse('2019-03-15'));

        $this->assertTrue($members->contains(self::LIQUID));
        $this->assertFalse($members->contains(self::PENNY));
    }

    public function testMembersAtDelistedInstrument(): void
    {
        $definition = $this->definition();
        $this->scenario();
        app(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse('2019-02-01'),
            CarbonImmutable::parse('2019-03-29'),
        );

        $resolver = app(UniverseMemberResolver::class);

        $this->assertTrue(
            $resolver->membersAt($definition, CarbonImmutable::parse('2019-02-15'))->contains(self::DELISTED),
            'Delistovaný instrument MUSÍ být členem k datům před delistingem — jinak je to survivorship bias.',
        );
        $this->assertFalse(
            $resolver->membersAt($definition, CarbonImmutable::parse('2019-03-15'))->contains(self::DELISTED),
        );
    }

    public function testRebuildTruncatedHistory(): void
    {
        $cutoff = CarbonImmutable::parse('2019-03-15');
        $definition = $this->definition();
        $this->scenario();

        app(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse('2019-02-01'),
            CarbonImmutable::parse('2019-03-29'),
        );
        $full = app(UniverseMemberResolver::class)->membersAt($definition, $cutoff)->sort()->values();

        DailyBar::query()->where('date', '>', $cutoff->toDateString())->delete();
        $truncated = $this->definition(version: 2);
        app(UniverseMemberResolver::class)->rebuild(
            $truncated,
            CarbonImmutable::parse('2019-02-01'),
            $cutoff,
        );
        $partial = app(UniverseMemberResolver::class)->membersAt($truncated, $cutoff)->sort()->values();

        $this->assertSame($full->all(), $partial->all());
    }

    public function testRebuildAppendOnly(): void
    {
        $definition = $this->definition();
        $this->scenario();
        $resolver = app(UniverseMemberResolver::class);

        $first = $resolver->rebuild($definition, CarbonImmutable::parse('2019-02-01'), CarbonImmutable::parse('2019-03-29'));
        $second = $resolver->rebuild($definition, CarbonImmutable::parse('2019-02-01'), CarbonImmutable::parse('2019-03-29'));

        $this->assertSame($first, $second);
    }

    private function definition(int $version = 1): UniverseDefinition
    {
        return UniverseDefinition::query()->create([
            'name' => 'liquid_us',
            'version' => $version,
            'rules' => UniverseRulesData::fake([
                'minPrice' => 5.0,
                'minAvgDollarVolume' => 1_000_000.0,
                'dollarVolumeLookbackDays' => 5,
            ]),
        ]);
    }

    private function scenario(): void
    {
        // kalendář: všechny pracovní dny února a března 2019
        // LIQUID:   close 100, volume 1M   → dollar volume 100M, člen po celé období
        // PENNY:    close 2,   volume 1M   → pod minPrice, nikdy člen
        // DELISTED: close 100, volume 1M, delisted_at 2019-02-28
        //           → člen do 28. 2., pak ne
    }
}
```

`testRebuildTruncatedHistory` je ten test, o kterém specifikace mluví jako o jádru celé sady: **členství k datu D spočítané nad daty, ve kterých budoucnost fyzicky není, se musí rovnat členství nad plnými daty.** Když se to nerovná, implementace se dívá dopředu — a žádné čtení kódu to nezjistí spolehlivěji.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=UniverseMemberResolverTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat UniverseMemberResolver**

Množinově, jedním `INSERT ... SELECT` s window funkcí pro klouzavý dollar volume:

```php
public function rebuild(UniverseDefinition $definition, CarbonImmutable $from, CarbonImmutable $to): int
{
    $rules = $definition->rules;

    return DB::affectingStatement(
        'INSERT INTO universe_members (definition_id, date, instrument_id) '
        . 'SELECT ?, c.date, c.instrument_id FROM ('
        . '  SELECT b.instrument_id, b.date, b.close,'
        . '    avg(b.close * b.volume) OVER ('
        . '      PARTITION BY b.instrument_id ORDER BY b.date'
        . '      ROWS BETWEEN ? PRECEDING AND CURRENT ROW'
        . '    ) AS avg_dollar_volume'
        . '  FROM daily_bars AS b'
        . '  JOIN instruments AS i ON i.id = b.instrument_id'
        . '  WHERE b.date <= ?'
        . '    AND (i.listed_at IS NULL OR i.listed_at <= b.date)'
        . '    AND (i.delisted_at IS NULL OR i.delisted_at >= b.date)'
        . ') AS c '
        . 'WHERE c.date >= ? AND c.close >= ? AND c.avg_dollar_volume >= ? '
        . 'ON CONFLICT (definition_id, date, instrument_id) DO NOTHING',
        [
            $definition->id,
            $rules->dollarVolumeLookbackDays - 1,
            $to->toDateString(),
            $from->toDateString(),
            $rules->minPrice,
            $rules->minAvgDollarVolume,
        ],
    );
}
```

Dvě věci, které dělají tenhle dotaz kauzálním. **`ROWS BETWEEN n PRECEDING AND CURRENT ROW`** — okno se nikdy nedívá dopředu; `FOLLOWING` by bylo přesně ta chyba, kterou test na zkrácená data hledá. **`WHERE b.date <= $to` je v podzapytí, ne vně** — kdyby bylo vně, klouzavý průměr by se počítal i z barů po `$to` a promítl by budoucnost do minulosti.

`ON CONFLICT DO NOTHING` zajišťuje append-only chování z specifikace: opakovaný přepočet pro tutéž verzi definice nic nepřepíše.

- [ ] **Step 4: Spustit test a ověřit zelenou**

Run: `vendor/bin/phpunit --filter=UniverseMemberResolverTest`
Expected: PASS, 4 testy

- [ ] **Step 5: Napsat command, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=UniverseMemberResolverTest
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: point-in-time členství v univerzu bez survivorship bias"
```

---

### Task 21: Parquet export a kontraktní test

**Files:**
- Create: `database/migrations/2026_08_06_001200_create_daily_bars_adjusted_view.php`
- Create: `research/export_parquet.py`, `research/tests/test_parquet_contract.py`, `research/pyproject.toml`
- Create: `app/MarketData/Export/ParquetExporter.php`
- Create: `app/MarketData/Console/ExportParquetCommand.php`
- Test: `tests/Feature/MarketData/Export/ParquetExporterTest.php`

**Interfaces:**
- Consumes: `daily_bars` (Task 5), `adjustment_factors` (18)
- Produces:
  - Postgres view `daily_bars_adjusted` — **jediné místo, kde žije aplikace adjustment vzorce**
  - `ParquetExporter::exportYear(int $year): string` — vrátí cestu k zapsanému souboru
  - Command `market-data:export-parquet {--year=}`
  - Python `export_parquet.py --year N --out PATH --dsn DSN`

- [ ] **Step 1: Vytvořit view s aplikací faktorů**

```php
DB::statement(<<<'SQL'
    CREATE VIEW daily_bars_adjusted AS
    SELECT
        b.instrument_id,
        b.date,
        b.open, b.high, b.low, b.close, b.volume,
        b.open  / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_open,
        b.high  / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_high,
        b.low   / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_low,
        b.close / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_close,
        (b.volume * COALESCE(f.cum_split_factor, 1))::bigint AS adj_volume,
        COALESCE(f.cum_split_factor, 1) AS cum_split_factor,
        COALESCE(f.cum_div_factor, 1) AS cum_div_factor,
        b.source
    FROM daily_bars AS b
    LEFT JOIN adjustment_factors AS f
        ON f.instrument_id = b.instrument_id AND f.date = b.date
SQL);
```

**Proč view a ne výpočet v exportním SQL:** specifikace požaduje, aby Python neimplementoval adjustment logiku. Kdyby vzorec byl v DuckDB dotazu, byl by to druhý výskyt téhož vzorce a mohl by se rozejít. Ve view existuje jednou, vlastní ho PHP migrace, a export z něj jen čte.

`LEFT JOIN` s `COALESCE` je důsledek toho, že `adjustment_factors` obsahuje jen řádky s faktorem různým od 1.

- [ ] **Step 2: Napsat Python exportní skript**

`research/export_parquet.py`:

```python
"""Vyexportuje jeden rok denních barů z Postgresu do Parquetu pomocí DuckDB."""

import argparse
import os
import sys

import duckdb


def export_year(year: int, out_path: str, dsn: str) -> int:
    tmp_path = f"{out_path}.tmp"
    os.makedirs(os.path.dirname(out_path), exist_ok=True)

    con = duckdb.connect()
    con.execute("INSTALL postgres; LOAD postgres;")
    con.execute(f"ATTACH '{dsn}' AS pg (TYPE POSTGRES, READ_ONLY)")
    con.execute(
        f"""
        COPY (
            SELECT * FROM pg.public.daily_bars_adjusted
            WHERE date >= DATE '{year}-01-01' AND date < DATE '{year + 1}-01-01'
            ORDER BY instrument_id, date
        ) TO '{tmp_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
        """
    )
    rows = con.execute(f"SELECT count(*) FROM read_parquet('{tmp_path}')").fetchone()[0]
    con.close()

    os.replace(tmp_path, out_path)
    return int(rows)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--year", type=int, required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--dsn", required=True)
    args = parser.parse_args()

    print(export_year(args.year, args.out, args.dsn))
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

`os.replace` je atomický přesun — Python čtoucí data nikdy neuvidí rozepsaný soubor, jak požaduje specifikace.

- [ ] **Step 3: Napsat kontraktní test v Pythonu**

`research/tests/test_parquet_contract.py`:

```python
import duckdb
import pyarrow.parquet as pq

EXPECTED_COLUMNS = {
    "instrument_id", "date", "open", "high", "low", "close", "volume",
    "adj_open", "adj_high", "adj_low", "adj_close", "adj_volume",
    "cum_split_factor", "cum_div_factor", "source",
}


def test_schema_matches_contract(exported_parquet_path):
    schema = pq.read_schema(exported_parquet_path)

    assert set(schema.names) == EXPECTED_COLUMNS


def test_row_count_and_checksum(exported_parquet_path, expected_rows, expected_close_sum):
    con = duckdb.connect()
    rows, close_sum = con.execute(
        f"SELECT count(*), sum(adj_close) FROM read_parquet('{exported_parquet_path}')"
    ).fetchone()

    assert rows == expected_rows
    assert abs(close_sum - expected_close_sum) < 1e-6
```

Fixtury `exported_parquet_path`, `expected_rows` a `expected_close_sum` vytvoří `conftest.py`, který naplní testovací Postgres kanonickým fixture z Tasku 17 a zavolá `export_year`.

Tenhle test je **jediné místo, kde se pozná, že se PHP export a Python čtení rozešly.** Proto je povinný, ne volitelný.

- [ ] **Step 4: Napsat PHP exporter a jeho test**

`ParquetExporter` volá skript přes `Symfony\Component\Process\Process`, kontroluje exit kód a vrací cestu. Test ověří: úspěšný export vytvoří soubor a vrátí jeho cestu; nenulový exit kód skriptu způsobí výjimku; cesta vychází z `MARKET_DATA_SHARED_PATH`.

```
storage/shared/daily/year=2019/part.parquet
```

- [ ] **Step 5: Spustit testy obou stran, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter=ParquetExporterTest
cd research && python -m pytest && cd ..
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData database research tests
git commit -m "feat: Parquet export přes DuckDB a kontraktní test schématu"
```

---

### Task 22: data:health a data:benchmark

**Files:**
- Create: `app/MarketData/Console/DataHealthCommand.php`, `DataBenchmarkCommand.php`
- Create: `app/MarketData/Health/HealthReport.php`, `HealthChecker.php`
- Test: `tests/Feature/MarketData/Health/HealthCheckerTest.php`, `tests/Feature/MarketData/Console/DataHealthCommandTest.php`

**Interfaces:**
- Consumes: `IngestRun` (7), `ValidationFinding` (7), `MarketDay` (4), `DailyBar` (5)
- Produces:
  - `HealthChecker::check(): HealthReport` s `lastSuccessfulIngestAt`, `tradingDaysCoveredLast30`, `openErrorFindings`, `missingPartitionYears`, `healthy` (bool)
  - Command `market-data:health` — **nenulový exit kód**, když `healthy === false`
  - Command `market-data:benchmark {--baseline=}` — měří průchod bulk importu a exportu, porovnává s uloženou baseline v `storage/benchmarks/baseline.json`

- [ ] **Step 1: Napsat failující test HealthChecker**

Test pokrývá čtyři případy, každý samostatně: zdravý stav; poslední úspěšný ingest starší než prahové stáří; existující `error` finding bez vyřešení; chybějící partition pro aktuální rok. V každém nezdravém případě musí `healthy` být `false`.

- [ ] **Step 2: Spustit test a ověřit selhání**

Run: `vendor/bin/phpunit --filter=HealthCheckerTest`
Expected: FAIL — class not found

- [ ] **Step 3: Implementovat HealthChecker a HealthReport**

`HealthReport` je `final readonly` DTO. `HealthChecker` skládá jednotlivé kontroly; každá je privátní metoda vracející svůj dílčí výsledek, aby šla čtením kódu ověřit.

- [ ] **Step 4: Napsat test příkazu na nenulový exit kód**

```php
public function testHandleUnhealthy(): void
{
    $this->artisan('market-data:health')->assertExitCode(1);
}
```

Nenulový exit kód je celý smysl příkazu — bez něj by ho monitoring nedokázal použít.

- [ ] **Step 5: Implementovat oba příkazy**

`market-data:benchmark` měří dvě věci a zapisuje je: počet řádků za sekundu při bulk importu kanonického fixture a dobu exportu jednoho roku. Když existuje baseline a měření je horší o víc než konfigurovaný podíl, příkaz to **vypíše jako varování s čísly**, ale nevrací chybu — je to nástroj pro člověka, ne test v CI.

- [ ] **Step 6: Spustit testy, statická analýza, code style, commit**

```bash
vendor/bin/phpunit --filter='HealthCheckerTest|DataHealthCommandTest'
vendor/bin/phpstan analyse
vendor/bin/phpcs
git add app/MarketData tests
git commit -m "feat: data:health s nenulovým exit kódem a data:benchmark"
```

---

### Task 23: Kontejnerizace (odložená)

**Proč je až tady a proč byla odložená.** Zadavatel Docker při vývoji nechtěl a lokální PHP s lokálním Postgresem stačí na všechno v Tascích 1–22. Kontejnerizace přináší hodnotu teprve ve dvou momentech: až bude potřeba reprodukovatelné prostředí pro Python vrstvu (podprojekt 2) a až bude systém běžet nepřetržitě na serveru (podprojekt 7). Dělat ji dřív by znamenalo udržovat druhé prostředí, ve kterém se nic nespouští.

**Files:**
- Create: `.docker/app/Dockerfile`, `.docker/research/Dockerfile`, `docker-compose.yml`
- Modify: `.env.example` (hostnames pro kontejnery)
- Test: `tests/Feature/SmokeTest.php` musí projít v kontejneru

**Interfaces:**
- Consumes: hotový projekt z Tasků 1–22
- Produces: `app`, `postgres`, `redis`, `worker`, `research` služby; identická testová sada spustitelná lokálně i v kontejneru

- [ ] **Step 1: Napsat Dockerfile pro `app`**

```dockerfile
FROM php:8.5-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app
USER app
WORKDIR /app
```

`ARG UID`/`GID` jsou tam kvůli pravidlu z guidelines vyhýbat se Docker příkazům, které mění ownership souborů v git repu.

- [ ] **Step 2: Napsat Dockerfile pro `research`**

```dockerfile
FROM python:3.13-slim

ARG UID=1000
ARG GID=1000

RUN pip install --no-cache-dir duckdb pyarrow pandas pytest psycopg[binary]

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app
USER app
WORKDIR /app
```

- [ ] **Step 3: Napsat docker-compose.yml**

Služby `app`, `postgres` (17), `redis` (7), `worker` (`php artisan queue:work --tries=1`), `research`. Sdílený volume `shared` namontovaný do `app`, `worker` i `research` na cestu z `MARKET_DATA_SHARED_PATH`. `worker` má `--tries=1`, protože ingest je idempotentní přes hash souboru a checkpoint — automatický retry by v půlce importu jen zamlžil, co se stalo.

Redis se přidává až tady a zatím ho nic nepoužívá; je v sestavě proto, aby prostředí odpovídalo tomu, co bude potřeba od podprojektu 4. **Pokud to při dokončení tohoto tasku stále platí, je správné ho vynechat a přidat s podprojektem 4.**

- [ ] **Step 4: Ověřit, že testy projdou v kontejneru**

```bash
docker compose build
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan test
docker compose run --rm research python -m pytest
```

Expected: stejný počet testů a stejný výsledek jako lokálně. Rozdíl znamená, že prostředí nejsou ekvivalentní, a je to chyba, ne vlastnost.

- [ ] **Step 5: Commit**

```bash
git add .docker docker-compose.yml .env.example
git commit -m "chore: kontejnerizace prostředí"
```

---

## Souhrn kritických invariantů

Věci, které při implementaci nejde odvodit z kódu a musí zůstat pravdivé. Každá má v plánu vlastní test.

1. **Neznámý symbol se nikdy nehádá.** Nenapárované řádky jdou do karantény, ne k nejbližšímu instrumentu.
2. **Jeden rozbitý ticker neshodí import ostatních.** Karanténa je po instrumentu, ne po běhu.
3. **Chyby v datech jsou řádky v tabulce, ne výjimky.** Výjimka jen pro chybu programátora a selhání infrastruktury.
4. **Delistovaný instrument je členem univerza k datům před delistingem.** Retroaktivní vyloučení je survivorship bias.
5. **Okno klouzavého průměru se nikdy nedívá dopředu.** `ROWS BETWEEN n PRECEDING AND CURRENT ROW`, nikdy `FOLLOWING`.
6. **Členství k datu D nad zkrácenými daty se rovná členství nad plnými daty.** Jádro testu na look-ahead.
7. **Adjustment se přepočítává vždy celý, nikdy inkrementálně.**
8. **Adjustment vzorec existuje v systému právě jednou** — ve view `daily_bars_adjusted`.
9. **Bary v `daily_bars` jsou surové a nikdy se nemění kvůli corporate action.**
10. **Tentýž soubor naimportovaný dvakrát nevytvoří duplikát.** Hash obsahu, a jen dokončený běh blokuje reimport.
11. **Strop nálezů nikdy nemlčí.** Při jeho dosažení vzniká souhrnný nález.
12. **Parquet se zapisuje atomicky.** Python nikdy nečte rozepsaný soubor.


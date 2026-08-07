# Stav projektu a předání

Poslední aktualizace: 2026-08-07

## Co to je

Research platforma pro hledání swing strategií na US akciích, která ve finální fázi obchoduje plně automaticky přes Alpaca API. Jádrem je backtest engine, ne exekuce — exekuce je poslední fáze.

**Rozdělení rolí:** Python vlastní indikátory, strategie, backtest a rozhodnutí. PHP vlastní data, orchestraci, exekuci, reconciliaci, audit a monitoring. Rozhraním mezi nimi je Parquet (data) a tabulka cílových pozic (rozhodnutí).

## Stav k dnešnímu dni

**Podprojekt 1 je hotový. Všech 23 tasků plánu odpracováno.** 128 PHP testů a 3 Python kontraktní testy zelené, PHPStan na levelu max bez chyb, phpcs bez chyb.

Co existuje:

| Vrstva | Obsah |
|---|---|
| Skeleton | Laravel 13.24, PHPUnit, PHPStan max + larastan, phpcs (PSR-12, 120 znaků, `strict_types`) |
| Testovací helpery | `EloquentMatcher`, `CollectionMatcher`, `DataMatcher`, `TestCase::spyLogger()`, `Tests\Support\StagingFixture` |
| Katalog | `instruments`, `instrument_symbols` + `SymbolResolver`, `market_days` + Alpaca kalendář, `corporate_actions` |
| Sklad | `daily_bars` a `intraday_bars` partitionované, `PartitionManager` |
| Audit | `ingest_runs`, `validation_findings` |
| Ingest | `BarSourcePort`, bulk CSV zdroj s hashem, staging + `COPY`, množinový resolve, karanténa, `IngestPipeline`, `BarMerger`, inkrementální Alpaca zdroj s rate limitem a lockem |
| Validace | 8 pravidel (3 error, 5 warning) nad `AbstractStagingRule`, `ValidationRunner` s karanténou po instrumentu |
| Adjustment | `adjustment_factors`, `AdjustmentFactorCalculator`, view `daily_bars_adjusted` |
| Univerzum | `universe_definitions`, `universe_members`, `UniverseMemberResolver` s point-in-time členstvím |
| Export | `ParquetExporter` + DuckDB skript, kontraktní testy v `research/tests` |
| Provoz | `market-data:health` s nenulovým exit kódem, `market-data:benchmark`, scheduler, kanonický fixture seeder |

Devět příkazů v `php artisan list market-data`.

**Plány a kód podprojektů 2–7 neexistují.** Podprojekt 1 tím splnil svou roli: Parquet snapshot je hotový a otestovaný, takže podprojekt 2 (indikátory v Pythonu) má na čem stavět a nemusí hádat tvar dat.

```
docs/superpowers/specs/2026-08-06-market-data-design.md          podprojekt 1
docs/superpowers/specs/2026-08-06-indicators-design.md           podprojekt 2
docs/superpowers/specs/2026-08-06-strategy-definition-design.md  podprojekt 3
docs/superpowers/specs/2026-08-07-backtest-engine-design.md      podprojekt 4
docs/superpowers/specs/2026-08-07-research-workflow-design.md    podprojekt 5
docs/superpowers/specs/2026-08-07-paper-execution-design.md      podprojekt 6
docs/superpowers/specs/2026-08-07-live-execution-design.md       podprojekt 7
docs/superpowers/plans/2026-08-06-market-data.md                 plán podprojektu 1, 23 tasků — HOTOVO
docs/superpowers/plans/2026-08-07-indicators.md                  plán podprojektu 2, 14 tasků — nezačatý
```

Git: branch `main`, remote `origin` = https://github.com/simako12-debug/forex.git. Každý task má vlastní větev `tech/task-NN`, vlastní commit a merge commit do `main`; větve jsou po sloučení smazané. Commit message každého tasku vyjmenovává odchylky od plánu a jejich důvod.

## Prostředí

Ověřeno 2026-08-07 na Windows 11 stroji. **Prostředí běží v Dockeru** — zadavatel si vyžádal kontejnerizaci hned, takže Task 23 je předtažený na začátek. Sekce níže nahrazuje původní zjištění z Linux stroje.

Sestava se spouští z rootu projektu:

```bash
docker compose up -d
docker compose exec app <příkaz>       # PHP 8.5, composer
docker compose exec research <příkaz>  # Python 3.13, duckdb, pyarrow
```

| Služba | Stav |
|---|---|
| `app` | PHP **8.5.9**, Composer 2.10.2, extensions `pdo_pgsql`, `zip`, `intl`, `bcmath` |
| `postgres` | Postgres **17.10**, databáze `forx` a `forx_testing`, uvnitř sítě `postgres:5432`, na hostu **5433** |
| `research` | Python **3.13.14**, duckdb 1.5.5, pyarrow 25.0.0, pandas 3.0.5, psycopg |
| `worker` | `php artisan queue:work --tries=1`, běží s `docker compose up -d` |
| Redis | **není v sestavě**; Task 23 Step 3 to sám připouští, v plánu 1 jedou locky i fronty nad `database` storem. Přijde s podprojektem 4 |

Ověřené kontrakty: PHP → Postgres přes PDO, Python → Postgres přes psycopg, DuckDB `postgres` extension čte Postgres, sdílený volume `/shared` je zapisovatelný z `app` i `research`, bind mount repa je zapisovatelný. Ověřovací sekvence z Tasku 23 projde celá:

```bash
docker compose build
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan test      # 128 passed
docker compose run --rm research sh -c 'cd /app/research && python -m pytest'   # 3 passed
```

`app` image obsahuje i `python3` s `duckdb`, protože `ParquetExporter` spouští exportní skript jako podproces a nemůže sáhnout do cizího kontejneru. `research` zůstává pro výzkumnou práci a kontraktní testy.

**Žádný krok se sudo není potřeba.** Role, obě databáze i extensions vznikají při `docker compose up`.

### Co je na hostu (mimo Docker)

PHP 8.4.3 a Composer 2.8.5 jsou nainstalované, ale **plán vyžaduje PHP 8.5** — host je proto na vývoj nepoužitelný a všechno jde přes `app` kontejner. `psql` na hostu není; klient je v `postgres` kontejneru.

### Obsazené porty

Na stroji běží další projekty (`stockmanager`, `wealthtracker`, `trading-*`). Obsazené je mimo jiné **5432**, proto Postgres této sestavy poslouchá na **5433**. Při přidávání služeb ověřit port předem.

## Jak pokračovat

### 1. Vykonat plán podprojektu 2 — doporučeno

Plán je hotový: `docs/superpowers/plans/2026-08-07-indicators.md`, 14 tasků ve čtyřech etapách. Etapa 2a rozšiřuje snapshot z podprojektu 1 o metadata a manifest (PHP), etapy 2b–2d staví indikátorovou vrstvu v Pythonu.

**Rozšíření snapshotu je součástí plánu 2, ne dodatek k plánu 1.** Export z podprojektu 1 nese jen bary, ale `listed_mask` potřebuje `listed_at`/`delisted_at`, `cs_rank` potřebuje členství v univerzu k datu a rozlišení mezery od warm-upu potřebuje kalendář.

Instrukce pro novou session:

> Vykonej implementační plán `docs/superpowers/plans/2026-08-07-indicators.md` od Tasku 1. Použij skill `superpowers:subagent-driven-development` nebo `superpowers:executing-plans`. Nejdřív si přečti `docs/superpowers/STATUS.md`.

### 2. Naimportovat reálná data

Zatím na sklad nikdy nesáhla jiná data než fixture. Do prvního reálného importu se nedozvíme, jak se pravidla chovají na skutečném dumpu — kolik nenapárovaných tickerů, kolik OHLC nálezů, jak dlouho běží `COPY` u stomilionového souboru. K tomu je potřeba koupit dump (viz externí neznámé níže) a pustit:

```bash
docker compose exec app php artisan market-data:ensure-partitions --from-year=2000
docker compose exec app php artisan market-data:import-calendar     # potřebuje Alpaca klíče
docker compose exec app php artisan market-data:import-bulk /shared/dumps/daily.csv
docker compose exec app php artisan market-data:health
```

### 3. Dopsat ingest intradenních barů

Jediná část rozsahu specifikace podprojektu 1, kterou plán vědomě nepokryl — viz Známé mezery.

### 4. Ověřit externí neznámé

Fakta, která specifikace vědomě neobsahují, protože to nejsou návrhová rozhodnutí. Žádné z nich neblokuje plán 1, ale všechna budou potřeba dřív nebo později:

- **Přijímá Alpaca aktuálně účty z ČR?** Seznam podporovaných zemí se mění. Blokuje podprojekty 6–7.
- **Povolují podmínky brokera automatizované obchodování přes API?** U Alpaky a IBKR se to čeká, u XTB spíš ne.
- **Cena a přesný rozsah bulk dumpů** — FirstRateData (16 272 tickerů, z toho 7 000+ delistovaných, denní od 2000) a HistoricalData.net (denní i 1min od 2003, 50 000+ delistovaných symbolů). **Teď už blokuje** — bez dumpu nejde spustit reálný import.
- **Aktuální sazby SEC Section 31 fee a FINRA TAF.** Backtest bez nich má podle specifikace **selhat**, ne použít nulu. Potřeba pro podprojekt 4.
- **Alpaca API klíče.** `ALPACA_KEY_ID` a `ALPACA_SECRET_KEY` v `.env` jsou prázdné, takže `market-data:import-calendar` i `market-data:import-incremental` proti reálnému API zatím neproběhly. Adaptéry jsou otestované proti `Http::fake()`, ne proti živému endpointu — **formát odpovědi je tím ověřený jen podle dokumentace, ne empiricky.**

### 5. Projít specifikace a měnit rozhodnutí

U podprojektů 2–7 je změna návrhových rozhodnutí ještě zdarma. Nejcitlivější místa:

- logika strategie jen v Pythonu (přepisuje role celého systému)
- scoring s cross-sectional prahem místo absolutního
- long-only ve v1
- nativní bracket příkazy u brokera místo syntetických stopů
- žádný trailing stop ve v1

## Známé mezery a vědomé odchylky

**Plán 1 nepokrývá ingest intradenních 5min barů.** Tabulka `intraday_bars` a její partitions vznikají v Tasku 5, ale žádný task do nich nenalévá data. Důvod: intradenní historie pro 500 nejlikvidnějších tickerů je samostatný nákup a samostatný formát, a její adaptér nemá smysl psát dřív, než dump existuje. Patří do navazujícího plánu. Kritérium hotovosti specifikace je splněné (je formulované nad denními bary), ale rozsah specifikace intradenní data zmiňuje.

**Plán mění pořadí ingest kroků proti specifikaci** — z *resolve → stage → validate* na *stage → resolve v SQL → validate*. Resolvovat sto milionů řádků po jednom v PHP je zbytečné, když to Postgres udělá jedním `UPDATE ... FROM` join. Chování zůstává stejné, karanténa se zredukuje na „řádky s `NULL` v `instrument_id`".

**Parquet nezapisuje PHP.** V PHP neexistuje zralý zapisovač Parquetu. Export je DuckDB příkaz, který přes `postgres` extension čte Postgres. Adjustment vzorec žije v Postgres view `daily_bars_adjusted`, které vlastní PHP migrace — tím existuje v systému právě jednou a Python ho neimplementuje.

**Redis v plánu 1 chybí záměrně.** Etapa 1a potřebuje jen atomický lock, který Laravel umí nad `database` storem. Redis nastupuje s Python job frontou v podprojektu 4; jeho role ze specifikace platí nezměněně.

**Worker běží, ale nikdy nezpracoval job.** V plánu 1 nevzniká jediná queued job — fronty nastupují s podprojektem 4. Ověřeno je, že v kontejneru `worker` běží `php artisan queue:work --tries=1` jako PID 1 bez restartů a že je fronta dostupná; průchod skutečného jobu ne, protože není co poslat.

**Adaptéry Alpaky nejsou ověřené proti živému API.** Testy jedou proti `Http::fake()`, což je záměr (síť v testech je zakázaná), ale znamená to, že tvar odpovědi odpovídá dokumentaci, ne měření. Při změně formátu spadne test adaptéru — což je ta správná vrstva, ale spadne až po ruční opravě fake dat.

**Task 23 byl předtažený na začátek.** Zadavatel si vyžádal Docker hned, takže kontejnerizace nevznikla jako poslední task, ale jako první krok. Její dokončení (odstranění compose profilu u workeru, ověřovací sekvence) proběhlo až na konci, protože do té doby nebylo co spouštět.

## Dvanáct kritických invariantů

Věci, které z kódu nejde odvodit a musí zůstat pravdivé. Každá má v plánu vlastní test. Úplný seznam s odůvodněním je na konci plánu podprojektu 1; nejdůležitější:

1. Neznámý symbol se **nikdy nehádá** — nenapárované řádky jdou do karantény.
2. Jeden rozbitý ticker **neshodí import ostatních** — karanténa je po instrumentu, ne po běhu.
3. Chyby v datech jsou **řádky v tabulce, ne výjimky**.
4. Delistovaný instrument **musí být** členem univerza k datům před delistingem — retroaktivní vyloučení je survivorship bias.
5. Okno klouzavého průměru se **nikdy nedívá dopředu** — `ROWS BETWEEN n PRECEDING AND CURRENT ROW`, nikdy `FOLLOWING`.
6. Členství k datu *D* nad zkrácenými daty se **rovná** členství nad plnými daty.
7. Adjustment se přepočítává **vždy celý**, nikdy inkrementálně.
8. Bary v `daily_bars` jsou **surové** a nikdy se nemění kvůli corporate action.
9. Tentýž soubor naimportovaný dvakrát **nevytvoří duplikát**.
10. Strop nálezů **nikdy nemlčí** — při jeho dosažení vzniká souhrnný nález.

## Konvence

`.claude/rules/backend/*` a `.ai/guidelines.md` v repu jsou zkopírované z Sharry monorepa a platí. Projektové `CLAUDE.md` na `.ai/guidelines.md` odkazuje.

Dvě vědomé odchylky od guidelines, obojí zdůvodněné v plánu:
- **UUID primární klíč se nepoužívá u časových řad** (`daily_bars`, `intraday_bars`, `adjustment_factors`, `universe_members`) — u 200M řádků je 16bajtový náhodný klíč nafouknutý index a rozbité clusterování. Guidelines platí pro business entity.
- **Testovací helpery** (`EloquentMatcher`, `CollectionMatcher`, `DataMatcher`, `spyLogger`) v Sharry monorepu žijí v `Sharry\Base` a tady neexistují — Task 2 plánu je doimplementuje. Musí být hotové **před prvním testem**, jinak se konvence začnou obcházet.

## Přenos na jiný stroj

`git clone https://github.com/simako12-debug/forex.git`. `.claude/` konfigurace je součástí repa, takže skilly a rules se přenesou s ním.

Setup kroky výše jsou psané pro Linux (původní stroj, `/home/petrsima/custom/forx`). Na Windows je potřeba je přeložit — instalace `pdo_pgsql` i vytvoření rolí a databází probíhá jinak.

# Market Data — design podprojektu 1

Datum: 2026-08-06
Stav: navržen, čeká na review
Revize 2026-08-07: opraveno rozdělení rolí PHP/Python a doba nasazení `research` prostředí po rozhodnutí v [podprojektu 3](2026-08-06-strategy-definition-design.md). Parquet export tím přestává být pohodlnost pro budoucí sweepy a stává se **hlavním rozhraním mezi PHP daty a Python výpočty** — musí být hotový dřív než podprojekt 2.

## Kontext celého systému

Cílem je **research platforma pro hledání swing strategií na US akciích**, která ve finální fázi obchoduje plně automaticky přes Alpaca API. Jádrem systému je backtest engine, ne exekuce — exekuce je poslední fáze, do které se projekt dostane až bude existovat strategie, které lze věřit.

### Rámcová rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Broker | Alpaca (US akcie + ETF) | čisté REST + WebSocket API, paper trading zdarma a okamžitě, historická data v ceně, jedna burzovní zóna a měna |
| Automatizace | plná, ale jako poslední fáze; před ní povinná paper fáze | plná automatizace vynucuje idempotenci objednávek, reconciliaci, kill-switch a audit trail — to má smysl stavět až nad ověřenou strategií |
| Stack | **Python** vlastní indikátory, strategie, backtest a rozhodnutí; **PHP** vlastní data, orchestraci, exekuci, reconciliaci, audit a monitoring | PHP chybí vektorizovaná numerika a statistický ekosystém; u research platformy je rychlost cyklu hlavní metrika. Rozdělení upřesněno v [podprojektu 3](2026-08-06-strategy-definition-design.md) — logika strategie existuje jen v Pythonu, takže divergence backtestu a živého provozu nemá kde vzniknout |
| PHP framework | Laravel | scheduler, queue, console commands, migrace; `guidelines.md` i `.claude/rules/backend/*` jsou psané pro Laravel + `spatie/laravel-data` |
| Univerzum | pravidlem definovaná likvidní podmnožina US trhu, vyhodnocená k datu, včetně delistovaných | eliminuje survivorship bias; `dollar volume` je lepší proxy likvidity než tržní kapitalizace a nepotřebuje fundamentální data |
| Timeframy | denní pro celé univerzum, 5min pro top 500 podle mediánu dollar volume za posledních 12 měsíců | swing horizont 2–10 dní; intradenní data slouží k ověření, jestli časování vstupu vůbec přidává hodnotu proti fillu na open |
| Získávání dat | jednorázový bulk import historie + free inkrementální denní update | historie se nemění, takže subskripce za ni je opakovaná platba za totéž; bulk dumpy obsahují delistované tickery |
| Úložiště | Postgres jako zdroj pravdy, odvozené Parquet snapshoty pro Python | Postgres čtou PHP i Python nativně a dává transakční ingest; Parquet přes DuckDB je pro opakované full-scany při sweepech řádově rychlejší |

### Nejasnosti k ověření mimo tento dokument

- Zda Alpaca aktuálně přijímá účty z ČR (seznam podporovaných zemí se mění).
- Zda podmínky brokera povolují automatizované obchodování přes API.
- Aktuální cena a přesný rozsah bulk dumpů (FirstRateData, HistoricalData.net).

### Pořadí podprojektů

Každý dostane vlastní spec → plán → implementaci:

1. **Market data** ← předmět tohoto dokumentu
2. Feature/indikátorová vrstva
3. Definice strategie (jak se strategie zapisuje)
4. Backtest engine
5. Research workflow (parametr sweepy, walk-forward, srovnávání běhů)
6. Paper execution
7. Live execution + risk + monitoring

## Rozsah podprojektu 1

### Vevnitř

- Katalog instrumentů: tickery, burza, sektor, datum listingu a delistingu
- Historie denních barů s uchováním neupravených hodnot
- Historie 5min barů pro podmnožinu: top 500 instrumentů podle mediánu dollar volume za posledních 12 měsíců, přepočítáno ročně. Jde o rozhodnutí o **rozsahu nakupovaných dat**, ne o point-in-time konstrukci — proto platí pravidlo níže o odmítnutí instrumentu bez dat.
- Pravidlo dostupnosti timeframu: strategie deklaruje, jaké timeframy potřebuje. Když pro instrument v požadovaném období data v tom timeframu nejsou, backtest **selže s chybou**, nikoliv tiše přeskočí. Tiché přeskakování by vyrobilo skryté zkreslení výběru instrumentů.
- Corporate actions jako samostatná entita, ne jen aplikovaná korekce
- Dva rovnocenné ingest kanály: bulk import z CSV/ZIP a inkrementální API pull
- Validační vrstva nad oběma kanály
- Point-in-time výpočet členství v univerzu
- Export Parquet snapshotů pro Python

### Mimo

Jakýkoliv indikátor, signál, strategie, backtest, objednávka nebo UI nad rámec CLI přehledu o stavu dat. Podprojekt 1 je datový sklad a nic víc.

### Poznámka k členění implementace

Rozsah je na jeden implementační plán velký. Plán ho pravděpodobně rozdělí na dvě etapy — **1a**: tooling setup, schéma, katalog instrumentů, kalendář, bulk ingest, validace; **1b**: adjustment faktory, point-in-time univerzum, Parquet export, `data:health`. Specifikace zůstává jedna, protože ty dvě etapy nemají samostatný smysl.

### Kritérium hotovosti

Dotaz „dej mi denní bary pro univerzum, jak vypadalo 15. března 2019" vrátí odpověď, která neobsahuje ani jeden ticker, který k tomu datu nesplňoval podmínky nebo nebyl obchodovaný — a validace na těch datech projde bez chyb severity `error`.

## Architektura

### Kontejnery

| Kontejner | Obsah | Účel |
|---|---|---|
| `app` | PHP 8.4 CLI, Composer | ingest, validace, export, produkce jobů |
| `postgres` | Postgres 17 | zdroj pravdy |
| `redis` | Redis | fronty, locky, progress, rate limiting |
| `worker` | PHP queue worker | dlouhé importy a přepočty |
| `research` | Python 3.13, pandas, pyarrow, duckdb | indikátory, strategie, backtest — potřeba **od podprojektu 2**, ne od 4 |

Bez nginx a bez web serveru — podprojekt 1 nemá HTTP vrstvu.

### Hranice PHP ↔ Python

Kanál nese **jen job spec a progress s výsledkem** (jednotky kB). Objemná data tečou vždy přes Parquet na shared volume, nikdy kanálem.

```
PHP           XADD research:jobs {job_id, strategy, params, universe, date_range}
Python worker XREADGROUP (consumer group, dlouho běžící proces)
              ├─ čte bary z /shared/data/*.parquet
              ├─ PUBLISH research:progress:<job_id> {pct, eta}
              └─ zapíše /shared/results/<job_id>.parquet
              XADD research:results {job_id, status, metrics}
PHP           XREADGROUP → uloží metriky do Postgresu
```

**Redis Streams, ne obyčejný list** — consumer groups a ACK zajistí, že job po smrti workera spadne zpět do fronty místo aby se tiše ztratil. U hodinových výpočtů to není teoretický problém.

**WebSocket se pro interní komunikaci nepoužije.** Není tu klient a server, ale fronta práce s fan-outem na N workerů; to je přesně případ pro Redis Streams. WebSocket by znamenal udělat z Pythonu server, řešit reconnecty a stejně za tím mít frontu. Jediná výhoda — streamování progressu — je v Redis pub/sub zdarma.

WebSocket bude potřeba **jednou a jinde**: v podprojektu 6/7 pro Alpaca trade updates (potvrzení fillů), kde jsme klientem a Alpaca serverem. Fill se pollovat rozumně nedá. Zda ten listener bude ReactPHP démon nebo Python proces se rozhodne tam.

### Role úložišť — pravidlo bez výjimky

- **Postgres** = zdroj pravdy
- **Parquet** = objemná odvozená data pro analytiku
- **Redis** = koordinace: fronty, locky, progress, rate limity

Cokoliv v Redisu smí kdykoliv zmizet, aniž by se něco nenávratně pokazilo. Bez tohoto pravidla začne Redis během roku držet stav, o který je škoda.

Redis se v podprojektu 1 uplatní i mimo job kanál: lock proti dvojímu spuštění denního ingestu, sliding-window rate limiting proti datovým zdrojům (Alpaca free má 200 req/min), a jako queue driver pro dlouhé importy.

### Struktura PHP kódu

Moduly podle podprojektů, ne podle technických vrstev. Každý modul má `Contracts/` jako jedinou veřejnou plochu; ostatní moduly na něj smí sahat jen skrz ni.

```
app/
  MarketData/
    Contracts/        BarSourcePort, CorporateActionSourcePort
    Ingest/
      Bulk/           CSV/ZIP importéry (adaptér per formát)
      Incremental/    API adaptéry (Alpaca, ...)
    Validation/       jedno pravidlo = jedna třída
    Universe/         point-in-time vyhodnocení členství
    Adjustment/       výpočet kumulativních koeficientů
    Export/           Parquet snapshoty
    Models/           Instrument, DailyBar, IntradayBar, CorporateAction
    Console/          příkazy
  Shared/
```

Oba porty budou mít od začátku nejméně dva adaptéry (bulk + inkrementální), takže nejde o spekulativní abstrakci, ale o popis reality.

## Datový model

### Tři principy, ze kterých schéma vyplývá

**1. Bary se ukládají neupravené a nikdy se nemění.** Dividenda vyhlášená dnes retroaktivně mění všechny ceny v historii. Ukládat upravené hodnoty by znamenalo přepis milionů řádků při každé corporate action a nenávratnou ztrátu informace o skutečně obchodované ceně. Ukládá se tedy raw OHLCV, corporate actions jako samostatná entita, a z nich se počítá kumulativní adjustment faktor. Upravené ceny se materializují až do Parquet exportu.

**2. Ticker není identita.** Symboly se recyklují — firma zkrachuje a její ticker po letech dostane jiná firma. Napojení bulk CSV klíčovaného symbolem přímo na instrument by slepilo dvě různé firmy do jedné cenové řady a vyrobilo skok, který nikdy nenastal. Instrument má proto stabilní vlastní identitu a symboly jsou její časově omezené atributy.

**3. Bez burzovního kalendáře nejde odlišit mezeru od svátku.** Kalendář je předpoklad validace, ne doplněk. Zdrojem je Alpaca calendar endpoint, ověřovaný proti knihovně `exchange_calendars` v `research` kontejneru. Naplní se jednorázově pro celé období historie a doplňuje se ročně — kalendář burzy je znám dopředu.

### Schéma

```
instruments          id (uuid), name, asset_class, primary_exchange,
                     sector, listed_at, delisted_at, delisting_reason (nullable)
instrument_symbols   instrument_id, symbol, valid_from, valid_to
                     └─ unique (symbol, valid_from); lookup symbol→instrument k datu

market_days          exchange, date, is_open, open_at, close_at, is_early_close

daily_bars           instrument_id, date, open, high, low, close, volume,
                     source, ingested_at
                     └─ PK (instrument_id, date); partitioned by year
                     └─ RAW, nikdy neupravené
intraday_bars        instrument_id, ts (timestamptz, UTC), o/h/l/c/v, source
                     └─ PK (instrument_id, ts); partitioned by month

corporate_actions    instrument_id, type (split|dividend|symbol_change|spinoff),
                     ex_date, ratio, amount, source, ingested_at
adjustment_factors   instrument_id, date, cum_split_factor, cum_div_factor
                     └─ odvozené z corporate_actions, kdykoliv přegenerovatelné

universe_definitions id, name, version, rules (jsonb), created_at
                     └─ rules má pro podprojekt 1 pevné schéma, ne obecný jazyk:
                        {min_price, min_avg_dollar_volume, dollar_volume_lookback_days}
universe_members     definition_id, date, instrument_id
                     └─ materializované; pro danou verzi definice APPEND-ONLY

ingest_runs          id, source, mode (bulk|incremental), started_at, finished_at,
                     rows_inserted, rows_updated, status, checkpoint, error
validation_findings  ingest_run_id, instrument_id, date, rule, severity, detail
```

### Vědomá odchylka od guidelines.md

`guidelines.md` předepisuje UUID primární klíč s `HasUuids` na všech modelech. Pro `daily_bars` a `intraday_bars` se to **neaplikuje** — u 200M řádků je 16bajtový náhodný klíč nafouknutý index a rozbité clusterování při vkládání. Časové řady dostanou složený přirozený klíč `(instrument_id, date)`, resp. `(instrument_id, ts)`, což je zároveň klíč, po kterém se dotazuje. Guidelines platí pro business entity (`instruments`, `universe_definitions`, `ingest_runs`, `validation_findings`).

### Revize dat

Dodavatelé data zpětně opravují. Neřeší se plnou bitemporalitou — pro tento účel by to bylo over-engineering. Každý bar nese `source` a `ingested_at`, přepis je povolený a viditelný v `ingest_runs`. Historizační tabulka se přidá teprve pokud se ukáže, že revize potřebují audit.

### Známé riziko

`universe_members` je materializovaná, takže změna pravidel vyžaduje přepočet — u 20 let historie běh na desítky minut. Řeší se verzováním definice, aby starý backtest zůstal reprodukovatelný. Nebezpečné místo je přepis členství pod nohama běžícímu experimentu; proto je tabulka pro danou verzi definice **append-only a nikdy se needituje**.

### Časové zóny

Intradenní timestampy vždy UTC `timestamptz`. Denní bary jako čisté `date` v pojmu burzy. Míchání obou světů je klasický zdroj off-by-one chyby, kdy se signál vyhodnotí o den dřív, než by v realitě mohl.

## Ingest a validace

### Jedna pipeline, dva vstupy

Bulk a inkrementální ingest se liší jen prvním krokem; zbytek je společný, aby bulk import nemohl obejít validaci.

```
1. Acquire     adaptér vrací normalizované řádky jako Generator (nikdy celý soubor v paměti)
2. Resolve     symbol + datum → instrument_id přes instrument_symbols
               neznámý symbol → karanténa, nikdy nehádat
3. Stage       COPY do unlogged staging tabulky
4. Validate    pravidla nad staging tabulkou, množinově v SQL
5. Merge       upsert do cílové tabulky, zápis ingest_run
6. Derive      přepočet adjustment_factors a universe_members pro dotčené instrumenty
7. Export      regenerace pouze dotčených Parquet partitions
```

**`COPY FROM STDIN`, ne Eloquent.** 100M řádků po jednom insertu je běh na dny; `COPY` do unlogged staging tabulky je řádově minuty. Eloquent se v ingestu nepoužije vůbec — je pro business entity, ne pro nalévání časových řad.

### Idempotence a resumabilita

- Bulk soubor se identifikuje **hashem obsahu** — druhý import téhož souboru je no-op.
- `ingest_run` drží checkpoint, takže spadlý import pokračuje místo aby začínal znovu.
- Inkrementální merge je `ON CONFLICT (instrument_id, date) DO UPDATE`.

### Validační pravidla

Každé je samostatná třída s jedním kontraktem — testovatelná izolovaně a vypsatelná příkazem.

| Pravidlo | Co hledá | Severita |
|---|---|---|
| `OhlcConsistency` | low ≤ open,close ≤ high, všechny > 0 | error |
| `DuplicateBar` | dva bary pro stejný instrument a den | error |
| `BarOnClosedDay` | bar v den, kdy byla burza zavřená → chyba zdroje nebo časové zóny | error |
| `MissingBarOnTradingDay` | chybí bar v otevřený den, kdy byl instrument listovaný | warning |
| `PriceJumpWithoutCorporateAction` | skok ceny nad prahem bez splitu či dividendy k datu | warning |
| `ZeroOrMissingVolume` | nulový objem v otevřený den — příznak IEX-only feedu | warning |
| `StaleSeries` | žádný nový bar N obchodních dní, ale instrument není delistovaný | warning |
| `CrossSourceDivergence` | close se mezi dvěma zdroji liší nad prahem | warning |

`CrossSourceDivergence` se aplikuje jen tam, kde se dva zdroje skutečně překrývají. Při zvolené strategii získávání dat (jeden bulk dump + jeden free inkrementální zdroj) je to okno inkrementálního ingestu, ne celá historie. Na historii tedy nekontroluje nic a nesmí se spoléhat na to, že ji pokrývá.

### Chování při chybě — karanténa po instrumentu, ne po běhu

Jeden rozbitý ticker nesmí zablokovat 1499 dobrých. `error` odloží řádky daného instrumentu do karantény a běh pokračuje. Celý běh se ruší jen při strukturálním selhání (nerozparsovatelný soubor, jiný počet sloupců než adaptér čeká). `warning` se zapíše do `validation_findings` a data projdou.

**Filozofie chyb:** chyby v datech nejsou výjimečný stav, jsou to očekávaná data — patří do tabulky, ne do exception. Výjimky jsou vyhrazené pro chyby programátora a selhání infrastruktury. Bez tohoto rozlišení ingest 1500 tickerů nedojde nikdy do konce.

### Přepočet adjustment faktorů je vždy celý

Pro jeden instrument jsou to řádově stovky řádků corporate actions, takže přepočet od nuly je levný. Inkrementální dopočítávání kumulativních koeficientů je místo, kde vznikají tiché chyby viditelné až v podivných backtest výsledcích.

### Parquet export

Partitionováno po roce, zapisováno atomicky (temp soubor + rename), takže Python nikdy nečte rozepsaný soubor. Do exportu jde raw cena, upravená cena i použitý koeficient — Python tak nemusí umět adjustment logiku a nemůže ji implementovat jinak než PHP.

```
/shared/data/daily/year=2019/part.parquet
/shared/data/intraday_5m/year=2019/month=03/part.parquet
```

### Monitoring

Tichá porucha je tady horší než hlasitá: když denní ingest jeden den neproběhne, backtesty jedou na zastaralých datech a nikdo to nepozná. Bude proto příkaz `data:health` (poslední úspěšný ingest, pokrytí za posledních N dní, počet otevřených findings) a notifikace při selhání.

## Testování

Konvence se drží `guidelines.md` doslova: `{ClassName}Test` v zrcadleném adresáři, `#[CoversClass]`, podtypy jako krátká substantiva scénáře bez verbálních prefixů, výjimky jako `test<Metoda><ExceptionClass>Throw`, žádné AAA komentáře, deterministická UUID přes `Uuid::fromString()` přímo v metodách, `assertSame` na `->toString()`, UUID do spy expectations jako stringy, `->with()` s matchery místo `withArgs()`, logger jen přes `$this->spyLogger()`.

### Předpoklad: doimplementovat Sharry-interní helpery

Část konvencí se opírá o helpery, které v greenfield projektu neexistují. Musí být hotové **dřív než první test**, jinak se konvence začnou obcházet.

| Helper | Co s tím |
|---|---|
| `EloquentMatcher` | doimplementovat (~30 řádků nad Hamcrest) |
| `CollectionMatcher` | doimplementovat |
| `DataMatcher` | doimplementovat |
| `$this->spyLogger()` | doimplementovat v base `TestCase` nad Monolog `TestHandler` |

Plus `hamcrest/hamcrest-php` do dev dependencies.

### Kde je u datového projektu hodnota

Ne v HTTP testech (podprojekt 1 nemá endpointy) a ne v pokrytí řádků, ale v testech **semantiky transformací** — v důkazu, že z dat nevypadne look-ahead bias, nezmizí zkrachovalé firmy a split se nepromítne obráceně.

### Jeden kanonický fixture

Malý syntetický dataset (5 instrumentů × ~60 obchodních dní) obsahující po jednom z každé pasti:

- instrument delistovaný v polovině období
- instrument, který se stane likvidním až v průběhu (vstupuje do univerza)
- ticker recyklovaný mezi dvěma různými instrumenty
- jeden split a jedna dividenda
- jedna mezera v datech a jeden bar porušující OHLC invariant
- jeden svátek a jeden zkrácený obchodní den

**Žádný test nesmí záviset na stáhnutém dumpu** — jinak je sada nespustitelná na čistém stroji a v CI.

### Sada podle typu

| Co | Jak |
|---|---|
| Validační pravidla | každé samostatně, 2–5 barů, oba směry (najde / nenajde) |
| Source adaptéry | proti skutečnému výřezu formátu (~20 řádků reálného CSV, commitnuté) |
| API adaptéry | `Http::fake` s nahranou odpovědí; žádná síť v testech |
| Adjustment faktory | golden test na známém reálném splitu — upravená řada nesmí mít v den ex-date nespojitost |
| Point-in-time univerzum | členství k datu D počítané nad zkrácenými daty (≤ D) se musí rovnat členství k D nad plnými daty |
| Survivorship | delistovaný instrument musí být členem k datům před delistingem — chyba, kterou lidé shipnou, je retroaktivní vyloučení |
| Idempotence | tentýž bulk soubor dvakrát → identický počet řádků, druhý běh hlásí nula insertů |
| Resumabilita | přerušený import + resume se musí rovnat čistému plnému importu |
| Parquet kontrakt | PHP vyexportuje, Python přes DuckDB přečte a ověří schéma, počet řádků a kontrolní součet hodnot |

Test point-in-time univerza je jádro sady: **look-ahead bias se nedá otestovat pohledem do kódu, ale dá se otestovat srovnáním.** Když vyjde stejné členství z dat, kde budoucnost fyzicky není, nemůže ji implementace používat. Levný test hlídající nejdražší chybu v projektu.

Parquet kontraktní test je jediný, který přesahuje jazykovou hranici — běží v `research` kontejneru a je jediné místo, kde se pozná, že se PHP export a Python čtení rozešly. Proto je povinný, ne volitelný.

### Příklady pojmenování

```php
#[CoversClass(OhlcConsistencyRule::class)]
final class OhlcConsistencyRuleTest extends TestCase
{
    public function testCheck(): void
    public function testCheckLowAboveOpen(): void
    public function testCheckNegativePrice(): void
    public function testCheckEmptySet(): void
}

AdjustmentFactorCalculatorTest
    testCalculate / testCalculateSplitOnly / testCalculateSplitAndDividend / testCalculateNoActions

UniverseMemberResolverTest
    testResolve / testResolveDelistedInstrument / testResolveTruncatedHistory / testResolveRecycledSymbol

BulkCsvImporterTest
    testImport / testImportDuplicateFile / testImportInvalidCsvHeaderExceptionThrow
```

### Pravidla, která zatím nedopadají

Povinné `fake()` + `testTransform` u ResponseData a `fake()` + `testValidate` u RequestData platí pro `Http/Responses` a `Http/Requests`. Podprojekt 1 HTTP vrstvu nemá, takže tato pravidla naběhnou až s API v pozdějších podprojektech. Normalizovaná DTO (`BarData`, `CorporateActionData`) `fake()` mít budou jako fixture, ale test na `fake()` je podle guidelines volitelný.

### Výkon patří mimo testovací sadu

Příkaz `data:benchmark` s uloženou baseline — regrese z minut na hodiny musí být vidět, ale v CI by to byl flaky test závislý na stroji.

### Python strana

`pytest` v `research` kontejneru, zatím jen pro čtecí vrstvu a kontraktní test. Plná sada přijde s podprojektem 4.

## Enforcement konvencí

`phpstan.neon` a `phpcs.xml` musí být nastavené od začátku — 120 znaků na řádek, typované konstanty, `=== false` / `empty($x) === false`, `new Class()->method()`, null-first uniony, zákaz vnořených `if`. Bez enforcementu v CI jsou guidelines dokument, který se po měsíci neplní.

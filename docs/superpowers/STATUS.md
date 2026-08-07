# Stav projektu a předání

Poslední aktualizace: 2026-08-07

## Co to je

Research platforma pro hledání swing strategií na US akciích, která ve finální fázi obchoduje plně automaticky přes Alpaca API. Jádrem je backtest engine, ne exekuce — exekuce je poslední fáze.

**Rozdělení rolí:** Python vlastní indikátory, strategie, backtest a rozhodnutí. PHP vlastní data, orchestraci, exekuci, reconciliaci, audit a monitoring. Rozhraním mezi nimi je Parquet (data) a tabulka cílových pozic (rozhodnutí).

## Stav k dnešnímu dni

**Dokumentace: kompletní. Kód: Tasky 1–4 plánu podprojektu 1 hotové** — Laravel 13 skeleton s vynucenými konvencemi, testovací helpery (`EloquentMatcher`, `CollectionMatcher`, `DataMatcher`, `spyLogger`), security master (`instruments`, `instrument_symbols`, `SymbolResolver`) burzovní kalendář (`market_days`, `AlpacaCalendarSource`, `CalendarImporter`, `market-data:import-calendar`) partitionované tabulky barů (`daily_bars`, `intraday_bars`, `BarData`, `PartitionManager`, `market-data:ensure-partitions`) a corporate actions (`corporate_actions`, `CorporateActionTypeEnum`, `CorporateActionData`). Tasky 7–23 nezačaté.

```
docs/superpowers/specs/2026-08-06-market-data-design.md          podprojekt 1
docs/superpowers/specs/2026-08-06-indicators-design.md           podprojekt 2
docs/superpowers/specs/2026-08-06-strategy-definition-design.md  podprojekt 3
docs/superpowers/specs/2026-08-07-backtest-engine-design.md      podprojekt 4
docs/superpowers/specs/2026-08-07-research-workflow-design.md    podprojekt 5
docs/superpowers/specs/2026-08-07-paper-execution-design.md      podprojekt 6
docs/superpowers/specs/2026-08-07-live-execution-design.md       podprojekt 7
docs/superpowers/plans/2026-08-06-market-data.md                 plán podprojektu 1, 23 tasků
```

Git: branch `main`, remote `origin` = https://github.com/simako12-debug/forex.git. Tři commity:
- `ef35bd4` — specifikace podprojektu 1 + `.claude` konfigurace zkopírovaná z monorepa
- `ce7adbc` — specifikace podprojektů 2–7 + implementační plán podprojektu 1
- `9314274` — tento dokument

Plány podprojektů 2–7 **neexistují**.

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
| `worker` | za profilem `worker` — `docker compose --profile worker up -d` až po Tasku 1 |
| Redis | **není v sestavě**; Task 23 Step 3 to sám připouští, v plánu 1 jedou locky i fronty nad `database` storem. Přijde s podprojektem 4 |

Ověřené kontrakty: PHP → Postgres přes PDO, Python → Postgres přes psycopg, DuckDB `postgres` extension čte Postgres (na tom stojí Task 21), sdílený volume `/shared` je zapisovatelný z `app` i `research`, bind mount repa je zapisovatelný.

**Žádný krok se sudo není potřeba.** Role, obě databáze i extensions vznikají při `docker compose up`.

### Co je na hostu (mimo Docker)

PHP 8.4.3 a Composer 2.8.5 jsou nainstalované, ale **plán vyžaduje PHP 8.5** — host je proto na vývoj nepoužitelný a všechno jde přes `app` kontejner. `psql` na hostu není; klient je v `postgres` kontejneru.

### Obsazené porty

Na stroji běží další projekty (`stockmanager`, `wealthtracker`, `trading-*`). Obsazené je mimo jiné **5432**, proto Postgres této sestavy poslouchá na **5433**. Při přidávání služeb ověřit port předem.

## Jak pokračovat — čtyři možnosti

### 1. Spustit plán podprojektu 1 — doporučeno

Odpracovat `docs/superpowers/plans/2026-08-06-market-data.md` od Tasku 1. Plán je psaný tak, aby ho šlo vykonávat task po tasku bez dalšího rozhodování; každý task končí vlastním commitem.

**Proč právě tohle první:** plán podprojektu 2 se opírá o skutečné schéma Parquetu, které vzniká až v Tasku 21 tohoto plánu. Napsat ho dřív znamená hádat názvy sloupců a tvar dat, které za dva dny vygeneruje kód.

**Proč to nezdržuje volba brokera:** Alpaca je v plánu 1 jen na dvou místech (kalendář, inkrementální ingest) a obojí je za portem s vyměnitelným adaptérem. I kdyby se ukázalo, že účet z ČR nejde otevřít, přepíše se jeden adaptér, ne datový sklad.

Instrukce pro novou session:

> Vykonej implementační plán `docs/superpowers/plans/2026-08-06-market-data.md` od Tasku 1. Použij skill `superpowers:subagent-driven-development` nebo `superpowers:executing-plans`. Nejdřív si přečti `docs/superpowers/STATUS.md`.

### 2. Ověřit externí neznámé

Fakta, která specifikace vědomě neobsahují, protože to nejsou návrhová rozhodnutí. Žádné z nich neblokuje plán 1, ale všechna budou potřeba dřív nebo později:

- **Přijímá Alpaca aktuálně účty z ČR?** Seznam podporovaných zemí se mění. Blokuje podprojekty 6–7.
- **Povolují podmínky brokera automatizované obchodování přes API?** U Alpaky a IBKR se to čeká, u XTB spíš ne.
- **Cena a přesný rozsah bulk dumpů** — FirstRateData (16 272 tickerů, z toho 7 000+ delistovaných, denní od 2000) a HistoricalData.net (denní i 1min od 2003, 50 000+ delistovaných symbolů). Potřeba pro Task 15 na reálných datech.
- **Aktuální sazby SEC Section 31 fee a FINRA TAF.** Backtest bez nich má podle specifikace **selhat**, ne použít nulu. Potřeba pro podprojekt 4.

### 3. Napsat plán podprojektu 2

Implementační plán indikátorové vrstvy. Nevýhoda je popsaná v možnosti 1 — část plánu by byla odhad k přepisu.

### 4. Projít specifikace a měnit rozhodnutí

Měnit návrhová rozhodnutí je teď zdarma, po implementaci ne. Nejcitlivější místa, kde by změna měla největší dopad:

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

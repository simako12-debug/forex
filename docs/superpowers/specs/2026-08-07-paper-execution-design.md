# Paper execution — design podprojektu 6

Datum: 2026-08-07
Stav: navržen, čeká na review
Závisí na: [podprojekt 1 — Market Data](2026-08-06-market-data-design.md), [podprojekt 3 — Definice strategie](2026-08-06-strategy-definition-design.md), [podprojekt 4 — Backtest engine](2026-08-07-backtest-engine-design.md)

## K čemu tento podprojekt je

Skutečným účelem paper fáze **není otestovat strategii** — to udělal backtest. Účelem je otestovat **automatizaci**: že se objednávky posílají správně, že se neposílají dvakrát, že se stav po restartu srovná, že stopy skutečně existují tam, kde si systém myslí, a že to, co se reálně stane, odpovídá tomu, co backtest předpovídal.

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Denní cyklus | **dva kroky** — výpočet po závěru, odesílání před otevřením | selhání výpočtu nebrání opakování, každý krok je samostatně spustitelný a mezi nimi je místo na health check |
| Stopy a odprodeje | **nativně u brokera** jako GTC příkazy | při výpadku systému nebo sítě zůstávají pozice chráněné; u něčeho, co bude obchodovat reálné peníze, to nemá alternativu |
| Správa příkazů | **deklarativní žádoucí stav + smiřovač** | jediný způsob, jak korektně zvládnout částečné odprodeje, částečné fily, restarty a zmeškané eventy |
| Zdroj pravdy o pozicích | **broker**, ne naše databáze | naše databáze může být zastaralá; peníze jsou u brokera |
| Měření divergence | **denní shadow backtest, srovnání obchod po obchodu** | systémovou chybu (například špatně převedenou stop cenu) zjistíš v řádu dne, ne měsíců |
| Ukončení paper fáze | **předem zapsaná kritéria** | přechod na reálné peníze je nejrizikovější moment projektu a „už to vypadá dobře" není kritérium |

## Rozsah

### Vevnitř

- Kontrakt cílových pozic mezi Pythonem a PHP
- Dvoukrokový denní cyklus řízený burzovním kalendářem
- Deklarativní model žádoucího stavu příkazů a smiřovač
- Idempotentní odesílání objednávek
- Zpracování fillů přes WebSocket a přes periodickou reconciliaci
- Převod stopových úrovní z upravených cen na surové
- Shadow backtest a detekce divergence
- Předem zapsaná kritéria ukončení paper fáze

### Mimo

Rizikové limity a kill-switch (podprojekt 7 — tady platí jen limity spočítané risk vrstvou z podprojektu 4). Reálné peníze.

### Kritérium hotovosti

Systém běží tři měsíce na paper účtu bez zásahu, u každé objednávky je dohledatelné, které rozhodnutí ji způsobilo, po vynuceném restartu v polovině cyklu se stav sám srovná bez duplicitní objednávky, a denní srovnání se shadow backtestem drží odchylku fillů pod zapsaným prahem.

## Kontrakt cílových pozic

Rozhraní mezi Pythonem (rozhoduje) a PHP (vykonává). Tabulka, kterou vlastní Laravel migrace a zapisuje do ní Python.

```
target_positions   id, run_id, strategy_name, strategy_version, strategy_code_hash,
                   as_of_date, instrument_id,
                   action (open | close | scale_out),
                   shares, limit_price, stop_price_raw, stop_price_adjusted,
                   score, score_rank, created_at
                   └─ unique (strategy_name, as_of_date, instrument_id, action)
```

`unique` je základ idempotence na úrovni rozhodnutí: dvakrát spuštěný výpočet nemůže vytvořit dvě různá zadání pro stejný instrument a den.

`stop_price_raw` i `stop_price_adjusted` jsou tam obojí záměrně — viz níže.

## Převod stopů z upravených cen na surové

Backtest počítá v upravených cenách (podprojekt 4), broker přijímá jen surové. Převod je jeden dělitel, ale je to místo, kde se dá udělat tichá chyba s velkými následky: stop o 30 % vedle se projeví jako pozice, která se zavře hned, nebo která není chráněná vůbec.

```
stop_price_raw = stop_price_adjusted × cum_split_factor(instrument, dnes)
                                     ÷ cum_div_factor(instrument, dnes)
```

Obě hodnoty se ukládají a **shadow backtest je porovnává** — to je ta věc, kterou by měsíční srovnání agregovaných metrik nezachytilo.

## Denní cyklus

Časy se odvozují z `market_days`, **nikdy z pevné hodiny v cronu**. Důvod: evropský a americký přechod na letní čas se rozchází o dva týdny ročně, takže pevná hodina v CET by dvakrát ročně na dva týdny běžela o hodinu vedle.

```
KROK 1 — po závěru (close_at + 60 min podle kalendáře)
  1. inkrementální ingest denních barů (podprojekt 1)
  2. validace; při findingu severity error pro instrument v portfoliu → alert a STOP
  3. přegenerování Parquet snapshotu
  4. výpočet score a signálů (Python)
  5. zápis target_positions
  6. shadow backtest a srovnání s dneškem
  7. zápis health záznamu

KROK 2 — před otevřením (open_at − 30 min podle kalendáře)
  1. health check: existuje dnešní target_positions? proběhl krok 1?  Když ne → alert a STOP
  2. dotaz na brokera: aktuální pozice, hotovost, otevřené příkazy
  3. výpočet žádoucího stavu příkazů
  4. smíření: zruš přebývající, doplň chybějící, uprav nesouhlasné
  5. zápis auditu
```

**Krok 2 nikdy nepracuje z naší představy o pozicích.** Vždycky se nejdřív zeptá brokera. Kdyby se spoléhal na naši databázi, jakýkoliv zmeškaný fill by se propsal do špatně velké objednávky.

## Žádoucí stav příkazů a smiřovač

Částečné odprodeje se do jednoho bracket příkazu nevejdou — Alpaca zvládne na pozici jeden take-profit a jeden stop. Imperativní správa („po odprodeji zruš stop a založ nový na menší počet kusů") vytváří okno, kdy stop hlídá víc kusů, než držíš, a po restartu v nesprávném momentě nikdo neví, co má existovat.

Řešením je **deklarativní model**: systém z `ExitPolicy`, aktuálního počtu kusů a průměrné vstupní ceny spočítá, jaké příkazy u brokera **mají** existovat, a smiřovač narovná rozdíl proti skutečnosti.

```
Pro každou otevřenou pozici je žádoucí stav:
  - jeden STOP příkaz  na  celý držený počet kusů  na  stop_price_raw
  - jeden LIMIT příkaz na  podíl podle nejbližšího nesplněného scale_out

Smiřovač porovná žádoucí stav se skutečnými otevřenými příkazy a:
  - chybějící příkaz založí
  - přebývající příkaz zruší
  - příkaz s jiným počtem kusů nebo cenou zruší a založí znovu
  - PORADÍ: nejdřív zakládá STOP, pak ruší přebývající — nikdy naopak
```

To pořadí v posledním bodě je celá pointa: **ochrana pozice nesmí ani na okamžik zmizet.** Krátkodobě existující duplicitní stop je nepříjemnost (broker odmítne přeprodej), chybějící stop je riziko.

Smiřovač je navíc řešením problémů, které by jinak potřebovaly vlastní obsluhu:

- **Restart uprostřed cyklu** — po restartu se prostě spočítá žádoucí stav a srovná.
- **Zmeškaný WebSocket event** — periodické smíření to dohoní.
- **Split během držení** — po přegenerování adjustment faktorů se `stop_price_raw` změní, smiřovač stop přeloží. Není potřeba věřit tomu, že broker upraví otevřené příkazy sám.
- **Částečný fill** — žádoucí stav se počítá z reálně drženého počtu kusů, takže se dorovná samo.

Smiřovač běží v kroku 2, po každém příchozím fillu z WebSocketu, a periodicky během obchodní session.

## Idempotence objednávek

Dvě vrstvy, obě potřebné:

1. **Deterministický `client_order_id`** složený z `strategy_name`, `as_of_date`, `instrument_id`, druhu nohy a pořadí. Broker druhé odeslání téhož ID odmítne, i kdyby naše databáze o prvním nevěděla.
2. **Lokální záznam** v `orders` s tímtéž ID jako unique klíčem.

První vrstva chrání proti stavu, kdy se objednávka odeslala, ale odpověď se nevrátila (timeout) — nejnepříjemnější případ, protože lokální záznam chybí, ale objednávka existuje. Bez deterministického ID by opakování vyrobilo duplikát.

## Datový model

```
orders           id, client_order_id (unique), broker_order_id, instrument_id,
                 target_position_id, side, order_type, shares, limit_price,
                 stop_price, status, submitted_at, filled_at,
                 filled_shares, avg_fill_price, rejected_reason

order_events     id, order_id, event_type, payload (jsonb), occurred_at, received_at
                 └─ append-only surový audit všeho, co přišlo z brokera

position_snapshots  as_of, instrument_id, shares, avg_entry_price, market_value,
                    source (broker), taken_at

reconciliations  id, ran_at, orders_created, orders_cancelled, orders_replaced,
                 mismatches (jsonb)

divergences      id, as_of_date, instrument_id, kind, expected, actual,
                 magnitude, exceeded_threshold (bool)
```

`order_events` je záměrně append-only a surový: když se něco pokazí, je to jediný záznam, který nelže. `received_at` vedle `occurred_at` proto, aby šlo poznat zpoždění nebo přeuspořádání eventů.

## Shadow backtest a detekce divergence

Každý den po kroku 1 se pustí backtest nad stejným obdobím jako běžící paper a porovná se **obchod po obchodu**:

| Druh divergence | Co se porovnává | Práh (konfigurovatelný) |
|---|---|---|
| `signal_set` | množina signálů backtestu vs. skutečně zadaných | jakýkoliv rozdíl je nález |
| `fill_price` | očekávaná vs. skutečná fill cena | odchylka v bazických bodech |
| `stop_price` | `stop_price_adjusted` převedený vs. stop u brokera | jakýkoliv rozdíl je nález |
| `missing_trade` | backtest obchodoval, paper ne | jakýkoliv výskyt |
| `extra_trade` | paper obchodoval, backtest ne | jakýkoliv výskyt |
| `shares` | očekávaný vs. skutečný počet kusů | jakýkoliv rozdíl je nález |

Nález nad prahem posílá alert. Nálezy se nezavírají automaticky — musí je někdo vyřešit, a počet nevyřešených je jedno z kritérií ukončení paper fáze.

**Živý provoz zná skutečné pořadí událostí**, které backtest u denních barů musel odhadovat. Paper fáze proto zároveň dává první měření toho, kolik pesimistický předpoklad „stop vyhrál" reálně stojí.

## Kritéria ukončení paper fáze

Soubor `acceptance/paper_{strategy_name}.yaml`, commitnutý dřív než se paper spustí:

```yaml
strategy: pullback_v1
criteria:
  min_calendar_days: 90
  min_closed_trades: 20
  max_fill_price_divergence_bps: 25
  max_unresolved_divergences: 0
  max_duplicate_orders: 0
  max_unprotected_position_minutes: 0
  min_reconciliation_success_rate: 1.0
  max_missed_cycles: 0
```

`max_unprotected_position_minutes: 0` a `max_duplicate_orders: 0` jsou nulové záměrně. Nejde o statistiku — jsou to vlastnosti, které buď systém má, nebo nemá, a jedna výjimka znamená, že nemá.

## Testování

| Co | Jak |
|---|---|
| Smiřovač | žádoucí a skutečný stav jako vstupní data, ověření vypočtených akcí; každý případ (chybí, přebývá, jiný počet kusů, jiná cena) samostatně |
| Pořadí akcí | smiřovač zakládá STOP **před** rušením přebývajícího — test na posloupnost, ne jen na výsledek |
| Idempotence | dvojí odeslání téhož `client_order_id` → jedna objednávka; simulovaný timeout bez lokálního záznamu → žádný duplikát |
| Restart uprostřed cyklu | přerušení po odeslání části objednávek, opakování → shodný koncový stav, nula duplikátů |
| Částečný fill | fill poloviny → žádoucí stav se přepočte na skutečný počet kusů |
| Odprodej a zmenšení stopu | fill limitního odprodeje → stop se přeloží na zbytek; mezi tím nikdy nesmí chybět |
| Split během držení | změna adjustment faktorů → přeložený stop se změní; test na správný převod adjusted → raw |
| Broker jako zdroj pravdy | naše databáze tvrdí jinou pozici než broker → vyhrává broker, zapíše se nesoulad |
| Kalendář vs. cron | zkrácený obchodní den a přechod na letní čas → kroky běží podle kalendáře, ne podle pevné hodiny |
| Detekce divergence | každý druh nálezu samostatně, oba směry (pod prahem / nad prahem) |
| Alpaca adaptér | `Http::fake` a nahrané odpovědi včetně odmítnutí a částečných filů; **žádná síť v testech** |

## Rizika

**Okno bez ochrany je nejhorší selhání, jaké tady může nastat.** Pořadí akcí ve smiřovači ho řeší, ale je to invariant, který se musí testovat explicitně a nesmí ho nikdo „zjednodušit" na zruš-a-založ.

**Zdvojená objednávka po timeoutu.** Deterministický `client_order_id` to řeší, ale jen pokud se generuje čistě z rozhodnutí — jakákoliv náhodná složka nebo časová značka v něm tu ochranu zruší.

**Nesoulad mezi upravenými a surovými cenami.** Převod je jeden dělitel a vypadá triviálně, proto se snadno udělá obráceně. Shadow backtest ho porovnává denně; bez toho by to byla chyba viditelná až na výsledcích.

**Dividendy v paper účtu rozbijí naivní srovnání equity.** Broker je připíše jako hotovost, backtest je má v upravených cenách. Srovnání proto musí být na úrovni total return, ne absolutních hodnot účtu — jinak bude divergence vypadat všude, kde žádná není.

**Zmeškaný cyklus je tichá porucha.** Když krok 1 neproběhne, krok 2 nemá co poslat a systém prostě neobchoduje. To samo o sobě není nebezpečné, ale ztratíš signály a nepoznáš to. Proto `max_missed_cycles: 0` a health záznam na konci každého kroku.

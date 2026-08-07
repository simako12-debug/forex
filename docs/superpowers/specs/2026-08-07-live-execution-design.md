# Live execution, risk a monitoring — design podprojektu 7

Datum: 2026-08-07
Stav: navržen, čeká na review
Závisí na: [podprojekt 6 — Paper execution](2026-08-07-paper-execution-design.md)

## K čemu tento podprojekt je

Od podprojektu 6 se liší jedinou věcí: chybami se platí. Veškerý obsah je proto o tom, **co se stane, když něco nefunguje** — ne o tom, co se stane, když všechno funguje. To už umí paper.

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Kill-switch | **zastavit nové vstupy, výstupy dál spravovat**; zamrznutí a likvidace jen ručně | automatická likvidace v panice prodává na dně, a když limit poruší chyba v kódu nebo špatná data, způsobila by chyba měření skutečnou ztrátu |
| Automatické spouštěče | denní ztráta, drawdown od maxima, nesoulad s brokerem, stará či neplatná data | první dvě jsou tržní riziko, druhé dvě jsou důkaz, že systém neví, co dělá — a to je horší |
| WebSocket listener | **PHP s ReactPHP** | fill se zapíše a hned zavolá smiřovač, který je PHP; exekuce zůstává v jednom jazyce, žádný mezijazykový skok v nejcitlivější cestě |
| Náběh kapitálu | **stupňovitě podle zapsaných pravidel** | první měsíce reálného provozu odhalí věci, které paper neodhalí (skutečný slippage, skutečné fily v otevírací aukci), a při malém objemu je ta lekce levná |

## Rozsah

### Vevnitř

- Rizikové limity a jejich průběžné vyhodnocování
- Kill-switch: perzistentní stav, spouštěče, ruční reset s důvodem
- WebSocket listener na trade updates s reconnectem a heartbeatem
- Zajištění jediné běžící instance
- Monitoring, alerting a denní přehled
- Auditní řetěz od rozhodnutí k objednávce
- Pravidla stupňovitého náběhu kapitálu
- Runbook pro havarijní scénáře

### Mimo

Strategie, backtest, research. Změny v datové vrstvě.

### Kritérium hotovosti

Systém obchoduje reálné peníze v prvním kapitálovém stupni; každou objednávku lze dohledat až k research běhu, který ji způsobil; vynucený restart, odpojení sítě a nedostupnost brokerova API skončí v definovaném stavu, ne v nedefinovaném; a porušení každého ze čtyř spouštěčů skutečně zastaví nové vstupy — ověřeno testem, ne úsudkem.

## Rizikové limity a kill-switch

### Stav obchodování je perzistentní

```
trading_state   id, state (active | entries_blocked | frozen),
                changed_at, changed_by (system | human),
                trigger (daily_loss | drawdown | broker_mismatch | stale_data | manual),
                reason, resolved_at, resolved_by, resolution_note
                └─ append-only; aktuální stav je poslední řádek
```

**Stav nesmí být v paměti procesu.** Kdyby byl, restart by kill-switch zrušil — což je přesně ten okamžik, kdy je nejvíc potřeba. Append-only tabulka navíc dává historii, kdy a proč se obchodování zastavilo.

### Semantika stavů

| Stav | Nové vstupy | Správa výstupů | Kdo nastavuje |
|---|---|---|---|
| `active` | ano | ano | — |
| `entries_blocked` | **ne** | ano | systém automaticky, nebo člověk |
| `frozen` | ne | **ne** (pozice chrání nativní stopy u brokera) | **jen člověk** |

Likvidace celého portfolia není stav — je to ruční operace s vlastním příkazem a vlastním potvrzením. Automaticky ji nespustí nic.

### Spouštěče

| Spouštěč | Podmínka | Poznámka |
|---|---|---|
| `daily_loss` | propad equity za obchodní den nad prahem | chytá tržní šok i rozbitý sizing |
| `drawdown` | propad od dosavadního maxima nad prahem | chytá pomalé zhoršování — tedy strategii, která přestala fungovat |
| `broker_mismatch` | reconciliace najde pozici, o které systém neví, nebo chybějící stop | není tržní riziko, ale důkaz, že systém neví, co dělá |
| `stale_data` | neproběhl denní ingest, validace hlásí `error` na instrumentu v portfoliu, nebo chybí platný sazebník poplatků | obchodovat na datech, kterým nevěříš, je horší než neobchodovat |

Prahy jsou konfigurovatelné, ale jejich hodnoty se **zapisují do `trading_state` při každém spuštění spouštěče**, aby šlo zpětně zjistit, proti jaké hodnotě se to tehdy měřilo.

### Vyhodnocení musí selhat na bezpečnou stranu

Nejdůležitější pravidlo celého podprojektu: **když systém nedokáže limity vyhodnotit, chová se, jako by byly porušené.** Nedostupné brokerovo API, chybějící equity, neúspěšná reconciliace — to všechno vede na `entries_blocked`, ne na „zatím nic neblokuju, protože nevím".

Opačné chování je nejběžnější způsob, jak automatizovaný systém způsobí škodu: neví, co se děje, a proto pokračuje.

### Reset vyžaduje důvod

Přechod z `entries_blocked` zpět na `active` může udělat **jen člověk** a příkaz vyžaduje textový důvod, který se ukládá do `resolution_note`. Není to byrokracie — je to jediná obrana proti tomu, aby se kill-switch resetoval reflexivně, bez zjištění příčiny.

## WebSocket listener

Dlouhoživý PHP proces (ReactPHP) přihlášený k Alpaca trade updates. Přijme event, zapíše ho do `order_events` a spustí smiřovač pro dotčenou pozici.

**Klíčová návrhová vlastnost: listener je zrychlovač, ne zdroj pravdy.** Periodická reconciliace z podprojektu 6 běží dál nezávisle na něm. Mrtvý listener tedy zhorší **latenci** reakce na fill, ne správnost stavu. To je záměrné — dlouhoživý proces je nejméně spolehlivá část systému a nesmí na něm nic viset.

Provozní požadavky:

- **Reconnect s exponenciálním backoffem** a stropem; každý pokus se loguje.
- **Heartbeat** — listener zapisuje známku života; její absence nad prahem je alert, ale nespouští kill-switch (protože správnost tím netrpí).
- **Monitoring paměti** — dlouhoživý PHP proces se restartuje po překročení prahu, protože kontrolovaný restart je lepší než OOM v nevhodný moment.
- **Supervize** — proces se po smrti restartuje sám (systemd nebo ekvivalent).

## Jediná běžící instance

Dvě současně běžící instance exekučního kroku by poslaly každou objednávku dvakrát. Deterministický `client_order_id` z podprojektu 6 to zachytí u brokera, ale spoléhat na to jako na jedinou obranu je slabé.

Každý exekuční krok proto drží **výhradní lock** s dobou platnosti delší, než je jeho maximální doba běhu. Nezískání locku není chyba — krok se prostě neprovede a zapíše se, že už běží jinde.

## Stupňovitý náběh kapitálu

Soubor `acceptance/live_{strategy_name}.yaml`, commitnutý dřív, než se první stupeň spustí:

```yaml
strategy: pullback_v1
tiers:
  - capital: 5000
    min_calendar_days: 60
    min_closed_trades: 15
  - capital: 20000
    min_calendar_days: 90
    min_closed_trades: 30
  - capital: 60000
    min_calendar_days: 120
    min_closed_trades: 50
promotion_criteria:
  max_fill_price_divergence_bps: 30
  max_unresolved_divergences: 0
  max_kill_switch_events: 0
  min_reconciliation_success_rate: 1.0
```

Postup na další stupeň je **ruční příkaz**, který ověří kritéria a odmítne, když nejsou splněná. Systém kapitál nenavyšuje sám.

`max_fill_price_divergence_bps` je v live volnější než v paper (30 vs. 25) záměrně: paper fily jsou simulované brokerem a v reálné otevírací aukci je rozptyl větší. Tohle číslo je zároveň první věc, kterou se z prvního stupně naučíš — a pak se má upravit podle měření, ne podle odhadu.

## Monitoring a alerting

| Co se hlídá | Selhání znamená | Severita |
|---|---|---|
| Dokončení kroku 1 a kroku 2 | systém neobchoduje a ztrácí signály | kritická |
| Úspěšnost reconciliace | systém neví, co drží | kritická |
| Pozice bez stopu | nechráněná expozice | kritická |
| Stav kill-switche | obchodování zastavené | kritická |
| Nevyřešené divergence | něco se rozchází s backtestem | vysoká |
| Heartbeat listeneru | zpomalená reakce na fill | střední |
| Svěžest dat | blíží se `stale_data` spouštěč | vysoká |
| Pokrytí sazebníku poplatků | backtest i live přestanou počítat náklady | střední |

Kanály jsou konfigurovatelné; minimum je **jeden push kanál pro kritické** (aby dorazil do telefonu) a **e-mailový denní přehled** se stavem účtu, otevřenými pozicemi, provedenými obchody a otevřenými nálezy. Denní přehled není luxus — je to způsob, jak si všimneš, že něco nedorazilo.

## Auditní řetěz

Od rozhodnutí k objednávce musí vést nepřerušená cesta:

```
research_run  →  target_position  →  order  →  order_events  →  fill
     │               │                 │
     │               │                 └─ client_order_id (deterministický)
     │               └─ strategy_code_hash, as_of_date, score, score_rank
     └─ data_snapshot_version, feature_ids, cost_params, risk_params
```

Požadavek, který z toho plyne: u **každé** objednávky v systému musí být možné odpovědět na otázku „proč tato objednávka existovala" bez odhadování. Když to u nějaké cesty nejde, je to chyba návrhu, ne chybějící report.

## Runbook havarijních scénářů

Ke každému scénáři patří, jak se pozná a co se udělá. Runbook je součástí repozitáře, ne ústní znalosti.

| Scénář | Detekce | Akce |
|---|---|---|
| Systém neběžel při otevření trhu | chybí health záznam kroku 2 | pozice byly chráněné nativními stopy; po startu smiřovač srovná stav; signály toho dne se **nedohánějí** (příležitost propadla, vstup by byl za jinou cenu, než backtest předpokládal) |
| Brokerovo API nedostupné | selhané dotazy | `entries_blocked` (fail-safe), retry s backoffem, alert; stopy u brokera platí dál |
| Nesoulad pozic s brokerem | reconciliace | `entries_blocked`, alert, **ruční** vyšetření; nikdy automatická korekce pozice |
| Objevena chybná stop cena | shadow backtest, `stop_price` divergence | smiřovač stop přeloží; zapsat nález a najít příčinu v převodu adjusted → raw |
| Objevena duplicitní objednávka | unique konflikt nebo odmítnutí brokerem | alert; zkontrolovat generování `client_order_id`, protože je to porušení invariantu |
| Mrtvý listener | chybí heartbeat | restart procesu; správnost stavu tím netrpí, protože periodická reconciliace běží dál |
| Stará data | `data:health` | `entries_blocked`, doplnit ingest, ověřit validaci, ručně uvolnit s důvodem |
| Strategie překročila drawdown | limit | `entries_blocked` automaticky; **rozhodnutí, jestli strategii vypnout úplně, je lidské** a patří do research cyklu, ne do exekuce |

## Testování

| Co | Jak |
|---|---|
| Každý spouštěč | samostatně, oba směry (pod prahem / nad prahem) → správný cílový stav |
| Fail-safe | nedostupný broker, chybějící equity, selhaná reconciliace → `entries_blocked`, **nikdy** `active` |
| Perzistence kill-switche | restart aplikace nesmí stav vrátit na `active` |
| Reset | reset bez důvodu selže; reset systémem (ne člověkem) selže |
| Semantika stavů | v `entries_blocked` se nová objednávka nepošle, ale stop a odprodej se spravují dál |
| `frozen` | nespustí ho žádný automatický spouštěč — test na to, že to nelze |
| Jediná instance | druhý souběžný krok lock nezíská a neprovede se |
| Listener reconnect | přerušené spojení → backoff a obnovení; zmeškané eventy dohoní reconciliace |
| Listener nezávislost | vypnutý listener → stav je po reconciliaci stejný, jen později |
| Auditní řetěz | pro každou objednávku ve fixture existuje úplná cesta až k `research_run` |
| Promoce kapitálu | nesplněná kritéria → příkaz odmítne navýšení |

## Rizika

**Fail-safe se snadno pokazí opravou.** Chování „nevím, tedy blokuji" vypadá při provozu jako falešný poplach a je velké pokušení ho zjemnit. Test na to existuje a komentář u něj musí vysvětlovat, proč tam je.

**Kill-switch, který se resetuje reflexivně, není kill-switch.** Povinný důvod je slabá obrana — skutečná je zvyk zjistit příčinu. Specifikace to nevyřeší, jen to zviditelní v `trading_state`.

**Dlouhoživý PHP proces je nejslabší část systému.** Návrh to obchází tím, že na něm nezávisí správnost, jen latence. Tenhle vztah se nesmí obrátit — kdyby někdo přesunul kritickou logiku do listeneru, ztratí se hlavní pojistka.

**Nedohánění zmeškaných signálů je vědomá volba, která bude bolet.** Když systém den neběžel, propadlé příležitosti se nedohánějí. Bude to vypadat jako ztracený zisk, ale vstup za jinou cenu než tu, se kterou backtest počítal, je jiný obchod — a systematické dohánění by udělalo z živého provozu něco, co backtest nikdy netestoval.

**První kapitálový stupeň odhalí čísla, která jsou dosud odhadem.** Skutečný slippage v otevírací aukci a skutečný rozptyl fillů nejde z paper účtu zjistit. Prahy divergence proto po prvním stupni **musí** projít revizí podle měření — a to je plánovaná činnost, ne reakce na problém.

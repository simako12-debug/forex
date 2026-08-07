# Research workflow — design podprojektu 5

Datum: 2026-08-07
Stav: navržen, čeká na review
Závisí na: [podprojekt 3 — Definice strategie](2026-08-06-strategy-definition-design.md), [podprojekt 4 — Backtest engine](2026-08-07-backtest-engine-design.md)

## K čemu tento podprojekt je

Backtest engine odpovídá na otázku „jak by se tato strategie chovala". Tento podprojekt odpovídá na otázku **„můžu tomu číslu věřit"** — a to je jiná a těžší otázka. Bez něj je celý systém stroj na hledání kombinací parametrů, které dobře vypadaly na historii.

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Dělení dat | **walk-forward + nedotčený finální holdout** | walk-forward ukáže, jestli strategie funguje v různých režimech; nedotčený holdout je jediná věc, která zbude nezaujatá, když se návrh mnohokrát iteroval |
| Mnohonásobné testování | **záznam všech pokusů + deflated Sharpe** | Sharpe 1,5 ze tří pokusů a ze tří tisíc jsou dvě různé zprávy; bez počtu pokusů není číslo interpretovatelné |
| Sweep | **mřížka nad hrubými hodnotami** | strategie má záměrně málo parametrů; plná mřížka ukáže celou plochu výsledků, a plochost optima je sama důležitá informace o robustnosti |
| Akceptace | **předem zapsaná kritéria** | lidský mozek posouvá laty podle výsledku a nevšimne si toho; to je nejběžnější cesta, jak dostat přefitovanou strategii do produkce |
| Ukládání | **Postgres**, migrace vlastní Laravel, řádky zapisuje Python | výsledky se musí dát dotazovat a spojovat s daty; schéma zůstává na jednom místě |

## Rozsah

### Vevnitř

- Definice a vynucení datového rozdělení: walk-forward okna a finální holdout
- Rozpočet na použití holdoutu a jeho vynucení
- Záznam všech běhů včetně metadat pro reprodukovatelnost
- Mřížkový sweep s explicitním stropem počtu kombinací
- Deflated Sharpe a počítadlo pokusů
- Sada robustnostních kontrol
- Předem zapsaná akceptační kritéria a jejich vyhodnocení na pass/fail
- Report na běh

### Mimo

Simulace samotná (podprojekt 4). Skutečné objednávky (podprojekty 6–7). Fitování vah — ve v1 jsou váhy ruční; tento podprojekt jen zajišťuje, aby jejich volba byla point-in-time a zaznamenaná.

### Kritérium hotovosti

Můžu spustit sweep, dostat tabulku výsledků přes všechna walk-forward okna, u každého čísla vidět, kolikátý pokus to byl a jaký je deflovaný Sharpe — a když spustím vyhodnocení akceptačních kritérií, systém řekne prošlo/neprošlo podle souboru, který byl commitnutý **před** tím během. Použití holdoutu bez zbývajícího rozpočtu skončí chybou.

## Rozdělení dat

```
2000 ────────────────────────────────── 2023-08 ──────── 2026-08
│              walk-forward zóna                │  HOLDOUT      │
│  train 3 roky → test 1 rok → posun o 1 rok    │  NEDOTČENÝ    │
```

**Walk-forward zóna** (vše kromě posledních tří let): okna se posouvají o rok, trénovací okno 3 roky, testovací 1 rok. Parametry se vybírají na trénovacím okně, vyhodnocují na testovacím. Výsledná OOS metrika je agregace přes všechna testovací okna, ne nejlepší z nich.

Délky oken jsou konfigurovatelné, ale **konfigurace se ukládá ke každému běhu** — jinak by se okna dala tiše měnit, dokud výsledek nevyjde.

**Finální holdout** (poslední tři roky): nepoužívá se vůbec, dokud není strategie hotová.

### Rozpočet na holdout

Holdout ztrácí smysl v momentě, kdy se na něj podíváš, upravíš návrh a podíváš se znovu. Systém to proto **omezuje mechanicky**:

- Tabulka `holdout_uses` zaznamenává každé použití: datum, verze a hash strategie, důvod, výsledek.
- Rozpočet je **3 použití na strategii** (konfigurovatelné, ale ne za běhu).
- Čtvrté spuštění na holdoutu **selže s chybou**, ne s varováním.
- Rozpočet se neresetuje změnou verze strategie — počítá se na `name`, ne na `version`. Jinak by šel obejít přejmenováním.

Tohle je záměrně nepříjemné. Nepohodlí je celý mechanismus.

## Záznam pokusů a reprodukovatelnost

Každý běh backtestu se zapíše, i ten neúspěšný. Bez toho není počet pokusů znám a deflated Sharpe se nedá spočítat.

```
research_runs      id, strategy_name, strategy_version, strategy_code_hash,
                   weights_as_of, universe_definition, universe_version,
                   data_snapshot_version, feature_ids (jsonb),
                   cost_params (jsonb), risk_params (jsonb),
                   walk_forward_config (jsonb), grid_definition_hash,
                   research_code_commit, window_kind (train|test|holdout),
                   period_start, period_end, created_at

research_metrics   run_id, metric, value

holdout_uses       id, strategy_name, strategy_version, strategy_code_hash,
                   reason, used_at, passed (bool)

acceptance_results run_id, criterion, threshold, actual, passed (bool)
```

`data_snapshot_version` je klíčová položka, která se snadno opomene: když se přegenerují Parquet snapshoty po revizi dat, staré běhy přestanou být srovnatelné s novými. Verze snapshotu to udělá viditelným.

`research_code_commit` je git SHA adresáře `research/`. Bez něj nejde po měsících zjistit, jestli rozdíl mezi dvěma běhy způsobila změna parametrů nebo změna kódu.

## Mřížkový sweep

Definice sweepu je soubor, který se commituje:

```yaml
strategy: pullback_v1
grid:
  entry.value: [10, 20, 30]
  exit.initial_stop.value: [1.5, 2.0, 2.5, 3.0]
  exit.max_holding_days: [5, 10, 15]
  components.trend.weight: [1, 2, 3]
```

Před spuštěním systém **vypíše počet kombinací** a když překročí strop (výchozí 500), odmítne běžet bez explicitního přepsání. Důvod není výpočetní čas, ale to, že tisíce kombinací dělají z výsledku šum a člověk to pozná lépe z čísla dopředu než z výsledků potom.

Výstupem sweepu není nalezené optimum, ale **celá plocha**. Report proto ukazuje výsledky napříč mřížkou, ne jen nejlepší řádek — a plochost okolí optima je jedno z akceptačních kritérií.

## Deflated Sharpe

Pozorovaný Sharpe se koriguje na počet pokusů a na rozptyl Sharpů mezi pokusy. Implementace se drží postupu Baileyho a Lópeze de Prada (2014): vstupem je pozorovaný Sharpe, počet provedených pokusů, rozptyl Sharpů přes pokusy a šikmost a špičatost denních výnosů; výstupem je pravděpodobnost, že pravý Sharpe je kladný.

Vzorec **není** součástí této specifikace, protože jej nemá cenu přepisovat z hlavy — implementuje se z původní práce a golden-testuje proti publikovaným příkladům. Do doby, než golden test existuje, se hodnota nesmí použít v akceptačním kritériu.

Vedle toho se reportuje jednodušší a snáz interpretovatelné číslo: **počet pokusů a rozdíl mezi nejlepším a mediánovým výsledkem mřížky**. Když je nejlepší výsledek daleko od mediánu, je to samo o sobě podezřelé, bez ohledu na jakoukoliv statistiku.

## Robustnostní kontroly

Každá je samostatný běh nebo přepočet a její výsledek je součástí reportu:

| Kontrola | Co dělá | Co znamená selhání |
|---|---|---|
| Zdvojení nákladů | přepočet s dvojnásobným `base_bps` a `impact_coef` | strategie žije z předpokladu nízkých nákladů |
| Okolí optima | výsledky sousedních bodů mřížky | ostrý pík = přefitováno; hledá se plošina, ne vrchol |
| Rozpad po letech | metriky za každý kalendářní rok zvlášť | výsledek dělá jeden nebo dva roky, ne strategie |
| Rozpad univerza | oddělené výsledky pro likvidnější a méně likvidní polovinu | funguje jen tam, kde by reálná velikost pozice nešla zobchodovat |
| Citlivost na počáteční datum | posun začátku o 1–6 měsíců | výsledek závisí na tom, kdy se začalo, tedy na náhodě |
| Podíl zastropovaných obchodů | z metrik podprojektu 4 | strategie neškáluje na reálný kapitál |

## Akceptační kritéria

Soubor `acceptance/{strategy_name}.yaml`, který **musí být commitnutý dřív**, než se spustí holdout běh. Systém ověří, že commit s kritérii je starší než běh; když ne, selže.

```yaml
strategy: pullback_v1
criteria:
  min_trades: 100
  max_drawdown: 0.25
  min_oos_sharpe: 0.8
  min_walk_forward_windows_positive: 0.6    # podíl testovacích oken s kladným výnosem
  max_cost_doubling_degradation: 0.4        # relativní zhoršení Sharpe při 2× nákladech
  max_size_capped_share: 0.2
  min_exposure: 0.2
```

Výstupem je tabulka pass/fail per kritérium v `acceptance_results`. **Systém nerozhoduje, jen měří** — rozhodnutí zůstává na člověku, ale musí být vidět, které kritérium se nesplnilo.

`min_walk_forward_windows_positive` je tam záměrně: strategie s vysokým celkovým Sharpe, která byla zisková ve dvou z osmi oken, není robustní strategie, ale šťastná náhoda ve dvou letech.

## Report

Na běh se generuje statický HTML report: equity curve, drawdown, rozpad po letech, tabulka mřížky, výsledky robustnostních kontrol, tabulka akceptačních kritérií a hlavička s celými metadaty běhu. Ukládá se do `storage/reports/{run_id}.html`.

Reporty jsou artefakty, ne dokumentace — verzují se přes `run_id`, ne přes git.

## Testování

| Co | Jak |
|---|---|
| Hranice rozdělení | walk-forward okna se nepřekrývají a žádné nezasahuje do holdoutu |
| Holdout rozpočet | čtvrté použití selže; přejmenování verze rozpočet neobnoví |
| Kritéria dřív než běh | holdout běh s kritérii commitnutými **po** běhu selže |
| Počítadlo pokusů | *n* běhů v mřížce dá `trials = n` a započítají se i selhané |
| Deflated Sharpe | golden test proti publikovanému příkladu; bez něj se metrika nesmí použít v kritériu |
| Vyhodnocení kritérií | každé kritérium samostatně, oba směry (splněno / nesplněno) |
| Reprodukovatelnost | dva běhy se stejnými metadaty dají identické metriky; změna `data_snapshot_version` je v záznamu vidět |
| Strop mřížky | překročení stropu odmítne běh bez explicitního přepsání |

## Rizika

**Největší riziko je člověk a specifikace ho nemůže odstranit.** Nic nezabrání tomu, aby se člověk podíval na walk-forward výsledky, změnil návrh a spustil znovu — a tím z walk-forward zóny udělal ladicí data. Záznam pokusů to zviditelní, holdout rozpočet omezí dopad, ale skutečnou obranou je jen disciplína. Je správné to mít v dokumentu napsané nahlas, protože nepojmenované riziko se neřídí.

**Vyčerpání holdoutu je nevratné.** Tři použití a je konec — a další nezaujatá data vzniknou jen tím, že uplyne čas. To je nepříjemné a je to tak správně: kdyby to nepříjemné nebylo, nefungovalo by to.

**Přežití strategií, které selhaly.** Když se vyzkouší dvacet strategií a jedna projde, není to totéž jako když projde první vyzkoušená. Záznam v `research_runs` to zachytí jen pokud se opravdu zapisují všechny běhy včetně zahozených — proto je zápis součástí enginu, ne volitelný krok.

**Deflated Sharpe může dát falešnou jistotu.** Je to statistika s předpoklady (mimo jiné nezávislost pokusů, kterou mřížka nesplňuje — sousední body mřížky jsou silně korelované). Proto se reportuje vedle jednodušších a robustnějších signálů, ne místo nich.

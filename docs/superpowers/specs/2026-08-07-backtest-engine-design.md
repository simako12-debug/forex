# Backtest engine — design podprojektu 4

Datum: 2026-08-07
Stav: navržen, čeká na review
Závisí na: [podprojekt 1 — Market Data](2026-08-06-market-data-design.md), [podprojekt 2 — Indikátory](2026-08-06-indicators-design.md), [podprojekt 3 — Definice strategie](2026-08-06-strategy-definition-design.md)

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Model simulace | **hybrid** — signály vektorizovaně, pozice v simulační smyčce po dnech | stop a částečné odprodeje závisí na vstupní ceně konkrétní pozice, což vektorizovat nelze; drahá část (score nad 1500 tickery × 6300 dní) zůstává vektorizovaná |
| Komise | **nula** (Alpaca akcie) | odpovídá realitě u zvoleného brokera |
| Poplatky | **SEC a FINRA TAF při prodeji**, sazby s datovou platností | jsou reálné, mění se v čase a u desítek obchodů ročně nejsou zanedbatelné |
| Slippage | v bazických bodech, **škáluje s podílem objednávky na průměrném denním objemu** | brání strategiím, které vypadají skvěle jen proto, že předpokládají nekonečnou likviditu |
| Fill při vstupu | **open následujícího dne** | odpovídá tomu, co systém reálně udělá (market-on-open po závěrečném výpočtu signálu); žádný look-ahead |
| Delisting v pozici | výstup na posledním závěru, u bankrotu **faktor návratnosti s výchozí nulou** | bez toho by backtest tvrdil, že z krachu vyjdeš s poslední kotací — a to je přesně tam, kde strategii bolí nejvíc |
| Sizing | **fixní frakce rizika** | přirozený partner k ATR stopům a odprodejům měřeným v R; všechny obchody mají stejné riziko nezávisle na volatilitě tickeru |
| Ceny v simulaci | **upravené** | split během držení nezpůsobí nespojitost a matematika pozice prostě funguje |
| Kapitál | **složené úročení** — sizing počítá z aktuální equity | odpovídá reálnému provozu |
| Páka | **žádná** ve v1 | součet hodnot pozic ≤ equity |

## Rozsah

### Vevnitř

- Simulační smyčka: otevírání a zavírání pozic, částečné odprodeje, stopy, časové limity
- Risk vrstva: velikost pozice, limit počtu pozic, limit podílu na denním objemu, kontrola hotovosti
- Model nákladů: poplatky při prodeji a slippage podle likvidity
- Zpracování corporate actions a delistingu během držení
- Výstupy: obchody s nohami, equity curve, metriky, denní stav pozic
- Metadata běhu pro reprodukovatelnost

### Mimo

Sweepy, walk-forward a rekalibrace vah (podprojekt 5). Skutečné objednávky (podprojekty 6–7). Definice strategie (podprojekt 3).

### Kritérium hotovosti

Pro zadanou strategii a období dostanu equity curve, seznam obchodů s nohami a sadu metrik — a u každého obchodu je dohledatelné, za jakou cenu se vstoupilo, proč se vystoupilo, kolik stály náklady a jakou metodou se rozhodla případná nejednoznačnost v rámci dne. Ruční přepočet jednoho obchodu z barů dá stejné číslo jako engine.

## Denní cyklus simulace

Pořadí operací v rámci jednoho obchodního dne *D* je **jádro korektnosti** celého enginu. Jakákoliv jiná posloupnost zavádí look-ahead.

```
Pro každý obchodní den D, chronologicky:

1. OTEVŘENÍ  Realizuj vstupní signály z dne D-1 na open(D).
             Ověř, že instrument v D skutečně obchoduje a je eligible.
             Ověř limity risk vrstvy (počet pozic, hotovost, podíl na objemu).

2. VÝSTUPY   Vyhodnoť otevřené pozice na baru D:
             a) mezera přes stop — open(D) ≤ stop → fill na open(D), NE na stop ceně
             b) stop zasažen intrabar — low(D) ≤ stop → fill na stop ceně
             c) spouštěč odprodeje — high(D) ≥ cíl → částečný fill na cílové ceně
             d) časový limit — držení ≥ max_holding_days → fill na close(D)
             Při kolizi (b) a (c) ve stejném baru platí pravidlo o nejednoznačnosti níže.

3. MARK      Zaznamenej equity k close(D): hotovost + Σ (kusy × close(D)).

4. SIGNÁLY   Spočítej score a vstupní signály k datu D.
             Realizují se v kroku 1 dne D+1.
```

**Krok 2a je zásadní a snadno se opomene.** Když akcie otevře pod stopem (gap down na špatné zprávě), stop se nevyplní na stop ceně — vyplní se na otevírací ceně, která je horší. Engine, který v takové situaci účtuje stop cenu, systematicky podhodnocuje ztráty, a to nejvíc právě u těch obchodů, které strategii nejvíc ublížily.

**Re-entry:** protože platí jedna pozice na instrument, vstup do instrumentu, ze kterého se v tentýž den vystoupilo, není možný. Nejdřívější nový vstup je den následující.

### Nejednoznačnost v rámci jednoho dne

Když ve stejném baru mohl padnout stop i spouštěč odprodeje, denní bar neříká, co bylo první:

1. **Jsou-li pro instrument a datum dostupná intradenní data**, pořadí se určí z nich.
2. **Nejsou-li**, platí pesimistický předpoklad: **stop vyhrál**.
3. U každého obchodu se zapíše, kterou metodou se rozhodlo (`intraday` / `pessimistic`).

Ten třetí bod umožňuje změřit, kolik pesimismus stojí, a je jediné konkrétní odůvodnění, proč se intradenní data pro 500 nejlikvidnějších tickerů kupují.

## Upravené ceny a dividendy — past na dvojí započtení

Simulace běží na **upravených** cenách. Ty už v sobě mají zahrnuté splity i dividendy, takže:

- **Dividendy se nepřipisují jako hotovost.** Byly by započtené dvakrát a výnos by vyšel vyšší, než jaký byl.
- **Split během držení nevyžaduje žádnou obsluhu.** Na upravené řadě není nespojitost, takže počet kusů ani stop se nepřepočítávají. To je hlavní důvod, proč se simuluje na upravených cenách.
- Výsledek je tím pádem **total return**, ne price return, a takto se musí interpretovat.

**Důsledek pro srovnání s živým provozem, který musí být v podprojektu 6 vyřešen:** živá exekuce pracuje se surovými cenami a dividendy dostává jako skutečnou hotovost. Aby byly obě strany srovnatelné, musí se paper i live výsledky převádět na total return, ne na porovnání absolutních cen. Stopové úrovně se navíc v backtestu počítají v upravených cenách a před odesláním objednávky se musí převést na surové.

## Model nákladů

### Poplatky při prodeji

Při prodeji akcií se v USA platí regulatorní poplatky, které broker přeúčtuje:

- **SEC Section 31 fee** — sazba za dolar hodnoty prodeje
- **FINRA TAF** — sazba za kus, s horním limitem na objednávku

Sazby **se mění a specifikace je nedefinuje číslem.** Ukládají se v tabulce s datovou platností (`fee_schedule`: `effective_from`, `sec_rate`, `taf_per_share`, `taf_cap`) a backtest použije sazbu platnou k datu obchodu. Aktuální hodnoty je potřeba doplnit z platných sazebníků SEC a FINRA — jsou to externí čísla, ne návrhové rozhodnutí. Do doby, než budou doplněné, engine **selže** místo aby použil nulu.

### Slippage

```
slippage_bps = base_bps + impact_coef × participation_rate

participation_rate = hodnota_objednávky / průměrný_denní_dollar_volume(20 dní)
```

Vstupní fill je tedy `open(D) × (1 + slippage_bps/10000)`, výstupní o stejnou hodnotu horší. `base_bps` a `impact_coef` jsou konfigurovatelné parametry běhu a ukládají se do metadat, aby šlo dvě běhy srovnat jen když měly stejné předpoklady.

### Strop na podíl na objemu

Risk vrstva navíc **zastropuje** velikost pozice na konfigurovatelný maximální podíl denního objemu (výchozí 1 %). Když by výpočet sizingu dal víc, pozice se zmenší a obchod se označí jako `size_capped`.

Bez tohoto stropu by strategie na malých tickerech vykazovala výsledky, které nejde v realitě dosáhnout, protože bys byl polovina denního obratu. Označení `size_capped` je důležité: když je zastropovaná většina obchodů, strategie neškáluje a je to vidět, místo aby se to schovalo do průměru.

## Risk vrstva

Velikost pozice fixní frakcí rizika:

```
riskovaná_částka = equity × risk_pct
vzdálenost_ke_stopu = entry_price − stop_price
kusy = floor(riskovaná_částka / vzdálenost_ke_stopu)
```

Pak se aplikují omezení, v tomto pořadí:

1. **Strop na podíl objemu** — `kusy ≤ max_participation × ADV_kusy`
2. **Strop na hodnotu pozice** — `kusy × entry_price ≤ equity × max_position_pct`
3. **Dostupná hotovost** — `kusy × entry_price ≤ hotovost`
4. **Limit počtu pozic** — když je otevřeno `max_positions`, další vstup se zahodí

Krok 4 potřebuje pravidlo pro případ, že signálů je víc než volných slotů: **berou se nejlepší podle score**, což je konzistentní s cross-sectional vstupním pravidlem z podprojektu 3. Zahozené signály se zapisují, aby šlo zjistit, jak často limit strategii brzdí.

Parametry risk vrstvy (`risk_pct`, `max_positions`, `max_position_pct`, `max_participation`) jsou součástí konfigurace běhu a ukládají se do metadat. **Nejsou** součástí strategie — proto jsou dvě strategie srovnatelné za stejných rizikových podmínek.

## Výstupy

**Obchody** — jeden řádek per obchod (celý životní cyklus pozice) plus podřízené nohy:

```
trade:  instrument_id, entry_date, entry_price, avg_entry_price, initial_stop,
        exit_date, shares_initial, realized_pnl, r_multiple,
        exit_reason (stop | scale_out_final | time | delisting),
        ambiguity_resolution (intraday | pessimistic | none),
        size_capped (bool), fees_total, slippage_total

leg:    trade_id, date, kind (entry | scale_out | stop | time | delisting),
        shares, price, fees, slippage
```

**Equity curve** — den, hotovost, hodnota pozic, celková equity, počet otevřených pozic.

**Metadata běhu** — bez nich není běh reprodukovatelný a nemá cenu ho ukládat: verze a hash strategie, `weights_as_of`, verze definice univerza, verze datového snapshotu, seznam `feature_id`, parametry nákladů, parametry risk vrstvy, období.

## Metriky

Definice jsou explicitní, protože „Sharpe" bez uvedení anualizace a bezrizikové sazby není číslo.

| Metrika | Definice |
|---|---|
| Total return | `equity_konec / equity_začátek − 1` |
| CAGR | `(equity_konec / equity_začátek)^(252/počet_obchodních_dní) − 1` |
| Sharpe | `mean(denní_výnos) / std(denní_výnos) × sqrt(252)`, bezriziková sazba **nula** a je to uvedené |
| Max drawdown | maximální relativní propad equity od dosavadního maxima |
| Délka max drawdownu | počet obchodních dní od maxima do návratu na maximum; pokud se nevrátil, do konce období |
| Exposure | podíl obchodních dní s alespoň jednou otevřenou pozicí |
| Průměrná expozice | průměrný podíl `hodnota_pozic / equity` |
| Počet obchodů | počet obchodů, ne nohou |
| Win rate | podíl obchodů s `realized_pnl > 0` |
| Profit factor | `Σ zisků / |Σ ztrát|` |
| Průměrné R | průměr `r_multiple` přes obchody |
| Expectancy | `win_rate × avg_win_R − (1 − win_rate) × avg_loss_R` |
| Turnover | roční objem obchodů dělený průměrnou equity |
| Podíl zastropovaných | podíl obchodů s `size_capped` — indikátor toho, že strategie neškáluje |

## Testování

`pytest`, žádná síť, žádná závislost na stáhnutém dumpu. Klíč je tady v **ručně přepočitatelných scénářích** — engine musí dát na malém fixture stejné číslo jako výpočet na papíře.

| Co | Jak |
|---|---|
| Jeden obchod od začátku do konce | 10 barů, známé ceny, ATR stop; ruční výpočet fillů, poplatků, slippage a `r_multiple` se musí rovnat výstupu enginu |
| Mezera přes stop | `open(D) < stop` → fill na `open(D)`, **ne** na stop ceně; ztráta je větší než 1R |
| Stop intrabar | `low(D) ≤ stop < open(D)` → fill na stop ceně, ztráta přesně 1R minus náklady |
| Částečný odprodej | odprodej poloviny na 2R, posun stopu na break-even, zbytek na časový limit; obchod má tři nohy a jeden `realized_pnl` |
| Časový limit | pozice bez zasažení stopu i cíle se uzavře na close v den `max_holding_days` |
| Kolize stop + cíl | bez intradenních dat vyhraje stop a zapíše se `pessimistic`; s intradenními daty se rozhodne podle nich a zapíše se `intraday` |
| Delisting | pozice v instrumentu delistovaném kvůli bankrotu se uzavře s faktorem návratnosti nula; `exit_reason = delisting` |
| Split během držení | na upravené řadě nezpůsobí žádnou změnu kusů ani stopu a `realized_pnl` odpovídá ručnímu výpočtu |
| Dividenda během držení | **nepřipíše se hotovost**; výnos odpovídá upravené řadě — přímý test proti dvojímu započtení |
| Sizing | `risk_pct = 1 %`, equity 100 000, vzdálenost ke stopu 2 USD → 500 kusů; pak každý ze čtyř stropů samostatně |
| Strop objemu | objednávka nad `max_participation` se zmenší a označí `size_capped` |
| Limit pozic | více signálů než slotů → vyberou se nejlepší podle score, zahozené se zapíší |
| Chybějící sazebník | období bez platného `fee_schedule` → engine **selže**, ne aby použil nulu |
| Look-ahead | posunutí celé datové řady o *k* dní posune výsledky o *k* dní a nic jiného nezmění |
| Determinismus | dvě spuštění téhož běhu dají bitově identické výstupy |

## Rizika

**Chyba v denním cyklu je nezjistitelná pohledem na výsledky.** Backtest s look-aheadem nevypadá rozbitě — vypadá skvěle. Proto je test na posun datové řady povinný a proto je pořadí kroků v denním cyklu součástí specifikace, ne implementačním detailem.

**Optimistický fill je druhá nejtišší chyba.** Mezera přes stop, nezastropovaný podíl na objemu a chybějící slippage všechny působí stejným směrem — zlepšují výsledek. Každý z nich má proto vlastní test.

**Sazby poplatků jsou externí data, která zestárnou.** Tabulka s datovou platností to řeší pro historii, ale nikdo nezajistí, že se doplní nová sazba, když se změní. Mitigace: `data:health` z podprojektu 1 se rozšíří o kontrolu, že sazebník pokrývá i aktuální datum.

**Dvojí započtení dividend je specifická past tohoto návrhu.** Vzniká přirozeně, pokud někdo později „opraví" engine tak, že dividendy připisuje — protože to na první pohled vypadá jako chybějící funkce. Test na to existuje a komentář u něj musí vysvětlovat proč.

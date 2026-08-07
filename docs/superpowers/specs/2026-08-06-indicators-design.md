# Indikátorová vrstva — design podprojektu 2

Datum: 2026-08-06
Stav: navržen, čeká na review
Závisí na: [podprojekt 1 — Market Data](2026-08-06-market-data-design.md)

## Změna rolí proti podprojektu 1

Při specifikaci podprojektu 3 padlo rozhodnutí, že **logika strategie žije jen v Pythonu** a PHP konzumuje hotové cílové pozice. Tím se přeskládaly role celého systému:

- **Python** vlastní indikátory, strategie, backtest a rozhodnutí — co koupit a co prodat.
- **PHP** vlastní data (ingest, kvalita, sklad), orchestraci, exekuci, reconciliaci, audit a monitoring.

Důsledky, které z toho plynou pro tuto vrstvu:

1. Indikátorová vrstva je **celá v Pythonu**. V PHP nevznikne ani jeden indikátor.
2. Parquet export z podprojektu 1 není pohodlnost pro budoucí sweepy, ale **hlavní rozhraní mezi PHP daty a Python výpočty**. Musí být hotový a otestovaný dřív, než tato vrstva začne existovat.
3. Python přestává být research nástroj a stává se produkčně kritickým — vlastní testová sada, vlastní nasazení, vlastní monitoring.

**Oprava specifikace podprojektu 1:** tvrzení, že `research` prostředí se nasazuje až od podprojektu 4, už neplatí. Python je potřeba od podprojektu 2.

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Materializace | **počítat za běhu** z Parquet barů do paměti | nad denními bary jsou to v numpy sekundy; žádná invalidace a indikátor nemůže být zastaralý vůči datům. Cache se přidá teprve po měření, ne dopředu |
| Implementace | **vlastní tenké implementace** + golden testy | TA knihovny se liší v konvencích (Wilderovo vs. jednoduché vyhlazování u RSI a ATR) a ta odchylka je tichá; změna konvence mezi verzemi knihovny přepisuje historické backtesty pod rukama |
| Warm-up | strategie **deklaruje** potřebný warm-up, backtest ho vynutí a při nedostatku dat **selže** | stejný princip jako u chybějícího timeframu — hlasité odmítnutí místo tichého přeskočení |
| Cross-sectional | **ano od v1** | rank-based pravidla (momentum žebříčky, relativní síla v sektoru) jsou u swing screeningu na 1500 tickerech běžná; dodělat je později znamená přepsat výpočetní vrstvu |
| Vstupní ceny | indikátory nad **upravenými** cenami, exekuce nad **surovými** | nezohledněný split by rozbil každý klouzavý průměr; objednávka se ale posílá v reálné ceně |
| Benchmark | `SPY`, konfigurovatelný | pro relativní sílu je potřeba jeden referenční instrument; SPY je v univerzu a má plnou historii |

## Rozsah

### Vevnitř

- Načtení barů z Parquetu do panelu (data × instrumenty)
- Výpočet per-instrument indikátorů: SMA, EMA, ATR, RSI, rolling high/low, průměrný dollar volume
- Výpočet cross-sectional featur: percentilový rank napříč členy univerza k datu
- Relativní síla proti benchmarku
- Explicitní semantika chybějících hodnot
- Deterministické ID featury pro reprodukovatelnost

### Mimo

Jakékoliv rozhodnutí o obchodu, definice strategie, generování signálu, backtest. Tato vrstva pouze produkuje čísla; interpretaci dělá podprojekt 3.

### Kritérium hotovosti

Pro zadané období a definici univerza dokážu dostat matici `sma(window=200, input=adj_close)`, ve které je u každého instrumentu odlišitelné, jestli je hodnota chybějící proto, že instrument tehdy nebyl listovaný, proto že běží warm-up, nebo proto že v datech je mezera — a golden testy na každý indikátor procházejí.

## Výpočetní model

Základní datová struktura je **široká matice**: index jsou obchodní dny, sloupce instrumenty, hodnoty čísla. Cross-sectional operace je pak jeden řádkový výpočet a per-instrument operace jeden sloupcový — obojí vektorizovaně.

```
             AAPL    MSFT    XYZ
2019-03-13   181.2   112.4   NaN
2019-03-14   182.0   113.1   NaN
2019-03-15   181.7   112.9   14.2
```

**Velikost:** univerzum ~1500 instrumentů × ~6300 obchodních dní = 9,45M hodnot na featuru, tedy ~75 MB ve `float64`. To se do paměti vejde snadno. Počítat panel nad **celým** katalogem včetně delistovaných (16k instrumentů) by dalo ~800 MB na featuru, což se nevejde — proto se panel vždy staví jen nad **členy univerza v daném období plus instrumenty potřebné pro warm-up**, nikdy nad celým katalogem.

### Tři druhy chybějící hodnoty

V takové matici je většina buněk prázdná a **důvody jsou tři, které se nesmí slít**:

| Druh | Význam | Jak se pozná |
|---|---|---|
| **Není listovaný** | instrument k tomu dni neexistoval nebo byl už delistovaný | `listed_mask[date, instrument] == False` |
| **Warm-up** | instrument existoval, ale indikátor ještě nemá dost historie | `listed_mask == True` a méně než *n* platných předchozích hodnot |
| **Mezera v datech** | instrument existoval a měl mít bar, ale nemá ho | `listed_mask == True`, bar chybí, a `MissingBarOnTradingDay` finding z podprojektu 1 to potvrzuje |

Kdyby se tyhle tři případy slily do jednoho `NaN`, stalo by se tohle: strategie by hlásila menší počet příležitostí, než reálně byl, a nikdo by nepoznal, jestli za tím je nedostatek dat nebo skutečná absence signálu. Statistika typu „strategie obchodovala 40× za rok" by byla nedůvěryhodná.

Panel proto vedle hodnot vždy nese **`listed_mask`** — booleovskou matici stejného tvaru, odvozenou z `instruments.listed_at` a `delisted_at`.

## Veřejné API

```python
@dataclass(frozen=True)
class FeatureRequest:
    name: str                    # "sma", "atr", "rsi", "rolling_high", "cs_rank", ...
    params: Mapping[str, object]  # {"window": 20}
    input: str = "adj_close"     # adj_close | adj_open | adj_high | adj_low | adj_volume | dollar_volume

    @property
    def feature_id(self) -> str:
        """Deterministický identifikátor, např. 'sma(input=adj_close,window=20)'.
        Parametry jsou řazené podle názvu, aby stejná featura měla vždy stejné ID."""


@dataclass(frozen=True)
class BarPanel:
    """Široké matice OHLCV pro jedno období a jednu množinu instrumentů."""
    adj_open: pd.DataFrame
    adj_high: pd.DataFrame
    adj_low: pd.DataFrame
    adj_close: pd.DataFrame
    adj_volume: pd.DataFrame
    listed_mask: pd.DataFrame


class FeatureSet:
    def get(self, feature_id: str) -> pd.DataFrame: ...
    def feature_ids(self) -> Sequence[str]: ...


def load_panel(
    start: date,
    end: date,
    instrument_ids: Sequence[str],
    parquet_root: Path,
) -> BarPanel: ...


def compute(panel: BarPanel, requests: Sequence[FeatureRequest]) -> FeatureSet: ...
```

`feature_id` není kosmetika — je to klíč, který se ukládá do záznamu o backtest běhu, aby šlo po měsících zjistit, co přesně se počítalo. Řazení parametrů podle názvu zajišťuje, že `sma(window=20)` a `sma(input=adj_close,window=20)` nedají dvě různá ID pro tentýž výpočet.

## Sada indikátorů v1 a jejich přesné definice

Definice jsou tady záměrně explicitní, protože „RSI" bez uvedení konvence není zadání.

**`sma(window=n)`** — aritmetický průměr posledních *n* hodnot včetně aktuální. První `n-1` hodnot je warm-up.

**`ema(window=n)`** — `alpha = 2/(n+1)`; první hodnota je SMA prvních *n* hodnot, dále `EMA_t = alpha*x_t + (1-alpha)*EMA_{t-1}`.

**`atr(window=n)`** — Wilderovo vyhlazování:
```
TR_t   = max(H_t - L_t, |H_t - C_{t-1}|, |L_t - C_{t-1}|)
ATR_n  = mean(TR_1 .. TR_n)
ATR_t  = (ATR_{t-1} * (n-1) + TR_t) / n     pro t > n
```

**`rsi(window=n)`** — Wilderovo vyhlazování, ne jednoduchý průměr:
```
gain_t = max(C_t - C_{t-1}, 0);  loss_t = max(C_{t-1} - C_t, 0)
avg_gain_n = mean(gain_1..gain_n);  avg_loss_n = mean(loss_1..loss_n)
avg_gain_t = (avg_gain_{t-1} * (n-1) + gain_t) / n
avg_loss_t = (avg_loss_{t-1} * (n-1) + loss_t) / n
RSI_t = 100 - 100 / (1 + avg_gain_t / avg_loss_t)
```
Když `avg_loss_t == 0`, RSI je 100 (ne dělení nulou).

**`rolling_high(window=n)` / `rolling_low(window=n)`** — maximum resp. minimum posledních *n* hodnot včetně aktuální.

**`dollar_volume_ma(window=n)`** — klouzavý průměr `close * volume` nad **surovými** hodnotami, ne upravenými. Důvod: likvidita je vlastnost skutečně zobchodovaného objemu v tehdejších cenách; adjustovaný objem by ji zkreslil.

**`relative_strength(window=n, benchmark="SPY")`** — `(C_t / C_{t-n}) / (B_t / B_{t-n})`, kde *B* je cena benchmarku. Hodnota > 1 znamená, že instrument překonal benchmark.

**`cs_rank(source=feature_id)`** — cross-sectional percentilový rank. Pro každý den se vezmou hodnoty zdrojové featury napříč **členy univerza k tomu dni**, seřadí se a přepočtou na percentil v intervalu ⟨0, 1⟩. Detaily, které musí být v implementaci explicitní:
- `NaN` hodnoty se z rankování **vylučují** a zůstávají `NaN` — nedostávají rank 0.
- Shodné hodnoty dostávají **průměrný rank** (`method="average"`).
- Percentil, ne absolutní pozice — jinak by výsledek závisel na velikosti univerza k danému dni, která se v čase mění.

## Look-ahead bezpečnost

Všechny indikátory jsou **kauzální**: hodnota k datu *D* používá jen data s datem ≤ *D*. Explicitně to znamená:

- Žádné centrované okno, žádný `shift(-1)`, žádná interpolace přes budoucí hodnoty.
- Cross-sectional rank k datu *D* se počítá nad univerzem k datu *D*, nikoliv nad dnešním univerzem.
- Instrument, který k datu *D* ještě nebyl v univerzu, do rankování k tomu dni nevstupuje.

Testuje se stejným trikem jako point-in-time univerzum v podprojektu 1: **featura spočítaná nad zkrácenými daty (≤ D) se musí rovnat featuře spočítané nad plnými daty a odečtené k datu D.** Když se to nerovná, implementace se dívá dopředu.

## Testování

Konvence pro Python stranu: `pytest`, jeden testovací modul per indikátor, žádné testy proti síti, žádná závislost na stáhnutém dumpu.

| Co | Jak |
|---|---|
| `sma` | golden: vstup `[1, 2, 3, 4]`, `window=3` → `[NaN, NaN, 2.0, 3.0]` |
| `ema` | golden: ověřit, že první hodnota je SMA prvních *n* a druhá odpovídá rekurenci s `alpha=2/(n+1)`, spočítané v testu podle vzorce |
| `atr`, `rsi` | golden: test má ve svém těle rozepsanou Wilderovu rekurenci a porovnává s implementací; vzorec je součástí testu, ne odkaz na knihovnu |
| `rsi` hraniční | monotónně rostoucí řada → RSI = 100; `avg_loss == 0` nesmí dělit nulou |
| `cs_rank` | shodné hodnoty dostanou průměrný rank; `NaN` zůstane `NaN`; rank je percentil nezávislý na velikosti univerza |
| `cs_rank` univerzum | instrument mimo univerzum k datu se do rankování k tomu dni nezapočítá |
| Tři druhy `NaN` | fixture s nelistovaným instrumentem, warm-upem a mezerou v datech; každý případ musí být odlišitelný |
| Kauzalita | featura nad zkrácenými daty (≤ D) = featura nad plnými daty odečtená k D, pro každý indikátor |
| Warm-up kontrakt | požadavek s warm-upem delším než dostupná historie **selže s chybou**, ne tichým `NaN` |
| `load_panel` | proti malému Parquet fixture souboru vygenerovanému v testu, ne proti reálnému exportu |

Kanonický fixture je zde jiný než v podprojektu 1: malý panel 4 instrumentů × 250 dní, kde jeden instrument je delistovaný v polovině, jeden vstupuje pozdě a jeden má třídenní mezeru.

## Rizika

**Divergence adjustmentu.** Panel čte upravené ceny z Parquetu, které spočítalo PHP. Když se změní logika adjustmentu v PHP a Parquet se nepřegeneruje, indikátory jedou nad starými cenami a nikdo si toho nevšimne. Mitigace: Parquet nese verzi adjustmentu a `load_panel` ji ověří proti očekávané hodnotě; nesoulad je chyba, ne varování.

**Paměť u velkých sweepů.** Panel je v paměti a sweep může chtít desítky featur současně. 75 MB na featuru × 30 featur = 2,2 GB. Není to problém dnes, ale je to strop, na který se dá narazit — proto se počítá jen nad univerzem, ne nad katalogem, a `FeatureSet` drží featury líně, ne všechny naráz.

**Tichá změna konvence.** Vlastní implementace tenhle problém řeší jen napůl — pokud někdo v budoucnu „opraví" RSI na jednoduché vyhlazování, všechny historické backtesty se stanou nesrovnatelnými. Mitigace: golden testy s rozepsaným vzorcem jsou zároveň dokumentací konvence a taková změna je shodí.

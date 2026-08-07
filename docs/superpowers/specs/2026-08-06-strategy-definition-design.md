# Definice strategie — design podprojektu 3

Datum: 2026-08-06
Stav: navržen, čeká na review
Závisí na: [podprojekt 1 — Market Data](2026-08-06-market-data-design.md), [podprojekt 2 — Indikátory](2026-08-06-indicators-design.md)

## Rozhodnutí

| Oblast | Rozhodnutí | Důvod |
|---|---|---|
| Jazyk | strategie žije **jen v Pythonu**, PHP konzumuje hotové cílové pozice | jedna implementace, takže divergence backtestu a živého provozu nemá kde vzniknout — to je nejdražší selhání, jaké tenhle systém může mít |
| Rozhodovací model | **scoring** — komponenty s vlastní vahou a vlastním bodovým rozsahem | zadavatelův návrh; transparentní, rozšiřitelné, každá komponenta je samostatně testovatelná |
| Vstupní práh | **cross-sectional**, ne absolutní | absolutní rozdělení score se posouvá s režimem trhu; absolutní práh by v bull marketu otevřel stovky pozic a v korekci žádnou, aniž by to kdo zamýšlel |
| Sizing a risk | **samostatná vrstva**, ne uvnitř strategie | strategie jsou srovnatelné za stejných podmínek a risk logika se testuje jednou |
| Směr | **long-only** ve v1 | žádná dostupnost výpůjčky, žádné výpůjční náklady, žádné neomezené riziko — data o shortovatelnosti k datu nemáme |
| Portfolio | **jedna strategie = jedno portfolio** | žádné konflikty signálů ani alokace mezi strategiemi před první funkční strategií |
| Výstup z pozice | tvrdý stop + částečné odprodeje + časový limit; **bez trailingu** | trailing zamítnut zadavatelem; `ExitPolicy` má místo pro pozdější doplnění bez přepisu |

## Rozsah

### Vevnitř

- Kontrakt, jak se strategie zapisuje: eligibility, score, vstupní pravidlo, výstupní politika, metadata
- Scoring model s komponentami, vahami a bodovými rozsahy
- Cross-sectional vstupní pravidlo
- Výstupní politika včetně částečných odprodejů
- Verzování strategie a point-in-time váhy
- Deklarace potřeb (featury, warm-up, timeframy, univerzum) a jejich validace před spuštěním

### Mimo

Simulace (podprojekt 4), sweepy a rekalibrace (podprojekt 5), sizing a rizikové limity (podprojekt 7 — risk vrstva), zápis cílových pozic do Postgresu (podprojekt 6).

### Kritérium hotovosti

Dokážu zapsat strategii jako jeden objekt, spustit nad ním validaci, která odmítne nekonzistentní deklaraci (chybějící featura, warm-up delší než data, neexistující verze univerza), a dostat pro zadané období matici vstupních signálů a k ní výstupní politiku — bez toho, že bych spustil backtest.

## Anatomie strategie

Strategie má pět částí a každá odpovídá na jednu otázku.

### 1. Eligibility — kdo vůbec smí být kandidátem

Tvrdé podmínky, nikdy ne score. Instrument je eligible k datu *D*, pokud:

- je členem univerza k datu *D* (podle verzované definice z podprojektu 1),
- má dost historie na deklarovaný warm-up,
- nemá k datu *D* otevřený `error` finding z validace dat.

**Do score tohle nikdy nepatří**, protože „skoro likvidní" nebo „skoro dost dat" neexistuje. Eligibility je binární a vyhodnocuje se před scoringem.

### 2. Score — jak dobrý je kandidát

Každá komponenta má **vlastní bodový rozsah** a **vlastní váhu na celkovém score**:

```
score(instrument, D) = Σ  weight_i × points_i(instrument, D)

kde  points_i ∈ ⟨0, max_points_i⟩
```

Komponenta tedy dělá dvě věci: převede featury na body ve svém rozsahu (například trendová komponenta dá 0 bodů pod 200denním průměrem a 10 bodů, když je cena nad ním a průměr roste), a její váha určuje, jak moc ten příspěvek váží proti ostatním.

**Absolutní velikost score je nepodstatná.** Protože vstupní pravidlo je rank-based, na výsledek má vliv jen *relativní* poměr vah, nikoliv jejich absolutní hodnoty ani celkový maximální součet. To odstraňuje celou třídu chyb kolem normalizace — score se nikdy nemusí přepočítávat na procenta a přidání komponenty nezmění interpretaci prahu.

### 3. Vstupní pravidlo — kdy se vstupuje

Dvě podporované formy, obě cross-sectional:

- `top_n(n)` — vstup do *n* nejlépe skórujících eligible instrumentů k datu *D*
- `min_rank_percentile(p)` — vstup do všech eligible instrumentů, jejichž percentilový rank score je ≥ *p*

Absolutní práh (`score > X`) **není podporovaný** a je to záměrné omezení, ne nedodělek. Důvod je v tabulce rozhodnutí výše.

Shodné score na hranici: řadí se sekundárně podle `instrument_id`, aby byl výsledek deterministický. Bez toho by dvě spuštění téhož backtestu mohla dát jiné portfolio.

### 4. Výstupní politika — jak se z pozice vychází

Deklarativní, protože stop závisí na vstupní ceně konkrétní pozice — vektorizovaně to vyjádřit nejde a engine to musí simulovat.

```
ExitPolicy:
  initial_stop      násobek ATR pod vstupní cenou, nebo procento
  scale_outs        posloupnost odprodejů: podíl pozice + spouštěč + volitelný posun stopu
  max_holding_days  časový limit
  trailing          NEPOUŽITO ve v1 — místo v kontraktu zůstává
```

Částečný odprodej má tři parametry: **jaký podíl** původní pozice se prodá, **při jakém spouštěči** (násobek počátečního rizika *R*, násobek ATR, nebo procentní zisk), a **kam se posune stop** pro zbytek pozice (typicky na break-even).

**Důsledek, který se propisuje dál:** pozice přestává být jedna věc s jedním vstupem a jedním výstupem a stává se posloupností nohou. Proto je nutná definice níže.

### 5. Metadata — co strategie potřebuje a čím je identifikovaná

Verze, seznam požadovaných featur, warm-up v barech, název a verze definice univerza, datum platnosti vah, směr.

## Definice obchodu pro metriky

Kvůli částečným odprodejům je nutné to říct explicitně, jinak by každá statistika byla dvojznačná:

- **Obchod** je celý životní cyklus pozice od prvního vstupu do úplného uzavření.
- **Noha** je jeden dílčí odprodej v rámci obchodu.
- Metriky typu „win rate", „průměrný zisk" a „počet obchodů" se počítají **nad obchody**, ne nad nohami.
- Doba držení obchodu se měří od vstupu do **poslední** nohy.
- Realizovaný zisk obchodu je součet zisků všech nohou vůči průměrné vstupní ceně.

## Nejednoznačnost v rámci jednoho dne

Když ve stejném denním baru mohl padnout stop i spouštěč odprodeje, denní bar neříká, co bylo první. Pravidlo:

1. **Jsou-li pro instrument a datum dostupná intradenní data**, pořadí se určí z nich.
2. **Nejsou-li**, platí pesimistický předpoklad: **stop vyhrál**.
3. Backtest u každého obchodu zapíše, kterou metodou pořadí rozhodl.

Ten třetí bod je důležitý: umožňuje změřit, kolik ten pesimismus stojí, a je to jediné konkrétní odůvodnění, proč se intradenní data pro 500 nejlikvidnějších tickerů vůbec kupují.

## Veřejné API

```python
@dataclass(frozen=True)
class ScoreComponent:
    name: str
    weight: float
    max_points: float
    required_features: Sequence[FeatureRequest]

    def points(self, panel: BarPanel, features: FeatureSet) -> pd.DataFrame:
        """Široká matice bodů v intervalu ⟨0, max_points⟩, stejný tvar jako panel.
        NaN znamená „nelze vyhodnotit" a instrument tím pádem není eligible."""


@dataclass(frozen=True)
class StopRule:
    kind: Literal["atr_multiple", "percent"]
    value: float


@dataclass(frozen=True)
class ScaleOut:
    fraction: float                                    # podíl původní pozice, 0 < f < 1
    trigger_kind: Literal["r_multiple", "atr_multiple", "percent_gain"]
    trigger_value: float
    move_stop_to: None | Literal["breakeven"]


@dataclass(frozen=True)
class ExitPolicy:
    initial_stop: StopRule
    scale_outs: Sequence[ScaleOut]
    max_holding_days: int
    trailing: None = None        # rezervováno, ve v1 vždy None


@dataclass(frozen=True)
class EntryRule:
    kind: Literal["top_n", "min_rank_percentile"]
    value: float


@dataclass(frozen=True)
class StrategySpec:
    name: str
    version: str
    universe_definition: str
    universe_version: int
    warmup_bars: int
    weights_as_of: date
    components: Sequence[ScoreComponent]
    entry: EntryRule
    exit: ExitPolicy
    direction: Literal["long"] = "long"

    def required_features(self) -> Sequence[FeatureRequest]:
        """Sjednocení požadavků všech komponent, deduplikované podle feature_id."""


def validate(spec: StrategySpec, panel: BarPanel) -> None:
    """Selže s chybou, když deklarace nesedí na dostupná data.
    Kontroluje: existenci verze univerza, dostatek historie na warmup_bars,
    dostupnost všech required_features, součet fraction ve scale_outs < 1."""


def score(spec: StrategySpec, panel: BarPanel, features: FeatureSet) -> pd.DataFrame:
    """Široká matice score. NaN tam, kde instrument není eligible."""


def entries(spec: StrategySpec, scores: pd.DataFrame, eligible: pd.DataFrame) -> pd.DataFrame:
    """Booleovská matice vstupních signálů podle cross-sectional pravidla."""
```

`validate()` je záměrně samostatná funkce, která se pouští **před** simulací a **selže**, ne varuje. Kontrola „součet `fraction` ve `scale_outs` < 1" je tam proto, že odprodej 100 % pozice po částech je maskovaný úplný výstup a patří vyjádřit jinak.

## Verzování a point-in-time váhy

Strategie nese `version` (ruční, semver-like) a systém k ní při každém běhu ukládá **hash obsahu modulu strategie**. Důvod je praktický: srovnávat dva backtesty má smysl jen když víš, jestli se mezi nimi změnila logika, a na to se ruční verze nedá spolehnout.

Váhy nesou `weights_as_of`. Pravidlo: **backtest k datu *D* smí použít jen váhy s `weights_as_of` ≤ *D***. Když v backtestu na roku 2015 použiješ váhy určené v roce 2026, backtest je fikce a bude vypadat mnohem lépe, než jaká byla realita.

Ve v1 se váhy nastavují ručně, takže `weights_as_of` je datum, kdy je člověk zapsal. Kontrakt je ale od začátku takový, že fitované váhy se do něj vejdou bez přepisu — jen jich bude víc, každá se svým datem, a rekalibrační mechanika přijde v podprojektu 5.

## Testování

`pytest`, žádná síť, žádná závislost na stáhnutém dumpu.

| Co | Jak |
|---|---|
| Komponenta | každá samostatně: body jsou v deklarovaném rozsahu, `NaN` na nevyhodnotitelných místech |
| Score | golden: dvě komponenty se známými body a vahami dají ručně spočítaný součet |
| Score invariance | vynásobení všech vah konstantou **nesmí změnit** výslednou množinu vstupů — přímý test toho, že absolutní škála je nepodstatná |
| `top_n` | při shodných score se řadí deterministicky podle `instrument_id`; dvě spuštění dají stejné portfolio |
| `top_n` s málo kandidáty | méně eligible instrumentů než *n* → vstup do všech, ne chyba |
| Eligibility | instrument mimo univerzum, s krátkou historií, nebo s otevřeným `error` findingem není kandidát |
| `validate` | chybějící featura, warm-up delší než data, neexistující verze univerza a součet `fraction` ≥ 1 — každý případ selže vlastní chybou |
| Point-in-time váhy | váhy s `weights_as_of` po datu backtestu způsobí chybu, ne tiché použití |
| Cross-sectional čistota | vstupy k datu *D* spočítané nad zkrácenými daty (≤ *D*) se rovnají vstupům nad plnými daty odečteným k *D* |

## Rizika

**Overfitting je hlavní riziko celého podprojektu.** Scoring systém s mnoha komponentami a volnými vahami má hodně volných parametrů a na 20 letech dat se vždycky najde kombinace, která vypadá skvěle a nefunguje. Obrana zapsaná do specifikace:

- **Nejvýš 5–7 komponent.** Ne technický limit, ale pravidlo, které se má vědomě porušovat, ne omylem překročit.
- **Hrubé váhy** — celá čísla, ideálně 1–3. Spojitě ladit váhy znamená fitovat.
- **Povinné ověření přírůstku**: přidání komponenty se přijímá jen když zlepší výsledek **out-of-sample**, ne in-sample. Mechanika je v podprojektu 5, ale pravidlo patří sem.

**Neúplnost scale-out modelu.** Podpora částečných odprodejů zvyšuje počet parametrů (podíl, spouštěč, posun stopu — a to za každý odprodej). Dva odprodeje znamenají šest dalších volných parametrů. Doporučení do specifikace: začít s **jedním** odprodejem, a druhý přidat jen když první prokazatelně pomáhá.

**Absence trailingu může být citelná.** Bez trailingu se zisk realizuje jen na předem daných úrovních a časovým limitem, takže silný trend se nevyužije celý. Není to chyba návrhu — je to vědomá volba zadavatele a `ExitPolicy` má na trailing rezervované místo. Pokud se v podprojektu 5 ukáže, že strategie nechává na stole hodně, je to první věc, kterou zkusit.

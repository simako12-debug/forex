"""Načtení snapshotu do širokých matic.

Index jsou obchodní dny z kalendáře, sloupce instrumenty. Cross-sectional operace
je pak jeden řádkový výpočet a per-instrument operace jeden sloupcový.

Panel se staví vždy jen nad zadanými instrumenty, nikdy nad celým katalogem:
1500 instrumentů × 6300 dní je ~75 MB na featuru, 16k instrumentů by bylo ~800 MB.
"""

import json
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import cast

import pandas as pd

from forx.errors import AdjustmentVersionMismatchError, IncompleteSnapshotError, UnknownInputError
from forx.request import VALID_INPUTS

EXPECTED_ADJUSTMENT_LOGIC_VERSION = 1

DEFAULT_UNIVERSE = ("liquid_us", 1)

_BAR_COLUMNS = ("adj_open", "adj_high", "adj_low", "adj_close", "adj_volume", "close", "volume")


@dataclass(frozen=True)
class BarPanel:
    """Široké matice OHLCV pro jedno období a jednu množinu instrumentů.

    Vedle hodnot nese dvě masky. listed_mask říká, jestli instrument k tomu dni
    vůbec existoval — bez ní by nešlo odlišit „nelistovaný" od warm-upu.
    universe_mask říká, jestli byl k tomu dni členem univerza; cross-sectional
    rank se počítá jen nad členy k danému dni, ne nad dnešním univerzem.
    """

    adj_open: pd.DataFrame
    adj_high: pd.DataFrame
    adj_low: pd.DataFrame
    adj_close: pd.DataFrame
    adj_volume: pd.DataFrame
    close: pd.DataFrame
    volume: pd.DataFrame
    listed_mask: pd.DataFrame
    universe_mask: pd.DataFrame

    def frame(self, name: str) -> pd.DataFrame:
        """Vrátí matici podle jména vstupu z FeatureRequest.input.

        Neznámé jméno je hlasitá chyba, ne AttributeError o kus dál. Seznam
        povolených vstupů žije v jednom místě jako VALID_INPUTS.
        """
        if name not in VALID_INPUTS:
            raise UnknownInputError(name, VALID_INPUTS)

        if name == "dollar_volume":
            return self.close * self.volume

        return getattr(self, name)  # type: ignore[no-any-return]


def load_panel(
    start: date,
    end: date,
    instrument_ids: list[str],
    parquet_root: Path,
    universe: tuple[str, int] = DEFAULT_UNIVERSE,
) -> BarPanel:
    """Načte snapshot z `parquet_root` a poskládá ho do panelu nad `instrument_ids`.

    Ověření verze adjustmentu proběhne první — dál by šlo počítat nad daty, která
    už neodpovídají tomu, jak je Python interpretuje.
    """
    _verify_manifest(parquet_root)

    columns = sorted(instrument_ids)
    trading_days = _trading_days(parquet_root, start, end)
    bars = _read_bars(parquet_root, start, end, columns)

    frames = {name: _pivot(bars, name, trading_days, columns) for name in _BAR_COLUMNS}
    listed_mask = _listed_mask(parquet_root, trading_days, columns)

    # listed_mask je autorita. Bar mimo okno listingu je datová chyba podprojektu 1,
    # ne signál — daily_bars se při ingestu proti listed_at/delisted_at nefiltrují,
    # takže vadný vendor dump takový řádek propustí. Bez maskování by missing_reasons
    # hlásilo PRESENT pro buňku, o které listed_mask tvrdí, že instrument neexistoval.
    frames = {name: frame.where(listed_mask) for name, frame in frames.items()}

    return BarPanel(
        **frames,
        listed_mask=listed_mask,
        universe_mask=_universe_mask(parquet_root, trading_days, columns, universe),
    )


def _verify_manifest(parquet_root: Path) -> None:
    payload = json.loads((parquet_root / "manifest.json").read_text(encoding="utf-8"))
    found = int(payload["adjustment_logic_version"])

    if found != EXPECTED_ADJUSTMENT_LOGIC_VERSION:
        raise AdjustmentVersionMismatchError(EXPECTED_ADJUSTMENT_LOGIC_VERSION, found)


def _trading_days(parquet_root: Path, start: date, end: date) -> pd.DatetimeIndex:
    calendar = pd.read_parquet(parquet_root / "meta" / "market_days.parquet")
    calendar = calendar[calendar["is_open"]]
    days = pd.to_datetime(calendar["date"])
    selected = days[(days >= pd.Timestamp(start)) & (days <= pd.Timestamp(end))]

    return pd.DatetimeIndex(sorted(selected.unique()))


def _read_bars(parquet_root: Path, start: date, end: date, columns: list[str]) -> pd.DataFrame:
    parts = []

    for year in range(start.year, end.year + 1):
        path = parquet_root / "daily" / f"year={year}" / "part.parquet"

        # Chybějící rok je chyba, ne prázdno. Tiché přeskočení by vyrobilo oblast
        # samých NaN nerozeznatelnou od warm-upu nebo delistingu.
        if not path.exists():
            raise IncompleteSnapshotError(year, str(path))

        parts.append(pd.read_parquet(path))

    if not parts:
        return pd.DataFrame(columns=["instrument_id", "date", *_BAR_COLUMNS])

    bars = pd.concat(parts, ignore_index=True)
    bars["date"] = pd.to_datetime(bars["date"])

    return bars[bars["instrument_id"].isin(columns)]


def _pivot(bars: pd.DataFrame, value: str, index: pd.DatetimeIndex, columns: list[str]) -> pd.DataFrame:
    if bars.empty:
        return pd.DataFrame(index=index, columns=columns, dtype="float64")

    wide = bars.pivot_table(index="date", columns="instrument_id", values=value, aggfunc="last")

    return wide.reindex(index=index, columns=columns).astype("float64")


def _listed_mask(parquet_root: Path, index: pd.DatetimeIndex, columns: list[str]) -> pd.DataFrame:
    instruments = pd.read_parquet(parquet_root / "meta" / "instruments.parquet").set_index("id")
    mask = pd.DataFrame(False, index=index, columns=columns)

    for instrument_id in columns:
        if instrument_id not in instruments.index:
            continue

        listed_at = cast(pd.Timestamp, instruments.loc[instrument_id, "listed_at"])
        delisted_at = cast(pd.Timestamp, instruments.loc[instrument_id, "delisted_at"])

        active = pd.Series(True, index=index)

        if pd.notna(listed_at):
            active = active & pd.Series(index >= listed_at, index=index)

        if pd.notna(delisted_at):
            active = active & pd.Series(index <= delisted_at, index=index)

        mask[instrument_id] = active

    return mask


def _universe_mask(
    parquet_root: Path, index: pd.DatetimeIndex, columns: list[str], universe: tuple[str, int]
) -> pd.DataFrame:
    members = pd.read_parquet(parquet_root / "meta" / "universe_members.parquet")
    members = members[
        (members["definition_name"] == universe[0]) & (members["definition_version"] == universe[1])
    ]
    members = members.copy()
    members["date"] = pd.to_datetime(members["date"])
    members = members[members["instrument_id"].isin(columns)]

    if members.empty:
        return pd.DataFrame(False, index=index, columns=columns)

    members["member"] = True
    wide = members.pivot_table(index="date", columns="instrument_id", values="member", aggfunc="last")

    return wide.reindex(index=index, columns=columns).fillna(False).astype(bool)

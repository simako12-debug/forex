"""Kanonický snapshot pro testy indikátorové vrstvy.

Je záměrně jiný než fixture podprojektu 1: menší (4 instrumenty × 250 dní) a
zaměřený na to, co potřebuje indikátorová vrstva — delisting v polovině, pozdní
vstup, třídenní mezera a benchmark s plnou historií.

Ceny jsou deterministické, počítané ze vzorce, ne náhodné. Golden testy potřebují
znát hodnoty předem.
"""

import json
import math
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path

import pandas as pd

ADJUSTMENT_LOGIC_VERSION = 1

FULL_ID = "11111111-1111-1111-1111-111111111111"
DELISTED_ID = "22222222-2222-2222-2222-222222222222"
LATECOMER_ID = "33333333-3333-3333-3333-333333333333"
BENCHMARK_ID = "44444444-4444-4444-4444-444444444444"

START = date(2019, 1, 1)
TRADING_DAYS = 250
DELISTING_INDEX = 125
LATECOMER_INDEX = 50
GAP_INDEXES = (200, 201, 202)


@dataclass(frozen=True)
class SnapshotSpec:
    """Popis toho, co ve fixture je — testy proti němu tvrdí, ne proti magickým konstantám."""

    dates: tuple[date, ...]
    instrument_ids: tuple[str, ...]
    delisted_id: str
    delisted_last_date: date
    latecomer_id: str
    latecomer_first_date: date
    gap_id: str
    gap_dates: tuple[date, ...]
    benchmark_id: str


def _trading_dates() -> tuple[date, ...]:
    dates: list[date] = []
    day = START

    while len(dates) < TRADING_DAYS:
        if day.weekday() < 5:
            dates.append(day)

        day += timedelta(days=1)

    return tuple(dates)


def _close_for(instrument_id: str, index: int) -> float:
    """Deterministická, hladká a nezáporná řada. Sinus dá lokální maxima i minima,
    takže rolling_high a rolling_low mají co najít."""
    base = {FULL_ID: 100.0, DELISTED_ID: 50.0, LATECOMER_ID: 20.0, BENCHMARK_ID: 200.0}[instrument_id]

    return round(base * (1.0 + 0.05 * math.sin(index / 7.0)) + index * 0.01, 4)


def _is_active(instrument_id: str, index: int) -> bool:
    if instrument_id == DELISTED_ID:
        return index <= DELISTING_INDEX

    if instrument_id == LATECOMER_ID:
        return index >= LATECOMER_INDEX

    return True


def write_snapshot(root: Path) -> SnapshotSpec:
    """Zapíše kanonický Parquet snapshot do `root` a vrátí popis toho, co v něm je."""
    dates = _trading_dates()
    instrument_ids = (FULL_ID, DELISTED_ID, LATECOMER_ID, BENCHMARK_ID)

    _write_bars(root, dates, instrument_ids)
    _write_metadata(root, dates, instrument_ids)
    _write_manifest(root, dates)

    return SnapshotSpec(
        dates=dates,
        instrument_ids=instrument_ids,
        delisted_id=DELISTED_ID,
        delisted_last_date=dates[DELISTING_INDEX],
        latecomer_id=LATECOMER_ID,
        latecomer_first_date=dates[LATECOMER_INDEX],
        gap_id=FULL_ID,
        gap_dates=tuple(dates[i] for i in GAP_INDEXES),
        benchmark_id=BENCHMARK_ID,
    )


def _write_bars(root: Path, dates: tuple[date, ...], instrument_ids: tuple[str, ...]) -> None:
    rows: list[dict[str, object]] = []

    for index, day in enumerate(dates):
        for instrument_id in instrument_ids:
            if not _is_active(instrument_id, index):
                continue

            if instrument_id == FULL_ID and index in GAP_INDEXES:
                continue

            close = _close_for(instrument_id, index)
            rows.append(
                {
                    "instrument_id": instrument_id,
                    "date": day,
                    "open": close,
                    "high": close + 1.0,
                    "low": close - 1.0,
                    "close": close,
                    "volume": 1_000_000 + index * 1_000,
                    "adj_open": close,
                    "adj_high": close + 1.0,
                    "adj_low": close - 1.0,
                    "adj_close": close,
                    "adj_volume": 1_000_000 + index * 1_000,
                    "cum_split_factor": 1.0,
                    "cum_div_factor": 1.0,
                    "source": "fixture",
                }
            )

    frame = pd.DataFrame(rows)
    frame["date"] = pd.to_datetime(frame["date"])

    for year, group in frame.groupby(frame["date"].dt.year):
        year_dir = root / "daily" / f"year={year}"
        year_dir.mkdir(parents=True, exist_ok=True)
        group.to_parquet(year_dir / "part.parquet", index=False)


def _write_metadata(root: Path, dates: tuple[date, ...], instrument_ids: tuple[str, ...]) -> None:
    meta_dir = root / "meta"
    meta_dir.mkdir(parents=True, exist_ok=True)

    instruments = pd.DataFrame(
        [
            {
                "id": instrument_id,
                "name": f"Fixture {instrument_id[:8]}",
                "asset_class": "us_equity",
                "primary_exchange": "NYSE",
                "sector": "Industrials",
                "listed_at": dates[LATECOMER_INDEX] if instrument_id == LATECOMER_ID else dates[0],
                "delisted_at": dates[DELISTING_INDEX] if instrument_id == DELISTED_ID else None,
                "delisting_reason": "acquired" if instrument_id == DELISTED_ID else None,
            }
            for instrument_id in instrument_ids
        ]
    )
    instruments["listed_at"] = pd.to_datetime(instruments["listed_at"])
    instruments["delisted_at"] = pd.to_datetime(instruments["delisted_at"])
    instruments.to_parquet(meta_dir / "instruments.parquet", index=False)

    members = pd.DataFrame(
        [
            {
                "definition_name": "liquid_us",
                "definition_version": 1,
                "date": day,
                "instrument_id": instrument_id,
            }
            for index, day in enumerate(dates)
            for instrument_id in instrument_ids
            if _is_active(instrument_id, index)
        ]
    )
    members["date"] = pd.to_datetime(members["date"])
    members.to_parquet(meta_dir / "universe_members.parquet", index=False)

    market_days = pd.DataFrame(
        [{"exchange": "XNYS", "date": day, "is_open": True, "is_early_close": False} for day in dates]
    )
    market_days["date"] = pd.to_datetime(market_days["date"])
    market_days.to_parquet(meta_dir / "market_days.parquet", index=False)


def _write_manifest(root: Path, dates: tuple[date, ...]) -> None:
    payload = {
        "adjustment_logic_version": ADJUSTMENT_LOGIC_VERSION,
        "exported_at": "2026-08-07T10:00:00+00:00",
        "years": sorted({day.year for day in dates}),
        "row_counts": {"daily_bars": 0},
    }
    (root / "manifest.json").write_text(json.dumps(payload), encoding="utf-8")

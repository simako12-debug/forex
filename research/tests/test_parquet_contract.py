"""Jediné místo, kde se pozná, že se PHP export a Python čtení rozešly.

Proto je tento test povinný, ne volitelný.

Kromě `daily_bars_adjusted` (export_parquet.py) pokrývá i tři metadatové
Parquety, které skládá export_metadata.py: panel.py jejich sloupce čte natvrdo
a bez tohoto testu by přejmenování sloupce v SQL nechalo zbytek sady zelený.
"""

import os

import duckdb
import pyarrow.parquet as pq

EXPECTED_COLUMNS = {
    "instrument_id",
    "date",
    "open",
    "high",
    "low",
    "close",
    "volume",
    "adj_open",
    "adj_high",
    "adj_low",
    "adj_close",
    "adj_volume",
    "cum_split_factor",
    "cum_div_factor",
    "source",
}

# Sloupce, které panel.py skutečně čte z každého metadatového Parquetu.
# Instruments: _listed_mask indexuje podle "id" a čte "listed_at"/"delisted_at".
# Universe_members: _universe_mask filtruje podle definition_name/definition_version
# a pivotuje podle date/instrument_id. Market_days: _trading_days filtruje podle
# is_open a čte date.
REQUIRED_METADATA_COLUMNS = {
    "instruments": {"id", "listed_at", "delisted_at"},
    "universe_members": {"definition_name", "definition_version", "date", "instrument_id"},
    "market_days": {"date", "is_open"},
}


def test_schema_matches_contract(exported_parquet_path):
    schema = pq.read_schema(exported_parquet_path)

    assert set(schema.names) == EXPECTED_COLUMNS


def test_row_count_and_checksum(exported_parquet_path, expected_rows, expected_close_sum):
    con = duckdb.connect()
    rows, close_sum = con.execute(
        f"SELECT count(*), sum(adj_close) FROM read_parquet('{exported_parquet_path}')"
    ).fetchone()

    assert rows == expected_rows
    assert abs(float(close_sum) - expected_close_sum) < 1e-6


def test_raw_prices_are_not_adjusted(exported_parquet_path):
    """Surové ceny musí zůstat surové — adjustment žije jen ve sloupcích adj_*."""
    con = duckdb.connect()
    raw_sum, adj_sum = con.execute(
        f"SELECT sum(close), sum(adj_close) FROM read_parquet('{exported_parquet_path}')"
    ).fetchone()

    assert float(raw_sum) != float(adj_sum)


def test_instruments_schema_matches_what_panel_reads(exported_metadata_dir):
    path = os.path.join(exported_metadata_dir, "instruments.parquet")
    schema = pq.read_schema(path)

    assert REQUIRED_METADATA_COLUMNS["instruments"].issubset(set(schema.names))


def test_universe_members_schema_matches_what_panel_reads(exported_metadata_dir):
    path = os.path.join(exported_metadata_dir, "universe_members.parquet")
    schema = pq.read_schema(path)

    assert REQUIRED_METADATA_COLUMNS["universe_members"].issubset(set(schema.names))


def test_market_days_schema_matches_what_panel_reads(exported_metadata_dir):
    path = os.path.join(exported_metadata_dir, "market_days.parquet")
    schema = pq.read_schema(path)

    assert REQUIRED_METADATA_COLUMNS["market_days"].issubset(set(schema.names))

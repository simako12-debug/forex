"""Jediné místo, kde se pozná, že se PHP export a Python čtení rozešly.

Proto je tento test povinný, ne volitelný.
"""

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

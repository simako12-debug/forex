from pathlib import Path

import pyarrow.parquet as pq

from tests.fixtures import write_snapshot


def test_write_snapshot_creates_layout(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    assert (tmp_path / "manifest.json").exists()
    assert (tmp_path / "meta" / "instruments.parquet").exists()
    assert (tmp_path / "meta" / "universe_members.parquet").exists()
    assert (tmp_path / "meta" / "market_days.parquet").exists()
    assert len(spec.dates) == 250
    assert len(spec.instrument_ids) == 4


def test_write_snapshot_bars_have_expected_columns(tmp_path: Path) -> None:
    write_snapshot(tmp_path)

    year_dirs = sorted((tmp_path / "daily").glob("year=*"))
    schema = pq.read_schema(year_dirs[0] / "part.parquet")

    assert {"instrument_id", "date", "close", "volume", "adj_close", "adj_volume"} <= set(schema.names)


def test_write_snapshot_gap_instrument_is_missing_bars(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    year_dirs = sorted((tmp_path / "daily").glob("year=*"))
    table = pq.read_table(year_dirs[0] / "part.parquet").to_pandas()
    gap_rows = table[(table["instrument_id"] == spec.gap_id) & (table["date"].isin(spec.gap_dates))]

    assert len(gap_rows) == 0

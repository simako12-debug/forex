import json
from pathlib import Path

import pandas as pd
import pytest

from forx.errors import AdjustmentVersionMismatchError, IncompleteSnapshotError, UnknownInputError
from forx.panel import load_panel
from tests.fixtures import write_snapshot


def test_load_panel_shape(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    assert panel.adj_close.shape == (len(spec.dates), len(spec.instrument_ids))
    assert list(panel.adj_close.columns) == sorted(spec.instrument_ids)


def test_load_panel_listed_mask_excludes_delisted(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    mask = panel.listed_mask[spec.delisted_id]

    assert bool(mask.loc[str(spec.delisted_last_date)]) is True
    assert bool(mask.iloc[-1]) is False


def test_load_panel_listed_mask_excludes_before_listing(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    mask = panel.listed_mask[spec.latecomer_id]

    assert bool(mask.iloc[0]) is False
    assert bool(mask.loc[str(spec.latecomer_first_date)]) is True


def test_load_panel_exposes_raw_and_adjusted_frames(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    # Indikátory jedou nad upravenými cenami, likvidita nad surovými — panel
    # proto musí nést obojí ve stejném tvaru.
    assert panel.close.shape == panel.adj_close.shape
    assert panel.volume.shape == panel.adj_volume.shape
    assert list(panel.close.columns) == list(panel.adj_close.columns)


def test_load_panel_dollar_volume_uses_raw_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    dollar_volume = panel.frame("dollar_volume")

    expected = panel.close.iloc[100][spec.benchmark_id] * panel.volume.iloc[100][spec.benchmark_id]
    assert dollar_volume.iloc[100][spec.benchmark_id] == pytest.approx(expected)


def test_load_panel_rejects_wrong_adjustment_version(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    manifest_path = tmp_path / "manifest.json"
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    payload["adjustment_logic_version"] = 99
    manifest_path.write_text(json.dumps(payload), encoding="utf-8")

    with pytest.raises(AdjustmentVersionMismatchError):
        load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_load_panel_universe_mask_follows_membership(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    assert bool(panel.universe_mask[spec.latecomer_id].iloc[0]) is False
    assert bool(panel.universe_mask[spec.latecomer_id].loc[str(spec.latecomer_first_date)]) is True


def test_load_panel_rejects_missing_year(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    for part in (tmp_path / "daily").glob("year=*/part.parquet"):
        part.unlink()

    with pytest.raises(IncompleteSnapshotError):
        load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_load_panel_rejects_year_missing_from_manifest(tmp_path: Path) -> None:
    """SnapshotExporter nemaže staré roční soubory — manifest je jediná autorita
    o tom, které roky snapshot skutečně pokrývá. Soubor na disku bez záznamu
    v manifestu je stejný druh problému jako soubor, který chybí úplně."""
    spec = write_snapshot(tmp_path)
    manifest_path = tmp_path / "manifest.json"
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    payload["years"] = [year for year in payload["years"] if year != spec.dates[0].year]
    manifest_path.write_text(json.dumps(payload), encoding="utf-8")

    with pytest.raises(IncompleteSnapshotError):
        load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_frame_rejects_unknown_input(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    with pytest.raises(UnknownInputError):
        panel.frame("neexistujici_vstup")


def test_load_panel_masks_bars_outside_listing_window(tmp_path: Path) -> None:
    """Bar po delistingu se nesmí dostat do panelu jako platná hodnota.

    Bez maskování by missing_reasons ohlásilo PRESENT pro buňku, o které
    listed_mask tvrdí, že instrument tehdy neexistoval.
    """
    spec = write_snapshot(tmp_path)
    part = next((tmp_path / "daily").glob("year=*/part.parquet"))
    bars = pd.read_parquet(part)

    after_delisting = bars[bars["instrument_id"] == spec.delisted_id].iloc[-1].copy()
    after_delisting["date"] = pd.Timestamp(spec.dates[-1])
    pd.concat([bars, after_delisting.to_frame().T], ignore_index=True).to_parquet(part, index=False)

    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)

    assert bool(panel.listed_mask[spec.delisted_id].iloc[-1]) is False
    assert pd.isna(panel.adj_close[spec.delisted_id].iloc[-1])
    assert pd.isna(panel.close[spec.delisted_id].iloc[-1])

from pathlib import Path

from forx.compute import compute
from forx.missing import MissingReason, missing_reasons
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot


def test_missing_reasons_distinguishes_all_three(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    # 1. nelistovaný: latecomer první den
    assert reasons.loc[str(spec.dates[0]), spec.latecomer_id] == MissingReason.NOT_LISTED
    # 2. warm-up: plný instrument první den, kdy už je listovaný ale nemá 5 hodnot
    assert reasons.loc[str(spec.dates[0]), spec.instrument_ids[0]] == MissingReason.WARMUP
    # 3. mezera v datech: den, kdy bar chybí, ale instrument existoval
    assert reasons.loc[str(spec.gap_dates[0]), spec.gap_id] == MissingReason.DATA_GAP


def test_missing_reasons_marks_present_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    assert reasons.loc[str(spec.dates[100]), spec.benchmark_id] == MissingReason.PRESENT


def test_missing_reasons_after_delisting_is_not_listed(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    reasons = missing_reasons(panel, values)

    assert reasons.iloc[-1][spec.delisted_id] == MissingReason.NOT_LISTED


def test_missing_reasons_shape_matches_values(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5})])
    values = features.get("sma(input=adj_close,window=5)")

    assert missing_reasons(panel, values).shape == values.shape

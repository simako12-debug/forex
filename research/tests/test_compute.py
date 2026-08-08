from pathlib import Path

import pytest

from forx.compute import compute
from forx.errors import InsufficientHistoryError, UnknownFeatureError
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot


def _panel(tmp_path: Path):
    spec = write_snapshot(tmp_path)

    return spec, load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def test_compute_returns_requested_feature(tmp_path: Path) -> None:
    spec, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])

    frame = features.get("sma(input=adj_close,window=20)")
    assert frame.shape == panel.adj_close.shape


def test_compute_lists_feature_ids(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(
        panel,
        [
            FeatureRequest(name="sma", params={"window": 20}),
            FeatureRequest(name="rsi", params={"window": 14}),
        ],
    )

    assert set(features.feature_ids()) == {
        "sma(input=adj_close,window=20)",
        "rsi(input=adj_close,window=14)",
    }


def test_compute_is_lazy(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])

    assert features.computed_count() == 0
    features.get("sma(input=adj_close,window=20)")
    assert features.computed_count() == 1


def test_compute_caches_result(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 20})])
    first = features.get("sma(input=adj_close,window=20)")
    second = features.get("sma(input=adj_close,window=20)")

    assert first is second


def test_compute_unknown_feature_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    with pytest.raises(UnknownFeatureError):
        compute(panel, [FeatureRequest(name="neexistuje", params={})])


def test_compute_cs_rank_without_source_request_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    with pytest.raises(UnknownFeatureError):
        compute(panel, [FeatureRequest(name="cs_rank", params={"source": "sma(input=adj_close,window=99)"})])


def test_compute_warmup_longer_than_history_raises(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 5000})])

    with pytest.raises(InsufficientHistoryError):
        features.get("sma(input=adj_close,window=5000)")

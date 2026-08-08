from pathlib import Path

import pandas as pd
import pytest

from forx.compute import compute
from forx.errors import InsufficientHistoryError, InvalidWindowError, UnknownFeatureError
from forx.features.cross_section import cs_rank
from forx.features.moving import sma
from forx.features.relative import relative_strength
from forx.features.wilder import atr
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot


def _panel(tmp_path: Path):
    spec = write_snapshot(tmp_path)

    return spec, load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)


def _panel_with_rows(tmp_path: Path, rows: int):
    """Panel useknutý na přesně `rows` obchodních dní od začátku fixture."""
    spec = write_snapshot(tmp_path)

    return spec, load_panel(spec.dates[0], spec.dates[rows - 1], list(spec.instrument_ids), tmp_path)


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


def test_compute_warmup_exactly_window_raises_for_rsi(tmp_path: Path) -> None:
    """rsi spotřebuje jeden řádek na první změnu ceny — window řádků nestačí."""
    _, panel = _panel_with_rows(tmp_path, 14)

    features = compute(panel, [FeatureRequest(name="rsi", params={"window": 14})])

    with pytest.raises(InsufficientHistoryError):
        features.get("rsi(input=adj_close,window=14)")


def test_compute_warmup_window_plus_one_passes_for_rsi(tmp_path: Path) -> None:
    _, panel = _panel_with_rows(tmp_path, 15)

    features = compute(panel, [FeatureRequest(name="rsi", params={"window": 14})])
    frame = features.get("rsi(input=adj_close,window=14)")

    assert frame.shape == panel.adj_close.shape


def test_compute_warmup_exactly_window_raises_for_relative_strength(tmp_path: Path) -> None:
    """relative_strength potřebuje existující řádek t - window — window řádků nestačí."""
    spec, panel = _panel_with_rows(tmp_path, 14)
    request = FeatureRequest(name="relative_strength", params={"window": 14, "benchmark": spec.benchmark_id})

    features = compute(panel, [request])

    with pytest.raises(InsufficientHistoryError):
        features.get(request.feature_id)


def test_compute_warmup_window_plus_one_passes_for_relative_strength(tmp_path: Path) -> None:
    spec, panel = _panel_with_rows(tmp_path, 15)
    request = FeatureRequest(name="relative_strength", params={"window": 14, "benchmark": spec.benchmark_id})

    features = compute(panel, [request])
    frame = features.get(request.feature_id)

    assert frame.shape == panel.adj_close.shape


def test_compute_warmup_exactly_window_passes_for_atr(tmp_path: Path) -> None:
    """atr na rozdíl od rsi window řádků stačí — TR na první pozici nepotřebuje C_{t-1}."""
    _, panel = _panel_with_rows(tmp_path, 14)

    features = compute(panel, [FeatureRequest(name="atr", params={"window": 14})])
    frame = features.get("atr(input=adj_close,window=14)")

    assert frame.shape == panel.adj_close.shape


def test_compute_zero_window_raises_invalid_window(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="sma", params={"window": 0})])

    with pytest.raises(InvalidWindowError):
        features.get("sma(input=adj_close,window=0)")


def test_compute_negative_window_raises_invalid_window(tmp_path: Path) -> None:
    _, panel = _panel(tmp_path)

    features = compute(panel, [FeatureRequest(name="ema", params={"window": -5})])

    with pytest.raises(InvalidWindowError):
        features.get("ema(input=adj_close,window=-5)")


def test_compute_atr_dispatch_matches_direct_call(tmp_path: Path) -> None:
    """Pojistka proti prohozeným maticím v dispatchi.

    atr bere tři matice a jejich pořadí nejde poznat z výsledku — prohozené
    high a low dá pořád věrohodná čísla. Porovnání s přímým voláním to zachytí.
    """
    _, panel = _panel(tmp_path)
    request = FeatureRequest(name="atr", params={"window": 14})

    actual = compute(panel, [request]).get(request.feature_id)
    expected = atr(panel.adj_high, panel.adj_low, panel.adj_close, window=14)

    pd.testing.assert_frame_equal(actual, expected)


def test_compute_relative_strength_dispatch_matches_direct_call(tmp_path: Path) -> None:
    """benchmark se musí vyjmout z params a předat jako benchmark_id."""
    spec, panel = _panel(tmp_path)
    request = FeatureRequest(
        name="relative_strength",
        params={"window": 20, "benchmark": spec.benchmark_id},
    )

    actual = compute(panel, [request]).get(request.feature_id)
    expected = relative_strength(panel.adj_close, window=20, benchmark_id=spec.benchmark_id)

    pd.testing.assert_frame_equal(actual, expected)


def test_compute_cs_rank_dispatch_matches_direct_call(tmp_path: Path) -> None:
    """cs_rank si zdroj vyžádá rekurzivně a dostane masku univerza, ne panel."""
    _, panel = _panel(tmp_path)
    source = FeatureRequest(name="sma", params={"window": 20})
    ranked = FeatureRequest(name="cs_rank", params={"source": source.feature_id})

    actual = compute(panel, [source, ranked]).get(ranked.feature_id)
    expected = cs_rank(sma(panel.adj_close, window=20), panel.universe_mask)

    pd.testing.assert_frame_equal(actual, expected)

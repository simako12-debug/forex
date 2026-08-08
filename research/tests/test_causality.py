"""Featura spočítaná nad zkrácenými daty (≤ D) se musí rovnat featuře spočítané
nad plnými daty a odečtené k datu D. Když se to nerovná, implementace se dívá
dopředu — a žádné čtení kódu to nezjistí spolehlivěji.

Je to stejný trik jako test point-in-time univerza v podprojektu 1.
"""

from pathlib import Path

import pandas as pd
import pytest

from forx.compute import compute
from forx.panel import load_panel
from forx.request import FeatureRequest
from tests.fixtures import write_snapshot

REQUESTS = [
    FeatureRequest(name="sma", params={"window": 20}),
    FeatureRequest(name="ema", params={"window": 20}),
    FeatureRequest(name="atr", params={"window": 14}),
    FeatureRequest(name="rsi", params={"window": 14}),
    FeatureRequest(name="rolling_high", params={"window": 20}),
    FeatureRequest(name="rolling_low", params={"window": 20}),
    FeatureRequest(name="dollar_volume_ma", params={"window": 20}, input="dollar_volume"),
]


@pytest.mark.parametrize("request_spec", REQUESTS, ids=lambda r: r.name)
def test_feature_is_causal(tmp_path: Path, request_spec: FeatureRequest) -> None:
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[180]

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = (
        compute(truncated_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]
    )

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False, check_exact=True)


def test_relative_strength_is_causal(tmp_path: Path) -> None:
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[180]
    request_spec = FeatureRequest(
        name="relative_strength", params={"window": 20, "benchmark": spec.benchmark_id}
    )

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = (
        compute(truncated_panel, [request_spec]).get(request_spec.feature_id).loc[str(cutoff)]
    )

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False, check_exact=True)


def test_cs_rank_is_causal(tmp_path: Path) -> None:
    """Cutoff MUSÍ ležet před delistingem, jinak test nemá sílu.

    cs_rank se má rankovat nad univerzem k datu D, ne nad dnešním. Kdyby cutoff
    ležel až za všemi změnami členství, univerzum k cutoffu by se rovnalo univerzu
    na konci panelu a regrese používající poslední řádek masky by prošla nepovšimnutá.
    Delisting je ve fixture na indexu 125, takže cutoff 100 obě univerza rozliší:
    ke dni 100 je delistovaný instrument ještě členem, na konci panelu už ne.
    """
    spec = write_snapshot(tmp_path)
    cutoff = spec.dates[100]
    source = FeatureRequest(name="sma", params={"window": 20})
    ranked = FeatureRequest(name="cs_rank", params={"source": source.feature_id})

    full_panel = load_panel(spec.dates[0], spec.dates[-1], list(spec.instrument_ids), tmp_path)
    full_value = compute(full_panel, [source, ranked]).get(ranked.feature_id).loc[str(cutoff)]

    truncated_panel = load_panel(spec.dates[0], cutoff, list(spec.instrument_ids), tmp_path)
    truncated_value = compute(truncated_panel, [source, ranked]).get(ranked.feature_id).loc[str(cutoff)]

    pd.testing.assert_series_equal(full_value, truncated_value, check_names=False, check_exact=True)

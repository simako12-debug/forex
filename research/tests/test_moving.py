import pandas as pd
import pytest

from forx.features.moving import ema, sma


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_sma_golden() -> None:
    result = sma(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[0])
    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(2.0)
    assert result.iloc[3] == pytest.approx(3.0)


def test_ema_first_value_is_sma_of_first_window() -> None:
    result = ema(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(2.0)


def test_ema_recurrence_matches_formula() -> None:
    # alpha = 2/(n+1) = 0.5 pro n=3; EMA_3 = 0.5*4 + 0.5*2 = 3.0
    alpha = 2.0 / (3.0 + 1.0)
    expected = alpha * 4.0 + (1.0 - alpha) * 2.0

    result = ema(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert result.iloc[3] == pytest.approx(expected)


def test_sma_ignores_leading_nan_as_warmup() -> None:
    result = sma(_frame([float("nan"), 2.0, 3.0, 4.0]), window=3)["A"]

    assert pd.isna(result.iloc[2])
    assert result.iloc[3] == pytest.approx(3.0)

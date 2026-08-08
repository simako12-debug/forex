import pandas as pd
import pytest

from forx.features.wilder import atr, rsi


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_atr_golden_wilder_recurrence() -> None:
    high = _frame([10.0, 11.0, 12.0, 11.0])
    low = _frame([8.0, 10.0, 11.0, 9.0])
    close = _frame([9.0, 10.8, 11.5, 9.5])

    # TR_0 = H-L = 2.0 (bez předchozího close)
    # TR_1 = max(1.0, |11-9|=2.0, |10-9|=1.0)      = 2.0
    # TR_2 = max(1.0, |12-10.8|=1.2, |11-10.8|=0.2) = 1.2
    # TR_3 = max(2.0, |11-11.5|=0.5, |9-11.5|=2.5)  = 2.5
    # ATR_2 = (2.0 + 2.0 + 1.2) / 3                 = 1.7333333
    # ATR_3 = (1.7333333 * 2 + 2.5) / 3             = 1.9888889
    result = atr(high, low, close, window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(1.7333333, abs=1e-6)
    assert result.iloc[3] == pytest.approx(1.9888889, abs=1e-6)


def test_rsi_golden_wilder_recurrence() -> None:
    close = _frame([10.0, 11.0, 10.5, 12.0, 11.5])

    # gain/loss: +1.0/0, 0/0.5, +1.5/0, 0/0.5
    # avg_gain_3 = (1.0 + 0 + 1.5)/3 = 0.8333333
    # avg_loss_3 = (0 + 0.5 + 0)/3   = 0.1666667
    # RSI_3 = 100 - 100/(1 + 5.0) = 83.333333
    # avg_gain_4 = (0.8333333*2 + 0)/3   = 0.5555556
    # avg_loss_4 = (0.1666667*2 + 0.5)/3 = 0.2777778
    # RSI_4 = 100 - 100/(1 + 2.0) = 66.666667
    result = rsi(close, window=3)["A"]

    assert pd.isna(result.iloc[2])
    assert result.iloc[3] == pytest.approx(83.333333, abs=1e-5)
    assert result.iloc[4] == pytest.approx(66.666667, abs=1e-5)


def test_atr_reseeds_after_gap() -> None:
    """Chybějící bar ruší stav Wilderovy rekurence, stejně jako u SMA a EMA."""
    high = _frame([10.0, 11.0, 12.0, float("nan"), 12.0, 13.0, 14.0])
    low = _frame([8.0, 10.0, 11.0, float("nan"), 10.0, 11.0, 12.0])
    close = _frame([9.0, 10.8, 11.5, float("nan"), 11.0, 12.0, 13.0])

    result = atr(high, low, close, window=3)["A"]

    assert not pd.isna(result.iloc[2])
    assert pd.isna(result.iloc[3])
    assert pd.isna(result.iloc[4])
    assert pd.isna(result.iloc[5])
    assert not pd.isna(result.iloc[6])


def test_rsi_reseeds_after_gap() -> None:
    """Totéž pro RSI — po mezeře je potřeba window platných změn za sebou."""
    close = _frame([10.0, 11.0, 10.5, 12.0, float("nan"), 12.0, 13.0, 12.5, 13.5])

    result = rsi(close, window=3)["A"]

    assert not pd.isna(result.iloc[3])
    assert pd.isna(result.iloc[4])
    assert pd.isna(result.iloc[5])


def test_rsi_monotonic_series_is_hundred() -> None:
    result = rsi(_frame([1.0, 2.0, 3.0, 4.0, 5.0]), window=3)["A"]

    assert result.iloc[3] == pytest.approx(100.0)
    assert result.iloc[4] == pytest.approx(100.0)


def test_rsi_zero_loss_does_not_divide_by_zero() -> None:
    result = rsi(_frame([1.0, 2.0, 3.0, 4.0]), window=3)["A"]

    assert not pd.isna(result.iloc[3])
    assert result.iloc[3] == pytest.approx(100.0)

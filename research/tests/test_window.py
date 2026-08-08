import pandas as pd
import pytest

from forx.features.window import dollar_volume_ma, rolling_high, rolling_low


def _frame(values: list[float]) -> pd.DataFrame:
    return pd.DataFrame({"A": values}, index=pd.RangeIndex(len(values)))


def test_rolling_high_includes_current_value() -> None:
    result = rolling_high(_frame([1.0, 5.0, 3.0, 2.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(5.0)
    assert result.iloc[3] == pytest.approx(5.0)


def test_rolling_low_includes_current_value() -> None:
    result = rolling_low(_frame([4.0, 5.0, 3.0, 6.0]), window=3)["A"]

    assert result.iloc[2] == pytest.approx(3.0)
    assert result.iloc[3] == pytest.approx(3.0)


def test_dollar_volume_ma_averages_input() -> None:
    result = dollar_volume_ma(_frame([100.0, 200.0, 300.0]), window=3)["A"]

    assert pd.isna(result.iloc[1])
    assert result.iloc[2] == pytest.approx(200.0)

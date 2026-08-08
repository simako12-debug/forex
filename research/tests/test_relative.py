import pandas as pd
import pytest

from forx.errors import UnknownBenchmarkError
from forx.features.relative import relative_strength


def _panel() -> pd.DataFrame:
    return pd.DataFrame(
        {
            "A": [100.0, 110.0, 121.0],
            "BENCH": [100.0, 100.0, 100.0],
        },
        index=pd.RangeIndex(3),
    )


def test_relative_strength_above_one_when_outperforming() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    # (121/100) / (100/100) = 1.21
    assert result["A"].iloc[2] == pytest.approx(1.21)


def test_relative_strength_is_one_for_benchmark_itself() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    assert result["BENCH"].iloc[2] == pytest.approx(1.0)


def test_relative_strength_warmup_is_nan() -> None:
    result = relative_strength(_panel(), window=2, benchmark_id="BENCH")

    assert pd.isna(result["A"].iloc[0])
    assert pd.isna(result["A"].iloc[1])


def test_relative_strength_missing_benchmark_raises() -> None:
    with pytest.raises(UnknownBenchmarkError):
        relative_strength(_panel(), window=2, benchmark_id="NENI")

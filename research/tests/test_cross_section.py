import pandas as pd
import pytest

from forx.features.cross_section import cs_rank


def _frame() -> pd.DataFrame:
    return pd.DataFrame(
        {"A": [1.0], "B": [3.0], "C": [3.0], "D": [float("nan")]},
        index=pd.RangeIndex(1),
    )


def _all_members() -> pd.DataFrame:
    return pd.DataFrame({"A": [True], "B": [True], "C": [True], "D": [True]}, index=pd.RangeIndex(1))


def test_cs_rank_is_percentile() -> None:
    result = cs_rank(_frame(), _all_members())

    # tři platné hodnoty: 1.0 → rank 1, 3.0 a 3.0 → průměrný rank 2.5
    assert result["A"].iloc[0] == pytest.approx(1.0 / 3.0)


def test_cs_rank_ties_get_average_rank() -> None:
    result = cs_rank(_frame(), _all_members())

    assert result["B"].iloc[0] == pytest.approx(2.5 / 3.0)
    assert result["C"].iloc[0] == pytest.approx(2.5 / 3.0)


def test_cs_rank_nan_stays_nan_and_is_not_ranked() -> None:
    result = cs_rank(_frame(), _all_members())

    assert pd.isna(result["D"].iloc[0])


def test_cs_rank_excludes_non_members() -> None:
    mask = pd.DataFrame(
        {"A": [True], "B": [True], "C": [False], "D": [True]}, index=pd.RangeIndex(1)
    )

    result = cs_rank(_frame(), mask)

    # C mimo univerzum → rankují se jen A a B, takže A je 1/2 a C je NaN
    assert pd.isna(result["C"].iloc[0])
    assert result["A"].iloc[0] == pytest.approx(0.5)


def test_cs_rank_is_independent_of_universe_size() -> None:
    small = pd.DataFrame({"A": [1.0], "B": [2.0]}, index=pd.RangeIndex(1))
    small_mask = pd.DataFrame({"A": [True], "B": [True]}, index=pd.RangeIndex(1))
    large = pd.DataFrame({"A": [1.0], "B": [2.0], "C": [3.0], "D": [4.0]}, index=pd.RangeIndex(1))
    large_mask = pd.DataFrame(
        {"A": [True], "B": [True], "C": [True], "D": [True]}, index=pd.RangeIndex(1)
    )

    smallest_in_small = cs_rank(small, small_mask)["A"].iloc[0]
    smallest_in_large = cs_rank(large, large_mask)["A"].iloc[0]

    # nejmenší hodnota má v obou případech percentil 1/n, ne absolutní pozici 1
    assert smallest_in_small == pytest.approx(0.5)
    assert smallest_in_large == pytest.approx(0.25)

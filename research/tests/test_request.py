import pytest

from forx.request import FeatureRequest


def test_feature_id_sorts_params_by_name() -> None:
    request = FeatureRequest(name="sma", params={"window": 20})

    assert request.feature_id == "sma(input=adj_close,window=20)"


def test_feature_id_is_stable_across_param_order() -> None:
    first = FeatureRequest(
        name="relative_strength", params={"window": 20, "benchmark": "SPY"}
    )
    second = FeatureRequest(
        name="relative_strength", params={"benchmark": "SPY", "window": 20}
    )

    assert first.feature_id == second.feature_id


def test_feature_id_includes_non_default_input() -> None:
    request = FeatureRequest(
        name="sma", params={"window": 5}, input="adj_volume"
    )

    assert request.feature_id == "sma(input=adj_volume,window=5)"


def test_feature_id_without_params() -> None:
    request = FeatureRequest(name="dollar_volume", params={})

    assert request.feature_id == "dollar_volume(input=adj_close)"


def test_request_is_frozen() -> None:
    request = FeatureRequest(name="sma", params={"window": 5})

    with pytest.raises(AttributeError):
        request.name = "ema"  # type: ignore[misc]

"""Registr featur.

Jméno v registru je totéž jméno, které nese FeatureRequest.name — tím je
zaručeno, že feature_id odkazuje na skutečně existující výpočet.
"""

from collections.abc import Callable

import pandas as pd

from forx.features.moving import ema, sma

FeatureFn = Callable[..., pd.DataFrame]

REGISTRY: dict[str, FeatureFn] = {
    "sma": sma,
    "ema": ema,
}

__all__ = ["REGISTRY", "FeatureFn", "ema", "sma"]

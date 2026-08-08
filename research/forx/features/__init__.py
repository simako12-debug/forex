"""Registr featur.

Jméno v registru je totéž jméno, které nese FeatureRequest.name — tím je
zaručeno, že feature_id odkazuje na skutečně existující výpočet.
"""

from collections.abc import Callable

import pandas as pd

from forx.features.moving import ema, sma
from forx.features.wilder import atr, rsi

FeatureFn = Callable[..., pd.DataFrame]

REGISTRY: dict[str, FeatureFn] = {
    "atr": atr,
    "ema": ema,
    "rsi": rsi,
    "sma": sma,
}

__all__ = ["REGISTRY", "FeatureFn", "atr", "ema", "rsi", "sma"]

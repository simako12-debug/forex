"""Registr featur.

Jméno v registru je totéž jméno, které nese FeatureRequest.name — tím je
zaručeno, že feature_id odkazuje na skutečně existující výpočet.
"""

from collections.abc import Callable

import pandas as pd

from forx.features.cross_section import cs_rank
from forx.features.moving import ema, sma
from forx.features.relative import relative_strength
from forx.features.wilder import atr, rsi
from forx.features.window import dollar_volume_ma, rolling_high, rolling_low

FeatureFn = Callable[..., pd.DataFrame]

REGISTRY: dict[str, FeatureFn] = {
    "atr": atr,
    "cs_rank": cs_rank,
    "dollar_volume_ma": dollar_volume_ma,
    "ema": ema,
    "relative_strength": relative_strength,
    "rsi": rsi,
    "rolling_high": rolling_high,
    "rolling_low": rolling_low,
    "sma": sma,
}

__all__ = [
    "REGISTRY",
    "FeatureFn",
    "atr",
    "cs_rank",
    "dollar_volume_ma",
    "ema",
    "relative_strength",
    "rsi",
    "rolling_high",
    "rolling_low",
    "sma",
]

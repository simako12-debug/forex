"""Cross-sectional operace — počítají se po řádcích, tedy napříč instrumenty k datu."""

import pandas as pd


def cs_rank(frame: pd.DataFrame, universe_mask: pd.DataFrame) -> pd.DataFrame:
    """Percentilový rank napříč členy univerza k danému dni.

    Tři věci, které musí být explicitní:
      - NaN se z rankování vylučují a zůstávají NaN; nedostávají rank 0.
      - Shodné hodnoty dostávají průměrný rank.
      - Percentil, ne absolutní pozice — jinak by výsledek závisel na velikosti
        univerza k danému dni, která se v čase mění.

    Rankuje se jen nad univerzem k datu D, ne nad dnešním univerzem. To je jedna
    z podmínek kauzality ze specifikace.
    """
    eligible = frame.where(universe_mask.reindex_like(frame).fillna(False))

    return eligible.rank(axis=1, method="average", pct=True, na_option="keep")

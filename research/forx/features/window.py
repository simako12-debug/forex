"""Okenní indikátory nad jednou maticí."""

import pandas as pd


def rolling_high(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Maximum posledních `window` hodnot včetně aktuální."""
    return frame.rolling(window=window, min_periods=window).max()


def rolling_low(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Minimum posledních `window` hodnot včetně aktuální."""
    return frame.rolling(window=window, min_periods=window).min()


def dollar_volume_ma(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Klouzavý průměr dollar volume.

    Vstupem MUSÍ být surové close × volume, ne upravené. Likvidita je vlastnost
    skutečně zobchodovaného objemu v tehdejších cenách; adjustovaný objem by ji
    zkreslil. Vstup vybírá FeatureRequest.input="dollar_volume".
    """
    return frame.rolling(window=window, min_periods=window).mean()

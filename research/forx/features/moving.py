"""Klouzavé průměry.

Definice jsou tady explicitní, protože „EMA" bez uvedení, čím se inicializuje,
není zadání. Golden testy mají vzorec rozepsaný ve svém těle, takže jsou zároveň
dokumentací konvence — kdyby ji někdo v budoucnu „opravil", testy spadnou.

Mezera (NaN) v datech ruší stav rekurze EMA — bez toho by EMA tiše imputovala
Missing data prostřednictvím poslední známé hodnoty, zatímco SMA by zůstala NaN.
"""

import numpy as np
import pandas as pd


def sma(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """Aritmetický průměr posledních `window` hodnot včetně aktuální.

    První `window - 1` hodnot je warm-up a zůstává NaN.
    """
    return frame.rolling(window=window, min_periods=window).mean()


def ema(frame: pd.DataFrame, window: int) -> pd.DataFrame:
    """alpha = 2/(n+1); první hodnota je SMA prvních n hodnot, dál rekurence.

    pandas .ewm() startuje jinak (od první hodnoty), takže se rekurence počítá
    ručně — jinak by se konvence tiše rozešla se specifikací.
    """
    alpha = 2.0 / (window + 1.0)
    seed = frame.rolling(window=window, min_periods=window).mean()
    result = pd.DataFrame(
        np.nan, index=frame.index, columns=frame.columns, dtype="float64"
    )

    for column in frame.columns:
        values = frame[column].to_numpy(dtype="float64")
        seeds = seed[column].to_numpy(dtype="float64")
        output = np.full(len(values), np.nan)
        previous = np.nan

        for position in range(len(values)):
            # Mezera ruší stav rekurze. Seed z rolling().mean() je po mezeře NaN,
            # dokud zase nebude window platných hodnot za sebou — tím se EMA
            # naseeduje znovu a chová se stejně jako SMA.
            if np.isnan(values[position]):
                previous = np.nan

                continue

            if np.isnan(previous):
                if not np.isnan(seeds[position]):
                    previous = seeds[position]
                    output[position] = previous

                continue

            previous = alpha * values[position] + (1.0 - alpha) * previous
            output[position] = previous

        result[column] = output

    return result

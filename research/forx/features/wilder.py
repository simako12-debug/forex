"""Indikátory s Wilderovým vyhlazováním.

Wilderovo vyhlazování NENÍ jednoduchý klouzavý průměr a NENÍ ani standardní EMA.
Rozdíl je tichý — hodnoty vypadají podobně — ale mění výsledky backtestů. TA
knihovny se v této konvenci liší, proto tenká vlastní implementace a golden testy
s rozepsanou rekurencí.
"""

import numpy as np
import pandas as pd


def _wilder_smooth(values: np.ndarray, window: int) -> np.ndarray:
    """ATR_n = mean(x_1..x_n); ATR_t = (ATR_{t-1} * (n-1) + x_t) / n.

    Mezera v datech ruší stav rekurze: po chybějící hodnotě se vyhlazování musí
    naseedovat znovu z window platných hodnot za sebou, stejně jako SMA. Přenášet
    poslední hodnotu přes mezeru by byla tichá imputace.
    """
    output = np.full(len(values), np.nan)
    previous = np.nan
    run = 0

    for position in range(len(values)):
        value = values[position]

        if np.isnan(value):
            previous = np.nan
            run = 0

            continue

        run += 1

        if np.isnan(previous):
            if run < window:
                continue

            previous = float(np.mean(values[position - window + 1 : position + 1]))
            output[position] = previous

            continue

        previous = (previous * (window - 1) + value) / window
        output[position] = previous

    return output


def atr(high: pd.DataFrame, low: pd.DataFrame, close: pd.DataFrame, window: int) -> pd.DataFrame:
    """TR_t = max(H_t - L_t, |H_t - C_{t-1}|, |L_t - C_{t-1}|), pak Wilder.

    První TR série i první TR po mezeře v datech používá jen H − L, protože
    C_{t-1} není k dispozici (posunutý sloupec dá na tom místě NaN a nanmax
    ho z výpočtu vynechá). To je standardní konvence, ne degradace: bar hned
    po mezeře je fakticky začátek nové série.

    Tím je ATR nekonzistentní s RSI, který stejnou situaci řeší jinak: RSI
    change přes mezeru zahodí úplně (NaN) a rekurenci na tom místě naseeduje
    až znovu, protože změna ceny bez dvou platných cen neexistuje. U ATR
    naopak H − L samotného baru smysl dává i bez předchozí ceny, takže
    hodnota nezmizí — jen ztratí složku závislou na C_{t-1}.
    """
    result = pd.DataFrame(np.nan, index=close.index, columns=close.columns, dtype="float64")

    for column in close.columns:
        high_values = high[column].to_numpy(dtype="float64")
        low_values = low[column].to_numpy(dtype="float64")
        close_values = close[column].to_numpy(dtype="float64")

        previous_close = np.concatenate(([np.nan], close_values[:-1]))
        candidates = np.vstack(
            [
                high_values - low_values,
                np.abs(high_values - previous_close),
                np.abs(low_values - previous_close),
            ]
        )

        # nanmax nad sloupcem samých NaN vypíše RuntimeWarning a testový výstup má
        # být čistý. Chybějící bar (H i L jsou NaN) proto do výpočtu nevstupuje;
        # jeho TR zůstane NaN a _wilder_smooth si na něm zruší stav rekurze.
        has_bar = ~np.isnan(high_values) & ~np.isnan(low_values)
        true_range = np.full(len(high_values), np.nan)
        true_range[has_bar] = np.nanmax(candidates[:, has_bar], axis=0)

        result[column] = _wilder_smooth(true_range, window)

    return result


def rsi(close: pd.DataFrame, window: int) -> pd.DataFrame:
    """Wilderovo vyhlazování zisků a ztrát. Při nulové průměrné ztrátě je RSI 100."""
    result = pd.DataFrame(np.nan, index=close.index, columns=close.columns, dtype="float64")

    for column in close.columns:
        values = close[column].to_numpy(dtype="float64")
        change = np.diff(values, prepend=np.nan)

        # np.where(change > 0, ...) by NaN převedlo na 0.0, protože NaN > 0 je False.
        # Mezera by tím prošla jako „cena se nehnula" a rekurence by běžela dál.
        missing = np.isnan(change)
        gains = np.where(missing, np.nan, np.where(change > 0, change, 0.0))
        losses = np.where(missing, np.nan, np.where(change < 0, -change, 0.0))

        # První prvek nemá předchozí hodnotu, takže do vyhlazování nevstupuje.
        average_gain = _wilder_smooth(gains[1:], window)
        average_loss = _wilder_smooth(losses[1:], window)

        with np.errstate(divide="ignore", invalid="ignore"):
            strength = np.where(average_loss == 0.0, np.inf, average_gain / average_loss)
            column_values = np.where(
                np.isnan(average_gain), np.nan, 100.0 - 100.0 / (1.0 + strength)
            )

        result[column] = np.concatenate(([np.nan], column_values))

    return result

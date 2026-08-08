"""Relativní síla proti benchmarku.

Poměr výnosu instrumentu k výnosu benchmarku za stejné okno umožňuje
identifikovat, zda instrument benchmark překonal či zaostává.
"""

import pandas as pd

from forx.errors import UnknownBenchmarkError


def relative_strength(
    frame: pd.DataFrame, window: int, benchmark_id: str
) -> pd.DataFrame:
    """(C_t / C_{t-n}) / (B_t / B_{t-n}); hodnota > 1 znamená překonání benchmarku.

    Benchmark musí být sloupcem panelu — panel se proto staví vždy včetně něj,
    i když není členem univerza.
    """
    if benchmark_id not in frame.columns:
        raise UnknownBenchmarkError(benchmark_id)

    instrument_return = frame / frame.shift(window)
    benchmark_return = frame[benchmark_id] / frame[benchmark_id].shift(window)

    return instrument_return.div(benchmark_return, axis=0)

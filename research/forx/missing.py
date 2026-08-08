"""Proč je hodnota chybějící.

Tři důvody se nesmí slít do jednoho NaN. Kdyby se slily, strategie by hlásila
menší počet příležitostí, než reálně byl, a nikdo by nepoznal, jestli za tím je
nedostatek dat nebo skutečná absence signálu.

Mezera v datech se odvozuje soběstačně — instrument je listovaný, den je podle
kalendáře obchodní a bar chybí. Specifikace zmiňovala potvrzení nálezem
MissingBarOnTradingDay z podprojektu 1, ale odvození nezávislé na historii
ingestu je robustnější.
"""

from enum import StrEnum

import numpy as np
import pandas as pd

from forx.panel import BarPanel


class MissingReason(StrEnum):
    """Čtyři vzájemně se vylučující stavy jedné buňky feature matice."""

    PRESENT = "present"
    NOT_LISTED = "not_listed"
    WARMUP = "warmup"
    DATA_GAP = "data_gap"


def missing_reasons(panel: BarPanel, values: pd.DataFrame) -> pd.DataFrame:
    """Matice stejného tvaru jako `values`, kde každá buňka nese svůj důvod.

    Pořadí přiřazení je záměrné: výchozí stav je warm-up, pak se přepíší
    nelistované buňky, pak mezery v datech (jen tam, kde je instrument
    listovaný) a nakonec přítomné hodnoty — ty musí vyhrát nad vším ostatním,
    protože hodnota buď existuje, nebo je jedno z předchozích tří.
    """
    listed = panel.listed_mask.reindex_like(values).fillna(False).to_numpy(dtype=bool)
    has_bar = panel.adj_close.reindex_like(values).notna().to_numpy(dtype=bool)
    has_value = values.notna().to_numpy(dtype=bool)

    reasons = np.full(values.shape, MissingReason.WARMUP.value, dtype=object)
    reasons[~listed] = MissingReason.NOT_LISTED.value
    reasons[listed & ~has_bar] = MissingReason.DATA_GAP.value
    reasons[has_value] = MissingReason.PRESENT.value

    return pd.DataFrame(reasons, index=values.index, columns=values.columns)

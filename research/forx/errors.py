"""Chybové stavy indikátorové vrstvy.

Každý z nich je hlasité odmítnutí, ne tiché NaN. Specifikace to vyžaduje
u warm-upu i u nesouladu verze adjustmentu.
"""


class ForxError(Exception):
    """Základ pro všechny chyby této vrstvy."""


class AdjustmentVersionMismatchError(ForxError):
    """Snapshot byl vyexportován jinou verzí adjustment logiky, než Python očekává."""

    def __init__(self, expected: int, found: int) -> None:
        super().__init__(
            f"Snapshot nese verzi adjustmentu {found}, očekává se {expected}. "
            "Přegeneruj snapshot příkazem market-data:export-snapshot."
        )
        self.expected = expected
        self.found = found


class InsufficientHistoryError(ForxError):
    """Featura potřebuje delší warm-up, než kolik je v panelu dní."""

    def __init__(self, feature_id: str, required: int, available: int) -> None:
        super().__init__(
            f"Featura {feature_id} potřebuje {required} dní historie, panel jich má {available}."
        )
        self.feature_id = feature_id
        self.required = required
        self.available = available


class UnknownFeatureError(ForxError):
    """Požadavek se odkazuje na featuru, která není v registru."""

    def __init__(self, name: str) -> None:
        super().__init__(f"Neznámá featura: {name}")
        self.name = name

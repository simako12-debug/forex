"""Chybové stavy indikátorové vrstvy.

Každý z nich je hlasité odmítnutí, ne tiché NaN. Specifikace to vyžaduje
u warm-upu i u nesouladu verze adjustmentu.
"""

from collections.abc import Iterable


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


class IncompleteSnapshotError(ForxError):
    """Snapshot postrádá soubor s bary pro rok, který spadá do požadovaného období.

    Tiché přeskočení by vyrobilo oblast samých NaN, kterou downstream nerozezná
    od warm-upu ani od delistingu — přesně to, čemu má rozlišení tří druhů
    chybějící hodnoty zabránit.
    """

    def __init__(self, year: int, path: str) -> None:
        super().__init__(f"Snapshot neobsahuje bary pro rok {year} (očekáváno v {path}).")
        self.year = year
        self.path = path


class UnknownBenchmarkError(ForxError):
    """Požadavek se odkazuje na benchmark, který panel nezná."""

    def __init__(self, name: str) -> None:
        super().__init__(f"Neznámý benchmark: {name}")
        self.name = name


class UnknownInputError(ForxError):
    """Požadavek se odkazuje na vstup, který panel nezná."""

    def __init__(self, name: str, allowed: Iterable[str]) -> None:
        super().__init__(f"Neznámý vstup: {name}. Povolené: {', '.join(sorted(allowed))}")
        self.name = name

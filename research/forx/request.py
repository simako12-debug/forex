"""Požadavek na featuru a jeho deterministický identifikátor."""

from collections.abc import Mapping
from dataclasses import dataclass, field

VALID_INPUTS = frozenset(
    {
        "adj_open",
        "adj_high",
        "adj_low",
        "adj_close",
        "adj_volume",
        "close",
        "volume",
        "dollar_volume",
    }
)


@dataclass(frozen=True)
class FeatureRequest:
    """Co se má spočítat.

    feature_id není kosmetika — ukládá se do záznamu o backtest běhu, aby šlo
    po měsících zjistit, co přesně se počítalo. Parametry se proto řadí podle
    názvu, aby stejná featura měla vždy stejné ID nezávisle na pořadí zápisu.
    """

    name: str
    params: Mapping[str, object] = field(default_factory=dict)
    input: str = "adj_close"

    @property
    def feature_id(self) -> str:
        """Deterministický identifikátor featurového požadavku."""
        parts = [f"input={self.input}"]
        parts.extend(
            f"{key}={self.params[key]}" for key in sorted(self.params)
        )

        return f"{self.name}({','.join(parts)})"

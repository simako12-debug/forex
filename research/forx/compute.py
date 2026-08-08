"""Skládání featur nad panelem.

FeatureSet drží featury líně. Sweep může chtít desítky featur současně a při
75 MB na featuru je 30 featur 2,2 GB — počítat všechny dopředu by byl zbytečný
strop, na který se dá narazit.
"""

from collections.abc import Sequence

import pandas as pd

from forx.errors import InsufficientHistoryError, UnknownFeatureError
from forx.features import REGISTRY
from forx.panel import BarPanel
from forx.request import FeatureRequest

_MULTI_INPUT_FEATURES = frozenset({"atr"})
_CROSS_SECTIONAL_FEATURES = frozenset({"cs_rank"})
_BENCHMARK_FEATURES = frozenset({"relative_strength"})


class FeatureSet:
    """Líný kontejner spočítaných featur."""

    def __init__(self, panel: BarPanel, requests: Sequence[FeatureRequest]) -> None:
        self._panel = panel
        self._requests = {request.feature_id: request for request in requests}
        self._cache: dict[str, pd.DataFrame] = {}

    def feature_ids(self) -> Sequence[str]:
        """Vrátí ID všech požadovaných featur, bez ohledu na to, jestli už jsou spočítané."""
        return tuple(self._requests)

    def computed_count(self) -> int:
        """Kolik featur už je v cache — slouží k ověření líného chování v testech."""
        return len(self._cache)

    def get(self, feature_id: str) -> pd.DataFrame:
        """Vrátí matici pro `feature_id`, při prvním volání ji spočítá a uloží do cache."""
        if feature_id in self._cache:
            return self._cache[feature_id]

        request = self._requests[feature_id]
        self._verify_history(request)
        self._cache[feature_id] = self._evaluate(request)

        return self._cache[feature_id]

    def _verify_history(self, request: FeatureRequest) -> None:
        window = request.params.get("window")

        if not isinstance(window, int):
            return

        available = len(self._panel.adj_close.index)

        if window > available:
            raise InsufficientHistoryError(request.feature_id, window, available)

    def _evaluate(self, request: FeatureRequest) -> pd.DataFrame:
        function = REGISTRY[request.name]

        if request.name in _MULTI_INPUT_FEATURES:
            return function(
                self._panel.adj_high,
                self._panel.adj_low,
                self._panel.adj_close,
                **request.params,
            )

        if request.name in _CROSS_SECTIONAL_FEATURES:
            source_id = str(request.params["source"])

            return function(self.get(source_id), self._panel.universe_mask)

        if request.name in _BENCHMARK_FEATURES:
            params = dict(request.params)
            benchmark_id = str(params.pop("benchmark"))

            return function(self._panel.frame(request.input), benchmark_id=benchmark_id, **params)

        return function(self._panel.frame(request.input), **request.params)


def compute(panel: BarPanel, requests: Sequence[FeatureRequest]) -> FeatureSet:
    """Ověří, že všechny požadované featury existují, a vrátí líný FeatureSet.

    Neznámá featura selže hned při skládání, ne až při čtení — psát překlep
    v názvu a zjistit to za hodinu uprostřed sweepu je zbytečná ztráta.

    Totéž platí pro zdroj cross-sectional featury: cs_rank se odkazuje na jinou
    featuru přes její feature_id, a ta musí být mezi požadavky.
    """
    for request in requests:
        if request.name not in REGISTRY:
            raise UnknownFeatureError(request.name)

    known = {request.feature_id for request in requests}

    for request in requests:
        source = request.params.get("source")

        if source is not None and str(source) not in known:
            raise UnknownFeatureError(str(source))

    return FeatureSet(panel, requests)

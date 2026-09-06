from typing import Any

from app.providers.errors import ProviderError
from app.providers.resolved_llm import ResolvedLanguageModelProvider


class FallbackLanguageModelProvider:
    """Uses only verified, centrally resolved candidates in their route priority order."""

    def __init__(self, candidates: list[dict[str, object]], timeout_seconds: float) -> None:
        self._providers = [
            ResolvedLanguageModelProvider(
                str(candidate["provider"]), str(candidate["api_key"]), str(candidate["model"]), None, timeout_seconds
            )
            for candidate in candidates
        ]
        self.provider_name = self._providers[0].provider_name
        self.model = self._providers[0].model

    def generate(self, research: dict[str, Any], config: dict[str, Any], language: str):
        last_error: ProviderError | None = None
        for provider in self._providers:
            try:
                return provider.generate(research, config, language)
            except ProviderError as error:
                last_error = error
        assert last_error is not None
        raise last_error

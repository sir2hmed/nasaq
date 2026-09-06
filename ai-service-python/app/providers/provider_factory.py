from typing import Any

from app.providers.base_llm import BaseLLMProvider
from app.providers.errors import UnsupportedProvider
from app.providers.gemini_provider import GeminiProvider
from app.providers.llm import DemoLanguageModelProvider
from app.providers.openai_provider import OpenAIProvider


class ProviderFactory:
    @staticmethod
    def create_llm_provider(
        provider_name: str,
        api_key: str | None = None,
        default_model: str | None = None,
        base_url: str | None = None,
        **kwargs: Any,
    ) -> BaseLLMProvider | DemoLanguageModelProvider:
        normalized = (provider_name or "demo").lower().strip()

        if normalized in {"demo", "mock"}:
            return DemoLanguageModelProvider()

        if normalized == "openai":
            model = default_model or "gpt-4o"
            url = base_url or "https://api.openai.com/v1"
            return OpenAIProvider(api_key=api_key, default_model=model, base_url=url, **kwargs)

        if normalized == "gemini":
            model = default_model or "gemini-1.5-flash"
            url = base_url or "https://generativelanguage.googleapis.com/v1beta"
            return GeminiProvider(api_key=api_key, default_model=model, base_url=url, **kwargs)

        raise UnsupportedProvider(normalized, "language model")

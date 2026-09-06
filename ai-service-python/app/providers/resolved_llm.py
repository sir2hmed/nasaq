"""Adapter from the normalized OpenAI/Gemini clients to the workflow writer contract."""

import json
from typing import Any

from app.providers.contracts import GeneratedContent
from app.providers.provider_factory import ProviderFactory


class ResolvedLanguageModelProvider:
    def __init__(
        self, provider: str, api_key: str, model: str, base_url: str | None, timeout_seconds: float
    ) -> None:
        self._provider = ProviderFactory.create_llm_provider(
            provider,
            api_key=api_key,
            default_model=model,
            base_url=base_url,
            timeout_seconds=timeout_seconds,
        )
        self.provider_name = provider
        self.model = model

    def generate(
        self, research: dict[str, Any], config: dict[str, Any], language: str
    ) -> GeneratedContent:
        prompt = {
            "research": research,
            "requirements": {
                "language": language,
                "style": config["style"],
                "length": config["length"],
                "format": config["format"],
                "rule": "Use only supplied sources. Do not invent citations or URLs.",
            },
        }
        response = self._provider.generate(json.dumps(prompt, ensure_ascii=False), model=self.model)
        return GeneratedContent(
            provider=response.provider,
            model=response.model,
            title="Generated content",
            content=response.content,
        )

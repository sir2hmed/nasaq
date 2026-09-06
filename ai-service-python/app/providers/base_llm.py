from abc import ABC, abstractmethod
from typing import Any

from app.providers.normalized_response import NormalizedLLMResponse


class BaseLLMProvider(ABC):
    provider_name: str = "base"

    @abstractmethod
    def generate(
        self,
        prompt: str | dict[str, Any],
        model: str | None = None,
        temperature: float = 0.7,
        max_tokens: int = 2048,
    ) -> NormalizedLLMResponse:
        """Generate text output from the provider."""
        pass

    @abstractmethod
    def generate_structured(
        self,
        prompt: str | dict[str, Any],
        schema: dict[str, Any],
        model: str | None = None,
        temperature: float = 0.7,
        max_tokens: int = 2048,
    ) -> NormalizedLLMResponse:
        """Generate structured JSON output conforming to schema."""
        pass

    @abstractmethod
    def test_connection(self) -> bool:
        """Test server-side provider connection."""
        pass

    @abstractmethod
    def list_models(self) -> list[str]:
        """List supported models for this provider."""
        pass

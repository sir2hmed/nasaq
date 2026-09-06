import json
from typing import Any

import httpx

from app.providers.base_llm import BaseLLMProvider
from app.providers.errors import (
    InvalidProviderResponse,
    MissingProviderCredential,
    ProviderAuthenticationError,
    TransientProviderError,
)
from app.providers.normalized_response import NormalizedLLMResponse


class OpenAIProvider(BaseLLMProvider):
    provider_name = "openai"

    def __init__(
        self,
        api_key: str | None = None,
        default_model: str = "gpt-4o",
        base_url: str = "https://api.openai.com/v1",
        timeout_seconds: float = 60.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.api_key = api_key
        self.default_model = default_model
        self.base_url = base_url.rstrip("/")
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def generate(
        self,
        prompt: str | dict[str, Any],
        model: str | None = None,
        temperature: float = 0.7,
        max_tokens: int = 2048,
    ) -> NormalizedLLMResponse:
        if not self.api_key:
            raise MissingProviderCredential(self.provider_name, "OPENAI_API_KEY")

        selected_model = model or self.default_model
        prompt_str = prompt if isinstance(prompt, str) else json.dumps(prompt, ensure_ascii=False)

        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    f"{self.base_url}/chat/completions",
                    headers={"Authorization": f"Bearer {self.api_key}"},
                    json={
                        "model": selected_model,
                        "messages": [{"role": "user", "content": prompt_str}],
                        "temperature": temperature,
                        "max_tokens": max_tokens,
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        if response.status_code in {401, 403}:
            raise ProviderAuthenticationError(self.provider_name)
        if response.status_code == 429:
            raise TransientProviderError(self.provider_name, "provider_rate_limited")
        if response.status_code >= 500:
            raise TransientProviderError(self.provider_name)
        if not response.is_success:
            raise InvalidProviderResponse(self.provider_name)

        try:
            payload = response.json()
            content = payload["choices"][0]["message"]["content"]
            usage_raw = payload.get("usage", {})
            usage = {
                "input_tokens": usage_raw.get("prompt_tokens", 0),
                "output_tokens": usage_raw.get("completion_tokens", 0),
                "total_tokens": usage_raw.get("total_tokens", 0),
            }
            return NormalizedLLMResponse(
                provider=self.provider_name,
                model=selected_model,
                content=content,
                usage=usage,
                finish_reason=payload["choices"][0].get("finish_reason", "completed"),
                request_id=payload.get("id"),
            )
        except (KeyError, IndexError, TypeError, ValueError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc

    def generate_structured(
        self,
        prompt: str | dict[str, Any],
        schema: dict[str, Any],
        model: str | None = None,
        temperature: float = 0.7,
        max_tokens: int = 2048,
    ) -> NormalizedLLMResponse:
        if not self.api_key:
            raise MissingProviderCredential(self.provider_name, "OPENAI_API_KEY")

        selected_model = model or self.default_model
        prompt_str = prompt if isinstance(prompt, str) else json.dumps(prompt, ensure_ascii=False)

        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    f"{self.base_url}/chat/completions",
                    headers={"Authorization": f"Bearer {self.api_key}"},
                    json={
                        "model": selected_model,
                        "messages": [
                            {
                                "role": "system",
                                "content": "Return valid JSON strictly matching format.",
                            },
                            {"role": "user", "content": prompt_str},
                        ],
                        "temperature": temperature,
                        "max_tokens": max_tokens,
                        "response_format": {"type": "json_object"},
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        if response.status_code in {401, 403}:
            raise ProviderAuthenticationError(self.provider_name)
        if response.status_code == 429:
            raise TransientProviderError(self.provider_name, "provider_rate_limited")
        if response.status_code >= 500:
            raise TransientProviderError(self.provider_name)
        if not response.is_success:
            raise InvalidProviderResponse(self.provider_name)

        try:
            payload = response.json()
            raw_text = payload["choices"][0]["message"]["content"]
            structured = json.loads(raw_text)
            usage_raw = payload.get("usage", {})
            usage = {
                "input_tokens": usage_raw.get("prompt_tokens", 0),
                "output_tokens": usage_raw.get("completion_tokens", 0),
                "total_tokens": usage_raw.get("total_tokens", 0),
            }
            return NormalizedLLMResponse(
                provider=self.provider_name,
                model=selected_model,
                content=raw_text,
                structured_output=structured,
                usage=usage,
                finish_reason=payload["choices"][0].get("finish_reason", "completed"),
                request_id=payload.get("id"),
            )
        except (KeyError, IndexError, TypeError, ValueError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc

    def test_connection(self) -> bool:
        return bool(self.api_key)

    def list_models(self) -> list[str]:
        return ["gpt-4o", "gpt-4o-mini", "gpt-4-turbo", "gpt-3.5-turbo"]

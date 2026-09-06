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


class GeminiProvider(BaseLLMProvider):
    provider_name = "gemini"

    def __init__(
        self,
        api_key: str | None = None,
        default_model: str = "gemini-1.5-flash",
        base_url: str = "https://generativelanguage.googleapis.com/v1beta",
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
            raise MissingProviderCredential(self.provider_name, "GEMINI_API_KEY")

        selected_model = model or self.default_model
        prompt_str = prompt if isinstance(prompt, str) else json.dumps(prompt, ensure_ascii=False)

        url = f"{self.base_url}/models/{selected_model}:generateContent"

        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    url,
                    headers={"x-goog-api-key": self.api_key},
                    json={
                        "contents": [{"parts": [{"text": prompt_str}]}],
                        "generationConfig": {
                            "temperature": temperature,
                            "maxOutputTokens": max_tokens,
                        },
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
            content = payload["candidates"][0]["content"]["parts"][0]["text"]
            usage_meta = payload.get("usageMetadata", {})
            usage = {
                "input_tokens": usage_meta.get("promptTokenCount", 0),
                "output_tokens": usage_meta.get("candidatesTokenCount", 0),
                "total_tokens": usage_meta.get("totalTokenCount", 0),
            }
            return NormalizedLLMResponse(
                provider=self.provider_name,
                model=selected_model,
                content=content,
                usage=usage,
                finish_reason="completed",
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
            raise MissingProviderCredential(self.provider_name, "GEMINI_API_KEY")

        selected_model = model or self.default_model
        prompt_str = prompt if isinstance(prompt, str) else json.dumps(prompt, ensure_ascii=False)
        structured_prompt = f"{prompt_str}\n\nReturn strict JSON matching schema."

        url = f"{self.base_url}/models/{selected_model}:generateContent"

        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    url,
                    headers={"x-goog-api-key": self.api_key},
                    json={
                        "contents": [{"parts": [{"text": structured_prompt}]}],
                        "generationConfig": {
                            "temperature": temperature,
                            "maxOutputTokens": max_tokens,
                            "responseMimeType": "application/json",
                        },
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
            raw_text = payload["candidates"][0]["content"]["parts"][0]["text"]
            structured = json.loads(raw_text)
            usage_meta = payload.get("usageMetadata", {})
            usage = {
                "input_tokens": usage_meta.get("promptTokenCount", 0),
                "output_tokens": usage_meta.get("candidatesTokenCount", 0),
                "total_tokens": usage_meta.get("totalTokenCount", 0),
            }
            return NormalizedLLMResponse(
                provider=self.provider_name,
                model=selected_model,
                content=raw_text,
                structured_output=structured,
                usage=usage,
                finish_reason="completed",
            )
        except (KeyError, IndexError, TypeError, ValueError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc

    def test_connection(self) -> bool:
        return bool(self.api_key)

    def list_models(self) -> list[str]:
        return ["gemini-1.5-flash", "gemini-1.5-pro", "gemini-1.0-pro"]

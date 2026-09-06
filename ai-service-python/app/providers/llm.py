"""Deterministic demo generation and OpenAI Responses API adapter."""

import json
from typing import Any, ClassVar

import httpx
from pydantic import ValidationError

from app.providers.contracts import GeneratedContent
from app.providers.errors import (
    InvalidProviderResponse,
    MissingProviderCredential,
    ProviderConfigurationMissing,
    ProviderAuthenticationError,
    TransientProviderError,
    UnsupportedProvider,
)


class DemoLanguageModelProvider:
    provider_name = "demo"

    def generate(
        self,
        research: dict[str, Any],
        config: dict[str, Any],
        language: str,
    ) -> GeneratedContent:
        topic = research["topic"]
        points = "\n".join(f"- {point}" for point in research["key_points"])
        sources = "\n".join(
            f"- {source['title']} — {source['url']}" for source in research["sources"]
        )
        repeat = {"short": 1, "medium": 2, "long": 3}[config["length"]]
        if language == "ar":
            synthesis = "\n\n".join(
                f"يعرض هذا القسم التجريبي رقم {index} تصورًا {config['style']} حول {topic}، "
                "وهو مخصص لاختبار سير العمل فقط."
                for index in range(1, repeat + 1)
            )
            title = f"ملخص تجريبي: {topic}"
            content = (
                "> وضع العرض التجريبي: المحتوى التالي مبني على مصادر محاكاة وليس بحثًا حقيقيًا.\n\n"
                f"## الخلاصة\n\n{research['summary']}\n\n## النقاط الرئيسية\n\n{points}\n\n"
                f"## الصياغة\n\n{synthesis}\n\n## المراجع التجريبية\n\n{sources}"
            )
        else:
            synthesis = "\n\n".join(
                f"Demo section {index} presents a {config['style']} perspective on {topic}; "
                "it exists solely to exercise the workflow without a paid provider."
                for index in range(1, repeat + 1)
            )
            title = f"Demo brief: {topic}"
            content = (
                "> DEMO MODE: the following content uses simulated sources, not live research.\n\n"
                f"## Summary\n\n{research['summary']}\n\n## Key points\n\n{points}\n\n"
                f"## Synthesis\n\n{synthesis}\n\n## Demo references\n\n{sources}"
            )
        return GeneratedContent(
            provider=self.provider_name,
            title=title,
            content=content,
        )


class OpenAIResponsesProvider:
    provider_name = "openai"
    output_schema: ClassVar[dict[str, Any]] = {
        "type": "object",
        "properties": {
            "title": {"type": "string"},
            "content": {"type": "string"},
        },
        "required": ["title", "content"],
        "additionalProperties": False,
    }

    def __init__(
        self,
        api_key: str | None,
        model: str,
        base_url: str = "https://api.openai.com/v1",
        timeout_seconds: float = 60.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.api_key = api_key
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def generate(
        self,
        research: dict[str, Any],
        config: dict[str, Any],
        language: str,
    ) -> GeneratedContent:
        if not self.api_key:
            raise MissingProviderCredential(self.provider_name, "LLM_API_KEY")
        prompt = {
            "research": research,
            "requirements": {
                "language": language,
                "style": config["style"],
                "length": config["length"],
                "format": config["format"],
                "rule": "Use only the supplied sources; do not invent citations or URLs.",
            },
        }
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    f"{self.base_url}/responses",
                    headers={"Authorization": f"Bearer {self.api_key}"},
                    json={
                        "model": self.model,
                        "store": False,
                        "input": [
                            {
                                "role": "system",
                                "content": (
                                    "Write accurate content from the supplied research. "
                                    "Return the required structured output."
                                ),
                            },
                            {
                                "role": "user",
                                "content": json.dumps(prompt, ensure_ascii=False),
                            },
                        ],
                        "text": {
                            "format": {
                                "type": "json_schema",
                                "name": "nasaq_writer_output",
                                "schema": self.output_schema,
                                "strict": True,
                            }
                        },
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        if response.status_code in {401, 403}:
            raise ProviderAuthenticationError(self.provider_name)
        if response.status_code == 429 or response.status_code >= 500:
            raise TransientProviderError(self.provider_name)
        if not response.is_success:
            raise InvalidProviderResponse(self.provider_name)

        try:
            payload = response.json()
            text = next(
                content["text"]
                for output in payload["output"]
                if output.get("type") == "message"
                for content in output.get("content", [])
                if content.get("type") == "output_text"
            )
            structured = json.loads(text)
            return GeneratedContent(
                provider=self.provider_name,
                model=self.model,
                title=structured["title"],
                content=structured["content"],
            )
        except (KeyError, StopIteration, TypeError, ValueError, ValidationError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc


class UnavailableLanguageModelProvider:
    def __init__(self, provider_name: str) -> None:
        self.provider_name = provider_name or "unconfigured"

    def generate(
        self,
        research: dict[str, Any],
        config: dict[str, Any],
        language: str,
    ) -> GeneratedContent:
        raise ProviderConfigurationMissing(self.provider_name)

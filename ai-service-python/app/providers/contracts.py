"""Strict provider-neutral data contracts."""

from typing import Any, Protocol

from pydantic import Field

from app.domain.models import ContractModel


class SearchSource(ContractModel):
    title: str = Field(min_length=1, max_length=1000)
    url: str
    snippet: str = Field(min_length=1, max_length=12000)
    score: float | None = None
    published_at: str | None = None
    is_demo: bool = False


class SearchResponse(ContractModel):
    provider: str
    sources: list[SearchSource]
    request_id: str | None = None


class GeneratedContent(ContractModel):
    provider: str
    model: str | None = None
    title: str = Field(min_length=1, max_length=1000)
    content: str = Field(min_length=1, max_length=100000)


class SynthesizedSpeech(ContractModel):
    provider: str
    model: str | None = None
    audio: bytes = Field(min_length=1)
    audio_format: str = "mp3"


class PublicationInput(ContractModel):
    title: str = Field(min_length=1, max_length=1000)
    file_path: str = Field(min_length=1, max_length=4096)
    file_name: str = Field(min_length=1, max_length=255)
    mime_type: str = Field(min_length=1, max_length=160)
    idempotency_key: str = Field(min_length=1, max_length=300)
    description: str | None = Field(default=None, max_length=5000)
    privacy_status: str = "private"


class PublicationResult(ContractModel):
    provider: str
    resource_id: str = Field(min_length=1, max_length=1000)
    url: str = Field(min_length=1, max_length=4096)
    mime_type: str
    simulated: bool = False


class EmailMessageInput(ContractModel):
    recipients: list[str] = Field(min_length=1, max_length=50)
    subject: str = Field(min_length=1, max_length=998)
    text_body: str = Field(min_length=1, max_length=200000)
    idempotency_key: str = Field(min_length=1, max_length=300)


class EmailDeliveryResult(ContractModel):
    provider: str
    message_id: str = Field(min_length=1, max_length=1000)
    recipients: list[str]
    simulated: bool = False


class SearchProvider(Protocol):
    provider_name: str

    def search(
        self,
        query: str,
        max_results: int,
        search_depth: str,
        language: str,
    ) -> SearchResponse: ...


class LanguageModelProvider(Protocol):
    provider_name: str

    def generate(
        self,
        research: dict[str, Any],
        config: dict[str, Any],
        language: str,
    ) -> GeneratedContent: ...


class SpeechProvider(Protocol):
    provider_name: str

    def synthesize(self, text: str, voice: str) -> SynthesizedSpeech: ...


class PublishingProvider(Protocol):
    provider_name: str

    def publish(self, publication: PublicationInput, user_id: str | None) -> PublicationResult: ...


class EmailProvider(Protocol):
    provider_name: str

    def send(self, message: EmailMessageInput, user_id: str | None) -> EmailDeliveryResult: ...

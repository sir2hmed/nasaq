"""Demo-safe and OpenAI-compatible text-to-speech providers."""

import httpx

from app.providers.contracts import SynthesizedSpeech
from app.providers.errors import (
    InvalidProviderResponse,
    MissingProviderCredential,
    ProviderAuthenticationError,
    TransientProviderError,
    UnsupportedProvider,
)


class DemoSpeechProvider:
    """Marker provider; the Video Agent documents and renders a silent demo fallback."""

    provider_name = "demo"

    def synthesize(self, text: str, voice: str) -> SynthesizedSpeech:
        raise UnsupportedProvider(self.provider_name, "speech synthesis")


class OpenAISpeechProvider:
    provider_name = "openai"

    def __init__(
        self,
        api_key: str | None,
        model: str = "tts-1",
        base_url: str = "https://api.openai.com/v1",
        timeout_seconds: float = 60.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.api_key = api_key
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def synthesize(self, text: str, voice: str) -> SynthesizedSpeech:
        if not self.api_key:
            raise MissingProviderCredential(self.provider_name, "TTS_API_KEY")
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    f"{self.base_url}/audio/speech",
                    headers={"Authorization": f"Bearer {self.api_key}"},
                    json={
                        "model": self.model,
                        "input": text[:4096],
                        "voice": voice,
                        "response_format": "mp3",
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        if response.status_code in {401, 403}:
            raise ProviderAuthenticationError(self.provider_name)
        if response.status_code == 429 or response.status_code >= 500:
            raise TransientProviderError(self.provider_name)
        if not response.is_success or not response.content:
            raise InvalidProviderResponse(self.provider_name)

        return SynthesizedSpeech(
            provider=self.provider_name,
            model=self.model,
            audio=response.content,
        )


class UnavailableSpeechProvider:
    def __init__(self, provider_name: str) -> None:
        self.provider_name = provider_name or "unconfigured"

    def synthesize(self, text: str, voice: str) -> SynthesizedSpeech:
        raise UnsupportedProvider(self.provider_name, "speech synthesis")

"""Environment-driven provider selection without exposing credentials to workflows."""

from dataclasses import dataclass

from app.config import Settings
from app.providers.contracts import (
    EmailProvider,
    LanguageModelProvider,
    PublishingProvider,
    SearchProvider,
    SpeechProvider,
)
from app.providers.email import DemoEmailProvider, SmtpEmailProvider
from app.providers.llm import (
    DemoLanguageModelProvider,
    OpenAIResponsesProvider,
    UnavailableLanguageModelProvider,
)
from app.providers.publishing import (
    DemoPublishingProvider,
    GoogleDrivePublishingProvider,
    YouTubePublishingProvider,
)
from app.providers.resolved_llm import ResolvedLanguageModelProvider
from app.providers.fallback_llm import FallbackLanguageModelProvider
from app.providers.search import DemoSearchProvider, TavilySearchProvider, UnavailableSearchProvider
from app.providers.speech import DemoSpeechProvider, OpenAISpeechProvider, UnavailableSpeechProvider
from app.services.integrations import IntegrationCredentialClient
from app.services.provider_resolution import ProviderResolution


@dataclass(frozen=True, slots=True)
class ProviderBundle:
    search: SearchProvider
    language_model: LanguageModelProvider
    speech: SpeechProvider
    drive: PublishingProvider
    youtube: PublishingProvider
    email: EmailProvider


def build_provider_bundle(
    settings: Settings,
    provider_mode: str,
    resolution: ProviderResolution | None = None,
) -> ProviderBundle:
    if provider_mode == "demo":
        return ProviderBundle(
            DemoSearchProvider(),
            DemoLanguageModelProvider(),
            DemoSpeechProvider(),
            DemoPublishingProvider("google_drive"),
            DemoPublishingProvider("youtube"),
            DemoEmailProvider(),
        )

    search: SearchProvider
    if settings.search_provider == "tavily":
        search = TavilySearchProvider(
            settings.search_api_key,
            settings.tavily_base_url,
            settings.provider_timeout_seconds,
        )
    else:
        search = UnavailableSearchProvider(settings.search_provider)

    language_model: LanguageModelProvider
    if resolution and resolution.candidates:
        language_model = FallbackLanguageModelProvider(list(resolution.candidates), resolution.timeout_seconds or settings.provider_timeout_seconds)
    elif (
        resolution
        and resolution.mode == "real"
        and resolution.provider in {"openai", "gemini"}
        and resolution.api_key
        and resolution.model
    ):
        language_model = ResolvedLanguageModelProvider(
            resolution.provider,
            resolution.api_key,
            resolution.model,
            resolution.base_url,
            resolution.timeout_seconds or settings.provider_timeout_seconds,
        )
    elif resolution:
        language_model = UnavailableLanguageModelProvider(resolution.state)
    elif settings.allow_environment_provider_fallback and settings.llm_provider == "openai":
        language_model = OpenAIResponsesProvider(
            settings.llm_api_key,
            settings.llm_model,
            settings.openai_base_url,
            settings.provider_timeout_seconds,
        )
    else:
        language_model = UnavailableLanguageModelProvider("unconfigured")
    speech: SpeechProvider
    if settings.tts_provider == "openai":
        speech = OpenAISpeechProvider(
            settings.tts_api_key,
            settings.tts_model,
            settings.openai_base_url,
            settings.provider_timeout_seconds,
        )
    else:
        speech = UnavailableSpeechProvider(settings.tts_provider)
    credentials = IntegrationCredentialClient(
        settings.laravel_callback_base_url,
        settings.laravel_callback_token,
        settings.integration_timeout_seconds,
    )
    return ProviderBundle(
        search,
        language_model,
        speech,
        GoogleDrivePublishingProvider(
            credentials,
            settings.google_drive_upload_url,
            settings.provider_timeout_seconds,
        ),
        YouTubePublishingProvider(
            credentials,
            settings.youtube_upload_url,
            max(settings.provider_timeout_seconds, 120.0),
        ),
        SmtpEmailProvider(credentials, settings.provider_timeout_seconds),
    )

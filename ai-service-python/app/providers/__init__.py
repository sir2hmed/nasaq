"""Search and language-model provider contracts and implementations."""

from app.providers.contracts import (
    GeneratedContent,
    LanguageModelProvider,
    SearchProvider,
    SearchResponse,
    SearchSource,
    SpeechProvider,
    SynthesizedSpeech,
)
from app.providers.factory import ProviderBundle, build_provider_bundle
from app.providers.llm import DemoLanguageModelProvider, OpenAIResponsesProvider
from app.providers.search import DemoSearchProvider, TavilySearchProvider
from app.providers.speech import DemoSpeechProvider, OpenAISpeechProvider

__all__ = [
    "DemoLanguageModelProvider",
    "DemoSearchProvider",
    "DemoSpeechProvider",
    "GeneratedContent",
    "LanguageModelProvider",
    "OpenAIResponsesProvider",
    "OpenAISpeechProvider",
    "ProviderBundle",
    "SearchProvider",
    "SearchResponse",
    "SearchSource",
    "SpeechProvider",
    "SynthesizedSpeech",
    "TavilySearchProvider",
    "build_provider_bundle",
]

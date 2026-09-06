import pytest

from app.providers.base_llm import BaseLLMProvider
from app.providers.gemini_provider import GeminiProvider
from app.providers.llm import DemoLanguageModelProvider
from app.providers.normalized_response import NormalizedLLMResponse
from app.providers.openai_provider import OpenAIProvider
from app.providers.provider_errors import MissingProviderCredential
from app.providers.provider_factory import ProviderFactory


def test_provider_factory_returns_demo_provider():
    provider = ProviderFactory.create_llm_provider("demo")
    assert isinstance(provider, DemoLanguageModelProvider)
    assert provider.provider_name == "demo"


def test_provider_factory_returns_openai_provider():
    provider = ProviderFactory.create_llm_provider(
        "openai", api_key="sk-test", default_model="gpt-4o"
    )
    assert isinstance(provider, BaseLLMProvider)
    assert isinstance(provider, OpenAIProvider)
    assert provider.provider_name == "openai"
    assert provider.default_model == "gpt-4o"


def test_provider_factory_returns_gemini_provider():
    provider = ProviderFactory.create_llm_provider(
        "gemini", api_key="gemini-test-key", default_model="gemini-1.5-flash"
    )
    assert isinstance(provider, BaseLLMProvider)
    assert isinstance(provider, GeminiProvider)
    assert provider.provider_name == "gemini"
    assert provider.default_model == "gemini-1.5-flash"


def test_openai_provider_raises_missing_credentials():
    provider = OpenAIProvider(api_key=None)
    with pytest.raises(MissingProviderCredential):
        provider.generate("Test prompt")


def test_gemini_provider_raises_missing_credentials():
    provider = GeminiProvider(api_key=None)
    with pytest.raises(MissingProviderCredential):
        provider.generate("Test prompt")


def test_normalized_response_structure():
    resp = NormalizedLLMResponse(
        provider="gemini",
        model="gemini-1.5-flash",
        content="Generated text content",
        structured_output={"title": "Demo Title"},
        usage={"input_tokens": 10, "output_tokens": 20, "total_tokens": 30},
    )
    assert resp.provider == "gemini"
    assert resp.content == "Generated text content"
    assert resp.usage["total_tokens"] == 30
    assert resp.structured_output["title"] == "Demo Title"

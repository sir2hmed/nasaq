from app.providers.errors import ProviderConfigurationMissing
from app.providers.llm import UnavailableLanguageModelProvider


def test_unconfigured_language_model_explains_how_to_restore_execution() -> None:
    provider = UnavailableLanguageModelProvider("unconfigured")

    try:
        provider.generate({}, {}, "en")
    except ProviderConfigurationMissing as error:
        assert error.code == "provider_configuration_missing"
        assert "platform default" in error.safe_message
    else:
        raise AssertionError("An unconfigured provider must not execute a real run.")

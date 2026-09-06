"""Safe provider errors with explicit retry classification."""


class ProviderError(Exception):
    def __init__(
        self,
        code: str,
        message: str,
        *,
        provider: str,
        retryable: bool,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.safe_message = message
        self.provider = provider
        self.retryable = retryable


class MissingProviderCredential(ProviderError):
    def __init__(self, provider: str, variable: str) -> None:
        super().__init__(
            "provider_credentials_missing",
            f"{provider.title()} credentials are not configured. Set {variable} server-side.",
            provider=provider,
            retryable=False,
        )


class UnsupportedProvider(ProviderError):
    def __init__(self, provider: str, kind: str) -> None:
        super().__init__(
            "provider_not_supported",
            f"The configured {kind} provider is not supported.",
            provider=provider or "unconfigured",
            retryable=False,
        )


class ProviderConfigurationMissing(ProviderError):
    """Raised when Laravel cannot resolve an enabled platform LLM provider."""

    def __init__(self, state: str = "unconfigured") -> None:
        super().__init__(
            "provider_configuration_missing",
            (
                "No enabled AI provider is configured for this run. "
                "An administrator must configure, test, enable, and select an "
                "OpenAI or Gemini provider as the platform default."
            ),
            provider=state or "unconfigured",
            retryable=False,
        )


class ProviderAuthenticationError(ProviderError):
    def __init__(self, provider: str) -> None:
        super().__init__(
            "provider_authentication_failed",
            f"{provider.title()} rejected its server-side credentials.",
            provider=provider,
            retryable=False,
        )


class TransientProviderError(ProviderError):
    def __init__(self, provider: str, code: str = "provider_temporarily_unavailable") -> None:
        super().__init__(
            code,
            f"{provider.title()} is temporarily unavailable.",
            provider=provider,
            retryable=True,
        )


class InvalidProviderResponse(ProviderError):
    def __init__(self, provider: str) -> None:
        super().__init__(
            "provider_response_invalid",
            f"{provider.title()} returned an invalid response.",
            provider=provider,
            retryable=False,
        )


class IntegrationNotConnected(ProviderError):
    def __init__(self, provider: str) -> None:
        super().__init__(
            "integration_not_connected",
            (
                f"Connect {provider.replace('_', ' ').title()} in Integration Settings "
                "before running this node."
            ),
            provider=provider,
            retryable=False,
        )


class InvalidPublicationInput(ProviderError):
    def __init__(self, provider: str, message: str) -> None:
        super().__init__(
            "publication_input_invalid",
            message,
            provider=provider,
            retryable=False,
        )

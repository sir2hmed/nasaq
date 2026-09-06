"""Retrieve encrypted-at-rest integration credentials over the internal trust channel."""

from typing import Any

import httpx

from app.providers.errors import (
    IntegrationNotConnected,
    InvalidProviderResponse,
    TransientProviderError,
)


class IntegrationCredentialClient:
    def __init__(
        self,
        base_url: str,
        service_token: str,
        timeout_seconds: float = 10.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.service_token = service_token
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def get(self, user_id: str | None, provider: str) -> dict[str, Any]:
        if not user_id:
            raise IntegrationNotConnected(provider)
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.get(
                    f"{self.base_url}/users/{user_id}/integrations/{provider}",
                    headers={
                        "X-Nasaq-Service-Token": self.service_token,
                        "Accept": "application/json",
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError("integration-settings") from exc

        if response.status_code == 404:
            raise IntegrationNotConnected(provider)
        if response.status_code >= 500:
            raise TransientProviderError("integration-settings")
        if not response.is_success:
            raise InvalidProviderResponse("integration-settings")
        try:
            credentials = response.json()["data"]["credentials"]
            if not isinstance(credentials, dict):
                raise TypeError("credentials must be an object")
            return credentials
        except (KeyError, TypeError, ValueError) as exc:
            raise InvalidProviderResponse("integration-settings") from exc

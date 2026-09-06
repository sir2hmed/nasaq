"""Demo, Google Drive, and YouTube publication adapters."""

import hashlib
import json
from pathlib import Path

import httpx

from app.providers.contracts import PublicationInput, PublicationResult
from app.providers.errors import (
    InvalidProviderResponse,
    InvalidPublicationInput,
    ProviderAuthenticationError,
    TransientProviderError,
)
from app.services.integrations import IntegrationCredentialClient


class DemoPublishingProvider:
    def __init__(self, provider_name: str) -> None:
        self.provider_name = provider_name

    def publish(self, publication: PublicationInput, user_id: str | None) -> PublicationResult:
        token = hashlib.sha256(publication.idempotency_key.encode()).hexdigest()[:18]
        host = (
            "drive.example.invalid"
            if self.provider_name == "google_drive"
            else "youtube.example.invalid"
        )
        return PublicationResult(
            provider=f"demo-{self.provider_name}",
            resource_id=f"demo-{token}",
            url=f"https://{host}/nasaq/{token}",
            mime_type=publication.mime_type,
            simulated=True,
        )


class GoogleDrivePublishingProvider:
    provider_name = "google_drive"

    def __init__(
        self,
        credentials: IntegrationCredentialClient,
        upload_url: str = "https://www.googleapis.com/upload/drive/v3/files",
        timeout_seconds: float = 60.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.credentials = credentials
        self.upload_url = upload_url
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def publish(self, publication: PublicationInput, user_id: str | None) -> PublicationResult:
        path = _validated_file(publication, self.provider_name)
        credentials = self.credentials.get(user_id, self.provider_name)
        access_token = credentials.get("access_token")
        if not isinstance(access_token, str) or not access_token:
            raise ProviderAuthenticationError(self.provider_name)
        metadata: dict[str, object] = {
            "name": publication.file_name,
            "appProperties": {"nasaqIdempotencyKey": publication.idempotency_key},
        }
        folder_id = credentials.get("folder_id")
        if isinstance(folder_id, str) and folder_id:
            metadata["parents"] = [folder_id]
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    self.upload_url,
                    params={"uploadType": "multipart", "fields": "id,name,mimeType,webViewLink"},
                    headers={"Authorization": f"Bearer {access_token}"},
                    files={
                        "metadata": (None, json.dumps(metadata), "application/json; charset=UTF-8"),
                        "file": (publication.file_name, path.read_bytes(), publication.mime_type),
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc
        payload = _validated_response(response, self.provider_name)
        resource_id = payload.get("id")
        if not isinstance(resource_id, str) or not resource_id:
            raise InvalidProviderResponse(self.provider_name)
        url = payload.get("webViewLink")
        if not isinstance(url, str) or not url.startswith("https://"):
            url = f"https://drive.google.com/file/d/{resource_id}/view"
        return PublicationResult(
            provider=self.provider_name,
            resource_id=resource_id,
            url=url,
            mime_type=str(payload.get("mimeType") or publication.mime_type),
        )


class YouTubePublishingProvider:
    provider_name = "youtube"

    def __init__(
        self,
        credentials: IntegrationCredentialClient,
        upload_url: str = "https://www.googleapis.com/upload/youtube/v3/videos",
        timeout_seconds: float = 120.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.credentials = credentials
        self.upload_url = upload_url
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def publish(self, publication: PublicationInput, user_id: str | None) -> PublicationResult:
        path = _validated_file(publication, self.provider_name)
        if publication.mime_type != "video/mp4":
            raise InvalidPublicationInput(
                self.provider_name, "YouTube publication requires a video/mp4 artifact."
            )
        credentials = self.credentials.get(user_id, self.provider_name)
        access_token = credentials.get("access_token")
        if not isinstance(access_token, str) or not access_token:
            raise ProviderAuthenticationError(self.provider_name)
        privacy = credentials.get("privacy_status", publication.privacy_status)
        if privacy not in {"private", "unlisted", "public"}:
            privacy = "private"
        metadata = {
            "snippet": {
                "title": publication.title[:100],
                "description": (publication.description or "Published by Nasaq AI")[:5000],
            },
            "status": {"privacyStatus": privacy},
        }
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    self.upload_url,
                    params={
                        "uploadType": "multipart",
                        "part": "snippet,status",
                        "notifySubscribers": "false",
                    },
                    headers={"Authorization": f"Bearer {access_token}"},
                    files={
                        "metadata": (None, json.dumps(metadata), "application/json; charset=UTF-8"),
                        "file": (publication.file_name, path.read_bytes(), publication.mime_type),
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc
        payload = _validated_response(response, self.provider_name)
        resource_id = payload.get("id")
        if not isinstance(resource_id, str) or not resource_id:
            raise InvalidProviderResponse(self.provider_name)
        return PublicationResult(
            provider=self.provider_name,
            resource_id=resource_id,
            url=f"https://www.youtube.com/watch?v={resource_id}",
            mime_type=publication.mime_type,
        )


def _validated_file(publication: PublicationInput, provider: str) -> Path:
    path = Path(publication.file_path)
    if not path.is_file() or path.stat().st_size <= 0:
        raise InvalidPublicationInput(provider, "The publication artifact is missing or empty.")
    return path


def _validated_response(response: httpx.Response, provider: str) -> dict[str, object]:
    if response.status_code in {401, 403}:
        raise ProviderAuthenticationError(provider)
    if response.status_code == 429 or response.status_code >= 500:
        raise TransientProviderError(provider)
    if not response.is_success:
        raise InvalidProviderResponse(provider)
    try:
        payload = response.json()
        if not isinstance(payload, dict):
            raise TypeError("provider response must be an object")
        return payload
    except (ValueError, TypeError) as exc:
        raise InvalidProviderResponse(provider) from exc

from pathlib import Path

import httpx
import pytest

from app.agents.publisher import PublisherAgent
from app.domain import ExecutionContext, WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.providers.contracts import (
    EmailMessageInput,
    PublicationInput,
    PublicationResult,
)
from app.providers.email import SmtpEmailProvider
from app.providers.errors import IntegrationNotConnected, InvalidPublicationInput
from app.providers.publishing import (
    DemoPublishingProvider,
    GoogleDrivePublishingProvider,
    YouTubePublishingProvider,
)
from app.services.idempotency import InMemoryIdempotencyStore
from app.services.integrations import IntegrationCredentialClient


class StubCredentials:
    def __init__(self, values: dict) -> None:
        self.values = values
        self.requests: list[tuple[str | None, str]] = []

    def get(self, user_id: str | None, provider: str) -> dict:
        self.requests.append((user_id, provider))
        return self.values


class CountingPublisher:
    provider_name = "counting-drive"

    def __init__(self) -> None:
        self.calls = 0

    def publish(self, publication: PublicationInput, user_id: str | None) -> PublicationResult:
        self.calls += 1
        return PublicationResult(
            provider=self.provider_name,
            resource_id="one-resource",
            url="https://drive.example.test/one-resource",
            mime_type=publication.mime_type,
        )


def integration_graph() -> WorkflowGraph:
    return WorkflowGraph.model_validate(
        {
            "version": 1,
            "name": "Phase 11 integration demo",
            "nodes": [
                {
                    "id": "researcher_01",
                    "type": "researcher",
                    "config": {
                        "topic": "Bilingual workflow automation",
                        "source_count": 2,
                        "search_depth": "basic",
                        "language": "en",
                    },
                },
                {
                    "id": "writer_01",
                    "type": "writer",
                    "config": {
                        "style": "professional",
                        "length": "short",
                        "format": "article",
                        "language": "same_as_input",
                    },
                },
                {
                    "id": "publisher_01",
                    "type": "publisher",
                    "config": {"destination": "google_drive"},
                },
                {
                    "id": "email_01",
                    "type": "email",
                    "config": {
                        "recipients": ["reviewer@example.test"],
                        "subject": "Nasaq demo result",
                        "body_template": "Your generated result:\n{links}",
                    },
                },
            ],
            "edges": [
                {"id": "edge_01", "source": "researcher_01", "target": "writer_01"},
                {"id": "edge_02", "source": "writer_01", "target": "publisher_01"},
                {"id": "edge_03", "source": "publisher_01", "target": "email_01"},
            ],
        }
    )


def test_full_demo_integration_path_needs_no_credentials_and_saves_preview(tmp_path: Path) -> None:
    result = WorkflowOrchestrator(AgentRegistry(tmp_path)).execute(
        integration_graph(),
        run_id="phase-11-demo",
        user_id="42",
        provider_mode="demo",
    )

    assert result.status == "success"
    publication = result.outputs["publisher_01"]
    delivery = result.outputs["email_01"]
    assert publication.data["simulated"] is True
    assert publication.data["url"].startswith("https://drive.example.invalid/")
    assert delivery.data["simulated"] is True
    assert delivery.data["recipients"] == ["reviewer@example.test"]
    assert delivery.artifacts[0].mime_type == "message/rfc822"
    assert Path(delivery.artifacts[0].path).read_bytes().startswith(b"From:")


def test_email_rejects_an_oversized_body_template_before_delivery(tmp_path: Path) -> None:
    email = AgentRegistry(tmp_path).get("email")
    assert email is not None

    errors = email.validate_config(
        {
            "recipients": ["reviewer@example.test"],
            "subject": "Nasaq result",
            "body_template": "x" * 20_001,
        },
        "email_01",
    )

    assert len(errors) == 1
    assert errors[0].code == "invalid_config"
    assert errors[0].details["field"] == "body_template"


def test_publisher_reuses_success_and_prevents_duplicate_side_effect(tmp_path: Path) -> None:
    provider = CountingPublisher()
    store = InMemoryIdempotencyStore()
    agent = PublisherAgent(
        tmp_path,
        provider,
        DemoPublishingProvider("youtube"),
        store,
    )
    context = ExecutionContext(
        workflow_run_id="same-run",
        user_id="7",
        correlation_id="same-correlation",
        provider_mode="real",
    )
    input_data = {
        "writer_01": {
            "title": "Idempotency",
            "content": "Upload this only once.",
            "language": "en",
            "format": "article",
        }
    }

    first = agent.run(input_data, {"destination": "google_drive"}, context, "publisher_01")
    second = agent.run(input_data, {"destination": "google_drive"}, context, "publisher_01")

    assert first.status == second.status == "success"
    assert first.data["idempotency_reused"] is False
    assert second.data["idempotency_reused"] is True
    assert provider.calls == 1


def test_drive_adapter_uses_multipart_upload_and_stores_idempotency_property(
    tmp_path: Path,
) -> None:
    file_path = tmp_path / "article.md"
    file_path.write_text("# Real artifact", encoding="utf-8")
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(
            200,
            json={
                "id": "drive-file-123",
                "name": "article.md",
                "mimeType": "text/markdown",
                "webViewLink": "https://drive.google.com/file/d/drive-file-123/view",
            },
        )

    credentials = StubCredentials({"access_token": "drive-access", "folder_id": "folder-1"})
    result = GoogleDrivePublishingProvider(
        credentials,
        transport=httpx.MockTransport(handler),
    ).publish(
        PublicationInput(
            title="Real article",
            file_path=str(file_path),
            file_name="article.md",
            mime_type="text/markdown; charset=utf-8",
            idempotency_key="run-1:publisher-1",
        ),
        "77",
    )

    request = requests[0]
    body = request.content.decode("utf-8")
    assert request.url.params["uploadType"] == "multipart"
    assert request.url.params["fields"] == "id,name,mimeType,webViewLink"
    assert request.headers["authorization"] == "Bearer drive-access"
    assert '"nasaqIdempotencyKey": "run-1:publisher-1"' in body
    assert '"parents": ["folder-1"]' in body
    assert "# Real artifact" in body
    assert credentials.requests == [("77", "google_drive")]
    assert result.resource_id == "drive-file-123"
    assert result.url.endswith("/drive-file-123/view")


def test_youtube_adapter_accepts_only_mp4_and_uses_private_default(tmp_path: Path) -> None:
    video = tmp_path / "video.mp4"
    video.write_bytes(b"small-mp4-fixture")
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json={"id": "youtube-123"})

    credentials = StubCredentials({"access_token": "youtube-access"})
    provider = YouTubePublishingProvider(
        credentials,
        transport=httpx.MockTransport(handler),
    )
    publication = PublicationInput(
        title="Generated video",
        file_path=str(video),
        file_name="video.mp4",
        mime_type="video/mp4",
        idempotency_key="run-1:youtube-1",
    )
    result = provider.publish(publication, "88")

    request = requests[0]
    body = request.content.decode("utf-8")
    assert request.url.params["part"] == "snippet,status"
    assert request.url.params["notifySubscribers"] == "false"
    assert '"privacyStatus": "private"' in body
    assert result.url == "https://www.youtube.com/watch?v=youtube-123"

    with pytest.raises(InvalidPublicationInput):
        provider.publish(publication.model_copy(update={"mime_type": "application/pdf"}), "88")


def test_credential_client_uses_internal_token_and_maps_disconnected_state() -> None:
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        if request.url.path.endswith("/youtube"):
            return httpx.Response(404, json={"message": "not connected"})
        return httpx.Response(
            200,
            json={"data": {"credentials": {"access_token": "decrypted-at-boundary"}}},
        )

    client = IntegrationCredentialClient(
        "http://laravel.test/api/internal",
        "internal-worker-token",
        transport=httpx.MockTransport(handler),
    )
    assert client.get("42", "google_drive") == {"access_token": "decrypted-at-boundary"}
    assert requests[0].headers["x-nasaq-service-token"] == "internal-worker-token"
    assert requests[0].url.path == "/api/internal/users/42/integrations/google_drive"
    with pytest.raises(IntegrationNotConnected):
        client.get("42", "youtube")


def test_smtp_adapter_authenticates_and_sends_without_logging_credentials() -> None:
    events: list[tuple] = []

    class FakeSmtp:
        def __init__(self, host: str, port: int, timeout: float) -> None:
            events.append(("connect", host, port, timeout))

        def __enter__(self):
            return self

        def __exit__(self, *args) -> None:
            return None

        def starttls(self, context) -> None:
            events.append(("tls",))

        def login(self, username: str, password: str) -> None:
            events.append(("login", username, password))

        def send_message(self, message) -> dict:
            events.append(("send", message["To"], message["X-Nasaq-Idempotency-Key"]))
            return {}

    credentials = StubCredentials(
        {
            "host": "smtp.example.test",
            "port": 587,
            "username": "mailer",
            "password": "secret-password",
            "from_email": "noreply@example.test",
            "from_name": "Nasaq",
            "encryption": "tls",
        }
    )
    result = SmtpEmailProvider(credentials, smtp_factory=FakeSmtp).send(
        EmailMessageInput(
            recipients=["person@example.test"],
            subject="Generated result",
            text_body="https://drive.example.test/result",
            idempotency_key="run-1:email-1",
        ),
        "99",
    )

    assert events[0][:3] == ("connect", "smtp.example.test", 587)
    assert ("tls",) in events
    assert ("login", "mailer", "secret-password") in events
    assert ("send", "person@example.test", "run-1:email-1") in events
    assert result.simulated is False
    assert result.recipients == ["person@example.test"]

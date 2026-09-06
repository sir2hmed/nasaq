"""Explicit registry of supported agent implementations."""

from pathlib import Path

from app.agents import (
    ApprovalAgent,
    BaseAgent,
    EmailAgent,
    ExportAgent,
    PublisherAgent,
    ResearcherAgent,
    VideoAgent,
    WriterAgent,
)
from app.providers.contracts import (
    EmailProvider,
    LanguageModelProvider,
    PublishingProvider,
    SearchProvider,
    SpeechProvider,
)
from app.providers.email import DemoEmailProvider
from app.providers.publishing import DemoPublishingProvider
from app.services.idempotency import IdempotencyStore, InMemoryIdempotencyStore


class AgentRegistry:
    def __init__(
        self,
        artifact_root: Path,
        search_provider: SearchProvider | None = None,
        language_model_provider: LanguageModelProvider | None = None,
        speech_provider: SpeechProvider | None = None,
        default_tts_voice: str = "alloy",
        drive_provider: PublishingProvider | None = None,
        youtube_provider: PublishingProvider | None = None,
        email_provider: EmailProvider | None = None,
        idempotency_store: IdempotencyStore | None = None,
    ) -> None:
        side_effects = idempotency_store or InMemoryIdempotencyStore()
        drive = drive_provider or DemoPublishingProvider("google_drive")
        youtube = youtube_provider or DemoPublishingProvider("youtube")
        email = email_provider or DemoEmailProvider()
        self._agents: dict[str, BaseAgent] = {
            "researcher": ResearcherAgent(search_provider),
            "writer": WriterAgent(language_model_provider),
            "approval": ApprovalAgent(),
            "export": ExportAgent(artifact_root),
            "video": VideoAgent(
                artifact_root,
                speech_provider,
                default_voice=default_tts_voice,
            ),
            "publisher": PublisherAgent(
                artifact_root,
                drive,
                youtube,
                side_effects,
            ),
            "email": EmailAgent(artifact_root, email, side_effects),
        }

    @property
    def agent_types(self) -> frozenset[str]:
        return frozenset(self._agents)

    def get(self, agent_type: str) -> BaseAgent | None:
        return self._agents.get(agent_type)

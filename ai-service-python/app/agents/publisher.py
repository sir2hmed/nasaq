"""Publish generated artifacts to Drive or YouTube with run/node idempotency."""

import hashlib
import re
from pathlib import Path
from typing import Any

from app.agents.base import AgentExecution, BaseAgent, validation_error
from app.domain import ExecutionContext, ExecutionError
from app.providers.contracts import PublicationInput, PublishingProvider
from app.providers.errors import InvalidPublicationInput
from app.services.idempotency import IdempotencyStore

ALLOWED_MIME_TYPES = {
    "text/markdown; charset=utf-8",
    "application/pdf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "video/mp4",
}


class PublisherAgent(BaseAgent):
    agent_type = "publisher"
    input_schema = "generated file, video, or Writer structured text"
    output_schema = "external resource ID and share/watch URL"

    def __init__(
        self,
        artifact_root: Path,
        drive_provider: PublishingProvider,
        youtube_provider: PublishingProvider,
        idempotency_store: IdempotencyStore,
    ) -> None:
        self.artifact_root = artifact_root.resolve()
        self.providers = {"google_drive": drive_provider, "youtube": youtube_provider}
        self.idempotency_store = idempotency_store

    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        destination = config.get("destination")
        if destination not in self.providers:
            return [
                validation_error(
                    "invalid_config",
                    "Publisher destination must be google_drive or youtube.",
                    node_id,
                    self.agent_type,
                    field="destination",
                )
            ]
        if config.get("privacy_status", "private") not in {"private", "unlisted", "public"}:
            return [
                validation_error(
                    "invalid_config",
                    "YouTube privacy must be private, unlisted, or public.",
                    node_id,
                    self.agent_type,
                    field="privacy_status",
                )
            ]
        return []

    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        if self._artifact(input_data) is None and self._written_content(input_data) is None:
            return [
                validation_error(
                    "invalid_input",
                    "Publisher requires a generated file, MP4 video, or Writer output.",
                    node_id,
                    self.agent_type,
                )
            ]
        return []

    def execute(
        self,
        input_data: dict[str, Any],
        config: dict[str, Any],
        execution_context: ExecutionContext,
        node_id: str,
    ) -> AgentExecution:
        destination = str(config["destination"])
        artifact = self._artifact(input_data)
        written = self._written_content(input_data)
        if artifact is None:
            assert written is not None
            artifact = self._writer_file(written, execution_context.workflow_run_id, node_id)

        mime_type = str(artifact["mime_type"])
        if mime_type not in ALLOWED_MIME_TYPES:
            raise InvalidPublicationInput(
                destination, "Publisher received an unsupported artifact MIME type."
            )
        path = Path(str(artifact["path"])).resolve()
        if not path.is_relative_to(self.artifact_root):
            raise InvalidPublicationInput(
                destination, "Publisher artifact must remain inside Nasaq storage."
            )
        if destination == "youtube" and mime_type != "video/mp4":
            raise InvalidPublicationInput(
                destination, "YouTube publication requires a video/mp4 predecessor."
            )

        idempotency_key = f"{execution_context.workflow_run_id}:{node_id}"
        publication = PublicationInput(
            title=str((written or {}).get("title") or config.get("title") or artifact["file_name"]),
            description=str(config.get("description") or (written or {}).get("content") or "")[
                :5000
            ]
            or None,
            file_path=str(path),
            file_name=str(artifact["file_name"]),
            mime_type=mime_type,
            idempotency_key=idempotency_key,
            privacy_status=str(config.get("privacy_status", "private")),
        )
        provider = self.providers[destination]
        payload, reused = self.idempotency_store.execute_once(
            idempotency_key,
            lambda: provider.publish(publication, execution_context.user_id).model_dump(
                mode="json"
            ),
        )
        warnings = []
        if payload["simulated"]:
            warnings.append("DEMO MODE: publication was simulated; no external upload occurred.")
        if reused:
            warnings.append("A completed publication was reused; no duplicate upload occurred.")
        return AgentExecution(
            output_type="publication",
            data={
                "publication": payload,
                "provider": payload["provider"],
                "resource_id": payload["resource_id"],
                "url": payload["url"],
                "mime_type": payload["mime_type"],
                "simulated": payload["simulated"],
                "idempotency_key": idempotency_key,
                "idempotency_reused": reused,
            },
            warnings=warnings,
            provider=str(payload["provider"]),
        )

    @classmethod
    def _artifact(cls, input_data: dict[str, Any]) -> dict[str, Any] | None:
        for value in input_data.values():
            found = cls._artifact_in(value)
            if found is not None:
                return found
        return None

    @classmethod
    def _artifact_in(cls, value: Any) -> dict[str, Any] | None:
        if isinstance(value, dict):
            if {"path", "file_name", "mime_type"} <= value.keys():
                return value
            for nested in value.values():
                found = cls._artifact_in(nested)
                if found is not None:
                    return found
        elif isinstance(value, list):
            for nested in value:
                found = cls._artifact_in(nested)
                if found is not None:
                    return found
        return None

    @staticmethod
    def _written_content(input_data: dict[str, Any]) -> dict[str, Any] | None:
        for value in input_data.values():
            if (
                isinstance(value, dict)
                and {"title", "content", "language", "format"} <= value.keys()
            ):
                return value
        return None

    def _writer_file(self, written: dict[str, Any], run_id: str, node_id: str) -> dict[str, Any]:
        safe_run = re.sub(r"[^A-Za-z0-9._-]+", "-", run_id).strip(".-") or "unknown"
        safe_node = re.sub(r"[^A-Za-z0-9._-]+", "-", node_id).strip(".-") or "unknown"
        directory = self.artifact_root / safe_run[:100] / safe_node[:100]
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / "publication.md"
        path.write_text(f"# {written['title']}\n\n{written['content']}\n", encoding="utf-8")
        return {
            "path": str(path),
            "file_name": path.name,
            "mime_type": "text/markdown; charset=utf-8",
            "checksum_sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        }

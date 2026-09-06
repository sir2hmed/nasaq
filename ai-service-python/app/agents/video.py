"""Convert structured Writer output into a genuine local MP4 artifact."""

import hashlib
import re
from pathlib import Path
from typing import Any

from app.agents.base import AgentExecution, AgentExecutionError, BaseAgent, validation_error
from app.domain import AgentArtifact, ExecutionContext, ExecutionError
from app.providers.contracts import SpeechProvider
from app.providers.speech import DemoSpeechProvider
from app.services.video import VideoService, VideoServiceError


class VideoAgent(BaseAgent):
    agent_type = "video"
    input_schema = "Writer structured script or text output"
    output_schema = "scene plan and a verified playable MP4 artifact"

    def __init__(
        self,
        artifact_root: Path,
        speech_provider: SpeechProvider | None = None,
        video_service: VideoService | None = None,
        default_voice: str = "alloy",
    ) -> None:
        self.artifact_root = artifact_root
        self.speech_provider = speech_provider or DemoSpeechProvider()
        self.video_service = video_service or VideoService()
        self.default_voice = default_voice

    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        errors: list[ExecutionError] = []
        scene_duration = config.get("scene_duration")
        if (
            not isinstance(scene_duration, int | float)
            or isinstance(scene_duration, bool)
            or not 1 <= scene_duration <= 10
        ):
            errors.append(
                validation_error(
                    "invalid_config",
                    "Video scene duration must be between 1 and 10 seconds.",
                    node_id,
                    self.agent_type,
                    field="scene_duration",
                )
            )
        max_scenes = config.get("max_scenes")
        if (
            not isinstance(max_scenes, int)
            or isinstance(max_scenes, bool)
            or not 1 <= max_scenes <= 8
        ):
            errors.append(
                validation_error(
                    "invalid_config",
                    "Video scene count must be between 1 and 8.",
                    node_id,
                    self.agent_type,
                    field="max_scenes",
                )
            )
        if config.get("narration") not in {"silent", "tts"}:
            errors.append(
                validation_error(
                    "invalid_config",
                    "Video narration must be silent or TTS.",
                    node_id,
                    self.agent_type,
                    field="narration",
                )
            )
        voice = config.get("voice", self.default_voice)
        if not isinstance(voice, str) or not voice.strip() or len(voice) > 80:
            errors.append(
                validation_error(
                    "invalid_config",
                    "Video narration voice is invalid.",
                    node_id,
                    self.agent_type,
                    field="voice",
                )
            )
        return errors

    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        if self._written_content(input_data) is None:
            return [
                validation_error(
                    "invalid_input",
                    "Video requires structured Writer output.",
                    node_id,
                    self.agent_type,
                    expected="writer_text",
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
        written = self._written_content(input_data)
        assert written is not None
        scenes = self._scene_plan(written, config["max_scenes"])
        output_directory = self._output_directory(execution_context.workflow_run_id, node_id)
        output_directory.mkdir(parents=True, exist_ok=True)

        audio_path: Path | None = None
        narration_provider: str | None = None
        narration_model: str | None = None
        warnings: list[str] = []
        if config["narration"] == "tts":
            if execution_context.provider_mode == "demo":
                warnings.append(
                    "DEMO MODE: TTS was requested but the local demo fallback "
                    "is intentionally silent."
                )
            else:
                narration_text = "\n\n".join(scene["text"] for scene in scenes)
                speech = self.speech_provider.synthesize(
                    narration_text,
                    config.get("voice", self.default_voice),
                )
                audio_path = output_directory / "narration.mp3"
                audio_path.write_bytes(speech.audio)
                narration_provider = speech.provider
                narration_model = speech.model

        try:
            rendered = self.video_service.render(
                output_directory,
                scenes,
                float(config["scene_duration"]),
                language=written["language"],
                demo_mode=execution_context.provider_mode == "demo",
                audio_path=audio_path,
            )
        except VideoServiceError as exc:
            raise AgentExecutionError(
                exc.code,
                exc.safe_message,
                retryable=False,
                provider="ffmpeg",
            ) from exc

        artifact = self._artifact(rendered.path, execution_context, node_id)
        narration_status = "generated" if rendered.has_audio else "silent"
        data: dict[str, Any] = {
            "title": written["title"],
            "language": written["language"],
            "scenes": scenes,
            "scene_count": len(scenes),
            "duration_seconds": rendered.duration_seconds,
            "width": rendered.width,
            "height": rendered.height,
            "codec": rendered.codec,
            "has_audio": rendered.has_audio,
            "narration": {
                "requested": config["narration"],
                "status": narration_status,
                "provider": narration_provider,
                "model": narration_model,
            },
            "video": artifact.model_dump(mode="json"),
        }
        if execution_context.provider_mode == "demo":
            data["demo_notice"] = (
                "DEMO MODE — this is a real local MP4 generated from simulated Writer content."
            )
            warnings.append("DEMO MODE: video scenes use simulated upstream content.")

        return AgentExecution(
            output_type="video",
            data=data,
            artifacts=[artifact],
            warnings=warnings,
            provider="ffmpeg",
            model=rendered.codec,
        )

    @staticmethod
    def _written_content(input_data: dict[str, Any]) -> dict[str, Any] | None:
        for value in input_data.values():
            required = {"title", "content", "language", "format"}
            if isinstance(value, dict) and required <= value.keys():
                return value
        return None

    @staticmethod
    def _scene_plan(written: dict[str, Any], max_scenes: int) -> list[dict[str, Any]]:
        cleaned = re.sub(r"(?m)^\s*[#>*-]+\s*", "", str(written["content"]))
        paragraphs = [
            re.sub(r"\s+", " ", paragraph).strip()
            for paragraph in re.split(r"\n\s*\n", cleaned)
            if paragraph.strip()
        ]
        chunks: list[str] = []
        for paragraph in paragraphs:
            if len(paragraph) <= 360:
                chunks.append(paragraph)
                continue
            sentences = [
                sentence.strip()
                for sentence in re.split(r"(?<=[.!?؟])\s+", paragraph)
                if sentence.strip()
            ]
            chunks.extend(sentences or [paragraph[:360]])
        if not chunks:
            chunks = [str(written["title"])]
        selected = chunks[:max_scenes]
        return [
            {"index": index, "title": f"Scene {index}", "text": text[:500]}
            for index, text in enumerate(selected, start=1)
        ]

    def _output_directory(self, run_id: str, node_id: str) -> Path:
        safe_run = self._safe_component(run_id)
        safe_node = self._safe_component(node_id)
        return self.artifact_root.resolve() / safe_run / safe_node

    @staticmethod
    def _safe_component(value: str) -> str:
        cleaned = re.sub(r"[^A-Za-z0-9._-]+", "-", value).strip(".-")
        return cleaned[:100] or "unknown"

    @staticmethod
    def _artifact(path: Path, context: ExecutionContext, node_id: str) -> AgentArtifact:
        checksum = hashlib.sha256(path.read_bytes()).hexdigest()
        artifact_key = hashlib.sha256(
            f"{context.workflow_run_id}:{node_id}:mp4".encode()
        ).hexdigest()[:24]
        return AgentArtifact(
            id=artifact_key,
            owner_id=context.user_id,
            workflow_run_id=context.workflow_run_id,
            node_id=node_id,
            path=str(path.resolve()),
            storage_key=str(path.relative_to(path.parents[2])).replace("\\", "/"),
            mime_type="video/mp4",
            file_name=path.name,
            file_size=path.stat().st_size,
            checksum_sha256=checksum,
        )

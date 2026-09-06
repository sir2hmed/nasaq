import json
import shutil
import subprocess
from pathlib import Path

import pytest

from app.agents.video import VideoAgent
from app.domain import ExecutionContext, WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.providers.contracts import SynthesizedSpeech
from app.services.video import RenderedVideo, VideoService
from tests.test_orchestration import graph_payload


def video_graph_payload() -> dict:
    payload = graph_payload()
    payload["name"] = "Writer to playable video"
    payload["nodes"][1]["config"]["format"] = "script"
    payload["nodes"][2] = {
        "id": "video_01",
        "type": "video",
        "position": {"x": 800, "y": 180},
        "config": {
            "scene_duration": 1,
            "max_scenes": 3,
            "narration": "silent",
            "voice": "alloy",
        },
    }
    payload["edges"][1] = {
        "id": "writer_video",
        "source": "writer_01",
        "target": "video_01",
    }
    return payload


@pytest.mark.skipif(
    shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None,
    reason="FFmpeg is verified in the service image",
)
def test_demo_video_workflow_renders_a_real_playable_mp4(tmp_path: Path) -> None:
    result = WorkflowOrchestrator(AgentRegistry(tmp_path)).execute(
        WorkflowGraph.model_validate(video_graph_payload()),
        run_id="phase10-video-run",
        user_id="video-owner",
        correlation_id="phase10-video-correlation",
    )

    assert result.status == "success"
    video = result.outputs["video_01"]
    assert video.output_type == "video"
    assert video.metadata.provider == "ffmpeg"
    assert video.metadata.demo_mode is True
    assert video.data["scene_count"] == 3
    assert video.data["duration_seconds"] >= 3
    assert video.data["width"] == 1280
    assert video.data["height"] == 720
    assert video.data["has_audio"] is False
    assert video.data["demo_notice"].startswith("DEMO MODE")

    artifact = video.artifacts[0]
    path = Path(artifact.path)
    assert artifact.mime_type == "video/mp4"
    assert artifact.file_name == "nasaq-video.mp4"
    assert artifact.owner_id == "video-owner"
    assert path.is_file()
    assert path.stat().st_size > 1000

    probe = subprocess.run(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "stream=codec_type,codec_name,width,height:format=duration,format_name",
            "-of",
            "json",
            str(path),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    metadata = json.loads(probe.stdout)
    assert "mp4" in metadata["format"]["format_name"].split(",")
    assert float(metadata["format"]["duration"]) > 0
    assert any(stream["codec_type"] == "video" for stream in metadata["streams"])


def test_missing_ffmpeg_is_a_clean_structured_node_failure(tmp_path: Path) -> None:
    registry = AgentRegistry(tmp_path)
    registry._agents["video"] = VideoAgent(
        tmp_path,
        video_service=VideoService(
            ffmpeg_binary="definitely-missing-ffmpeg",
            ffprobe_binary="definitely-missing-ffprobe",
        ),
    )
    result = WorkflowOrchestrator(registry).execute(
        WorkflowGraph.model_validate(video_graph_payload()),
        run_id="phase10-video-failure",
    )

    assert result.status == "failed"
    assert result.errors[0].code == "ffmpeg_unavailable"
    assert result.errors[0].node_id == "video_01"
    assert result.errors[0].retryable is False
    assert "installed" in result.errors[0].message
    assert any(
        log.code == "ffmpeg_unavailable" and log.node_id == "video_01" for log in result.logs
    )


class FakeSpeechProvider:
    provider_name = "openai"

    def synthesize(self, text: str, voice: str) -> SynthesizedSpeech:
        assert "script" in text.lower()
        assert voice == "alloy"
        return SynthesizedSpeech(
            provider="openai",
            model="tts-1",
            audio=b"mock-mp3-audio",
        )


class FakeVideoService:
    def render(
        self,
        output_directory: Path,
        scenes: list[dict],
        scene_duration: float,
        **options: object,
    ) -> RenderedVideo:
        audio_path = options["audio_path"]
        assert isinstance(audio_path, Path)
        assert audio_path.read_bytes() == b"mock-mp3-audio"
        path = output_directory / "nasaq-video.mp4"
        path.write_bytes(b"mock-playable-video")
        return RenderedVideo(path, 4.2, 1280, 720, "h264", True)


def test_real_tts_is_integrated_when_explicitly_configured(tmp_path: Path) -> None:
    agent = VideoAgent(
        tmp_path,
        speech_provider=FakeSpeechProvider(),
        video_service=FakeVideoService(),  # type: ignore[arg-type]
    )
    result = agent.run(
        {
            "writer_01": {
                "title": "A short script",
                "content": "This script becomes a narrated video scene.",
                "language": "en",
                "format": "script",
            }
        },
        {
            "scene_duration": 2,
            "max_scenes": 3,
            "narration": "tts",
            "voice": "alloy",
        },
        ExecutionContext(
            workflow_run_id="real-tts-run",
            user_id="owner-1",
            provider_mode="real",
            correlation_id="real-tts-correlation",
        ),
        "video_01",
    )

    assert result.status == "success"
    assert result.data["has_audio"] is True
    assert result.data["narration"] == {
        "requested": "tts",
        "status": "generated",
        "provider": "openai",
        "model": "tts-1",
    }
    assert "demo_notice" not in result.data

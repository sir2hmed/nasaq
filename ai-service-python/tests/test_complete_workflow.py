from pathlib import Path

from app.domain import WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.services.video import RenderedVideo


class FakeVideoService:
    def render(
        self,
        output_directory: Path,
        scenes: list[dict],
        scene_duration: float,
        *,
        language: str,
        demo_mode: bool,
        audio_path: Path | None = None,
    ) -> RenderedVideo:
        output_directory.mkdir(parents=True, exist_ok=True)
        path = output_directory / "nasaq-video.mp4"
        path.write_bytes(b"phase-12-deterministic-mp4-fixture")
        return RenderedVideo(
            path=path,
            duration_seconds=scene_duration * len(scenes),
            width=1280,
            height=720,
            codec="h264",
            has_audio=False,
        )


def complete_graph() -> WorkflowGraph:
    return WorkflowGraph.model_validate(
        {
            "version": 1,
            "name": "Complete product demo",
            "nodes": [
                {
                    "id": "researcher_01",
                    "type": "researcher",
                    "config": {
                        "topic": "Complete visual agent workflow",
                        "source_count": 3,
                        "language": "en",
                        "search_depth": "basic",
                    },
                },
                {
                    "id": "writer_01",
                    "type": "writer",
                    "config": {
                        "style": "professional",
                        "length": "short",
                        "format": "script",
                        "language": "same_as_input",
                    },
                },
                {
                    "id": "video_01",
                    "type": "video",
                    "config": {
                        "scene_duration": 2,
                        "max_scenes": 4,
                        "narration": "silent",
                        "voice": "alloy",
                    },
                },
                {"id": "approval_01", "type": "approval", "config": {}},
                {
                    "id": "publisher_01",
                    "type": "publisher",
                    "config": {"destination": "youtube", "privacy_status": "private"},
                },
                {
                    "id": "email_01",
                    "type": "email",
                    "config": {
                        "recipients": ["reviewer@example.test"],
                        "subject": "Approved Nasaq demo",
                        "body_template": "The approved video is ready:\n{links}",
                    },
                },
                {
                    "id": "export_01",
                    "type": "export",
                    "config": {"formats": ["markdown", "pdf", "docx"]},
                },
            ],
            "edges": [
                {"id": "edge_01", "source": "researcher_01", "target": "writer_01"},
                {"id": "edge_02", "source": "writer_01", "target": "video_01"},
                {"id": "edge_03", "source": "video_01", "target": "approval_01"},
                {"id": "edge_04", "source": "approval_01", "target": "publisher_01"},
                {"id": "edge_05", "source": "publisher_01", "target": "email_01"},
                {"id": "edge_06", "source": "video_01", "target": "export_01"},
            ],
        }
    )


def test_every_agent_pauses_for_review_then_completes_with_logs_and_outputs(
    tmp_path: Path,
) -> None:
    registry = AgentRegistry(tmp_path)
    registry.get("video").video_service = FakeVideoService()
    orchestrator = WorkflowOrchestrator(registry)

    waiting = orchestrator.execute(
        complete_graph(),
        run_id="phase-12-complete",
        user_id="complete-owner",
        correlation_id="phase-12-correlation",
        provider_mode="demo",
    )
    assert waiting.status == "waiting_for_approval"
    assert waiting.execution_order == [
        "researcher_01",
        "writer_01",
        "video_01",
        "approval_01",
        "publisher_01",
        "email_01",
        "export_01",
    ]
    assert set(waiting.outputs) == {"researcher_01", "writer_01", "video_01"}

    completed = orchestrator.execute(
        complete_graph(),
        run_id="phase-12-complete",
        user_id="complete-owner",
        correlation_id="phase-12-correlation",
        provider_mode="demo",
        approved_node_keys={"approval_01"},
    )

    assert completed.status == "success"
    assert set(completed.outputs) == {
        "researcher_01",
        "writer_01",
        "video_01",
        "approval_01",
        "publisher_01",
        "email_01",
        "export_01",
    }
    assert all(result.status == "success" for result in completed.outputs.values())
    assert (
        completed.outputs["publisher_01"].data["url"].startswith("https://youtube.example.invalid/")
    )
    assert completed.outputs["email_01"].data["delivery_status"] == "simulated"
    assert completed.outputs["email_01"].data["subject"] == "Approved Nasaq demo"
    assert completed.outputs["export_01"].data["formats"] == ["markdown", "pdf", "docx"]
    assert len(completed.outputs["export_01"].artifacts) == 3
    assert all(Path(item.path).is_file() for item in completed.artifacts)
    succeeded_nodes = {log.node_id for log in completed.logs if log.code == "node_succeeded"}
    assert succeeded_nodes == set(completed.outputs)

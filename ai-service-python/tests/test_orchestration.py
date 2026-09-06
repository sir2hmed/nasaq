import hashlib
import zipfile
from pathlib import Path

from starlette.testclient import TestClient

from app.config import Settings
from app.domain import WorkflowGraph
from app.main import create_app
from app.orchestration import AgentRegistry, WorkflowOrchestrator


def graph_payload() -> dict:
    return {
        "version": 1,
        "name": "Phase 5 demo execution",
        "description": "Researcher to Writer to Export",
        "nodes": [
            {
                "id": "researcher_01",
                "type": "researcher",
                "position": {"x": 120, "y": 180},
                "config": {
                    "topic": "Visual AI workflows",
                    "source_count": 3,
                    "language": "en",
                    "search_depth": "basic",
                },
            },
            {
                "id": "writer_01",
                "type": "writer",
                "position": {"x": 460, "y": 180},
                "config": {
                    "style": "professional",
                    "length": "medium",
                    "format": "article",
                    "language": "same_as_input",
                },
            },
            {
                "id": "export_01",
                "type": "export",
                "position": {"x": 800, "y": 180},
                "config": {"formats": ["markdown", "pdf", "docx"]},
            },
        ],
        "edges": [
            {"id": "research_writer", "source": "researcher_01", "target": "writer_01"},
            {"id": "writer_export", "source": "writer_01", "target": "export_01"},
        ],
    }


def orchestrator(artifact_root: Path) -> WorkflowOrchestrator:
    return WorkflowOrchestrator(AgentRegistry(artifact_root))


def test_full_demo_workflow_generates_valid_real_files(tmp_path: Path) -> None:
    result = orchestrator(tmp_path).execute(
        WorkflowGraph.model_validate(graph_payload()),
        run_id="phase5-full-run",
        user_id="user-42",
        correlation_id="correlation-phase5",
    )

    assert result.status == "success"
    assert result.demo_mode is True
    assert result.execution_order == ["researcher_01", "writer_01", "export_01"]
    assert result.errors == []
    assert list(result.outputs) == result.execution_order
    assert all(output.status == "success" for output in result.outputs.values())
    assert result.outputs["researcher_01"].data["sources"][0]["url"].startswith("demo://")
    assert result.outputs["writer_01"].data["demo_notice"].startswith("DEMO MODE")
    assert {artifact.file_name for artifact in result.artifacts} == {
        "demo-output.md",
        "demo-output.pdf",
        "demo-output.docx",
    }

    artifacts = {Path(artifact.path).suffix: artifact for artifact in result.artifacts}
    for artifact in result.artifacts:
        path = Path(artifact.path)
        assert path.is_file()
        assert artifact.owner_id == "user-42"
        assert artifact.workflow_run_id == "phase5-full-run"
        assert artifact.node_id == "export_01"
        assert artifact.file_size == path.stat().st_size
        assert artifact.checksum_sha256 == hashlib.sha256(path.read_bytes()).hexdigest()

    assert artifacts[".md"].path.endswith("demo-output.md")
    assert "DEMO MODE" in Path(artifacts[".md"].path).read_text(encoding="utf-8")
    assert Path(artifacts[".pdf"].path).read_bytes().startswith(b"%PDF-")
    with zipfile.ZipFile(artifacts[".docx"].path) as document:
        assert "[Content_Types].xml" in document.namelist()
        assert "word/document.xml" in document.namelist()

    assert [entry.code for entry in result.logs] == [
        "run_validation_started",
        "run_started",
        "node_started",
        "node_succeeded",
        "node_started",
        "node_succeeded",
        "node_started",
        "node_succeeded",
        "run_succeeded",
    ]


def test_research_demo_is_deterministic_and_standalone(tmp_path: Path) -> None:
    payload = graph_payload()
    payload["nodes"] = [payload["nodes"][0]]
    payload["edges"] = []
    graph = WorkflowGraph.model_validate(payload)

    first = orchestrator(tmp_path).execute(graph, run_id="research-one")
    second = orchestrator(tmp_path).execute(graph, run_id="research-two")

    assert first.status == second.status == "success"
    assert first.outputs["researcher_01"].data == second.outputs["researcher_01"].data


def test_missing_configuration_fails_before_any_node_runs(tmp_path: Path) -> None:
    payload = graph_payload()
    payload["nodes"][0]["config"]["topic"] = ""

    result = orchestrator(tmp_path).execute(
        WorkflowGraph.model_validate(payload), run_id="invalid-config"
    )

    assert result.status == "failed"
    assert result.outputs == {}
    assert result.artifacts == []
    assert result.errors[0].code == "invalid_config"
    assert result.errors[0].node_id == "researcher_01"
    assert result.errors[0].details["field"] == "topic"
    assert not tmp_path.joinpath("invalid-config").exists()


def test_invalid_handoff_returns_a_structured_node_error(tmp_path: Path) -> None:
    payload = graph_payload()
    payload["nodes"] = [payload["nodes"][1]]
    payload["edges"] = []

    result = orchestrator(tmp_path).execute(
        WorkflowGraph.model_validate(payload), run_id="invalid-handoff"
    )

    assert result.status == "failed"
    assert result.errors[0].code == "invalid_input"
    assert result.errors[0].node_id == "writer_01"
    assert result.outputs["writer_01"].status == "failed"
    assert result.outputs["writer_01"].error == result.errors[0]


def test_development_endpoint_executes_the_full_workflow(tmp_path: Path) -> None:
    settings = Settings(
        app_env="testing",
        artifact_root=tmp_path,
        check_dependencies=False,
        allowed_origins=["http://localhost:5173"],
    )
    with TestClient(create_app(settings)) as client:
        response = client.post(
            "/internal/demo-executions",
            json={
                "run_id": "endpoint-run",
                "user_id": "endpoint-user",
                "selected_language": "en",
                "workflow": graph_payload(),
            },
        )

    assert response.status_code == 200
    payload = response.json()
    assert payload["status"] == "success"
    assert payload["execution_order"] == ["researcher_01", "writer_01", "export_01"]
    assert len(payload["artifacts"]) == 3


def test_development_endpoint_is_absent_in_production(tmp_path: Path) -> None:
    settings = Settings(
        app_env="production",
        artifact_root=tmp_path,
        check_dependencies=False,
    )
    with TestClient(create_app(settings)) as client:
        response = client.post(
            "/internal/demo-executions",
            json={"run_id": "blocked", "workflow": graph_payload()},
        )

    assert response.status_code == 404

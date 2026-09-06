from pathlib import Path

import pytest

from app.domain import WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowGraphValidator


def validator() -> WorkflowGraphValidator:
    return WorkflowGraphValidator(AgentRegistry(Path("unused")).agent_types)


def test_topological_sort_is_deterministic_for_parallel_branches() -> None:
    graph = WorkflowGraph.model_validate(
        {
            "version": 1,
            "nodes": [
                node("researcher_b", "researcher", researcher_config("B")),
                node("researcher_a", "researcher", researcher_config("A")),
                node("writer_b", "writer", writer_config()),
                node("writer_a", "writer", writer_config()),
            ],
            "edges": [
                edge("b", "researcher_b", "writer_b"),
                edge("a", "researcher_a", "writer_a"),
            ],
        }
    )

    assert validator().validate(graph) == []
    assert validator().topological_sort(graph) == [
        "researcher_b",
        "researcher_a",
        "writer_b",
        "writer_a",
    ]


def test_cycle_is_rejected_with_structured_error() -> None:
    graph = WorkflowGraph.model_validate(
        {
            "version": 1,
            "nodes": [
                node("researcher", "researcher", researcher_config("DAGs")),
                node("writer", "writer", writer_config()),
                node("export", "export", {"formats": ["markdown"]}),
            ],
            "edges": [
                edge("one", "researcher", "writer"),
                edge("two", "writer", "export"),
                edge("cycle", "export", "researcher"),
            ],
        }
    )

    errors = validator().validate(graph)

    assert "workflow_cycle" in {error.code for error in errors}
    with pytest.raises(ValueError, match="cycle"):
        validator().topological_sort(graph)


def node(node_id: str, agent_type: str, config: dict) -> dict:
    return {"id": node_id, "type": agent_type, "position": {"x": 0, "y": 0}, "config": config}


def edge(edge_id: str, source: str, target: str) -> dict:
    return {"id": edge_id, "source": source, "target": target}


def researcher_config(topic: str) -> dict:
    return {"topic": topic, "source_count": 3, "language": "en", "search_depth": "basic"}


def writer_config() -> dict:
    return {
        "style": "professional",
        "length": "medium",
        "format": "article",
        "language": "same_as_input",
    }

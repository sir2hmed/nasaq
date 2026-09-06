"""Structural validation and deterministic topological ordering for workflow DAGs."""

import heapq
from collections import Counter

from app.domain import ExecutionError, WorkflowGraph

COMPATIBLE_TARGETS: dict[str, frozenset[str]] = {
    "researcher": frozenset({"writer"}),
    "writer": frozenset({"export", "video", "approval", "publisher", "email"}),
    "approval": frozenset({"export", "publisher", "email"}),
    "export": frozenset({"approval", "publisher", "email"}),
    "video": frozenset({"approval", "publisher", "email", "export"}),
    "publisher": frozenset({"email"}),
    "email": frozenset(),
}


class WorkflowGraphValidator:
    def __init__(self, supported_agent_types: frozenset[str]) -> None:
        self.supported_agent_types = supported_agent_types

    def validate(self, graph: WorkflowGraph) -> list[ExecutionError]:
        errors: list[ExecutionError] = []
        if not graph.nodes:
            return [self._error("workflow_empty", "Workflow must contain at least one node.")]

        node_counts = Counter(node.id for node in graph.nodes)
        for node_id, count in node_counts.items():
            if count > 1:
                errors.append(
                    self._error(
                        "duplicate_node_id",
                        "Workflow node identifiers must be unique.",
                        node_id=node_id,
                    )
                )

        edge_counts = Counter(edge.id for edge in graph.edges)
        for edge_id, count in edge_counts.items():
            if count > 1:
                errors.append(
                    self._error(
                        "duplicate_edge_id",
                        "Workflow edge identifiers must be unique.",
                        edge_id=edge_id,
                    )
                )

        connection_counts = Counter((edge.source, edge.target) for edge in graph.edges)
        for (source, target), count in connection_counts.items():
            if count > 1:
                errors.append(
                    self._error(
                        "duplicate_connection",
                        "Workflow cannot contain duplicate connections.",
                        source=source,
                        target=target,
                    )
                )

        node_map = {node.id: node for node in graph.nodes}
        connected: set[str] = set()
        for node in graph.nodes:
            if node.type not in self.supported_agent_types:
                errors.append(
                    self._error(
                        "unsupported_agent",
                        f"Agent type '{node.type}' is not available.",
                        node_id=node.id,
                        agent_type=node.type,
                    )
                )

        for edge in graph.edges:
            source = node_map.get(edge.source)
            target = node_map.get(edge.target)
            if source is None or target is None:
                errors.append(
                    self._error(
                        "dangling_edge",
                        "Workflow edge references a missing node.",
                        edge_id=edge.id,
                        source=edge.source,
                        target=edge.target,
                    )
                )
                continue
            connected.update((source.id, target.id))
            if edge.source == edge.target:
                errors.append(
                    self._error(
                        "self_loop",
                        "A workflow node cannot connect to itself.",
                        edge_id=edge.id,
                        node_id=edge.source,
                    )
                )
            if target.type not in COMPATIBLE_TARGETS.get(source.type, frozenset()):
                errors.append(
                    self._error(
                        "incompatible_contract",
                        f"{source.type} output cannot be passed to {target.type}.",
                        edge_id=edge.id,
                        source_type=source.type,
                        target_type=target.type,
                    )
                )

        if len(graph.nodes) > 1:
            for node in graph.nodes:
                if node.id not in connected:
                    errors.append(
                        self._error(
                            "dangling_node",
                            "Every node in a multi-node workflow must be connected.",
                            node_id=node.id,
                        )
                    )

        if not any(error.code in {"duplicate_node_id", "dangling_edge"} for error in errors):
            try:
                self.topological_sort(graph)
            except ValueError:
                errors.append(self._error("workflow_cycle", "Workflow graph contains a cycle."))
        return errors

    @staticmethod
    def topological_sort(graph: WorkflowGraph) -> list[str]:
        order_index = {node.id: index for index, node in enumerate(graph.nodes)}
        incoming = {node.id: 0 for node in graph.nodes}
        outgoing: dict[str, list[str]] = {node.id: [] for node in graph.nodes}
        for edge in graph.edges:
            if edge.source not in outgoing or edge.target not in incoming:
                raise ValueError("edge references a missing node")
            outgoing[edge.source].append(edge.target)
            incoming[edge.target] += 1

        ready = [
            (order_index[node_id], node_id) for node_id, count in incoming.items() if count == 0
        ]
        heapq.heapify(ready)
        result: list[str] = []
        while ready:
            _, node_id = heapq.heappop(ready)
            result.append(node_id)
            for target in sorted(outgoing[node_id], key=order_index.__getitem__):
                incoming[target] -= 1
                if incoming[target] == 0:
                    heapq.heappush(ready, (order_index[target], target))
        if len(result) != len(graph.nodes):
            raise ValueError("workflow graph contains a cycle")
        return result

    @staticmethod
    def _error(code: str, message: str, **details: object) -> ExecutionError:
        node_id = details.get("node_id")
        agent_type = details.get("agent_type")
        safe_details = {
            key: value for key, value in details.items() if key not in {"node_id", "agent_type"}
        }
        return ExecutionError(
            code=code,
            message=message,
            node_id=node_id if isinstance(node_id, str) else None,
            agent_type=agent_type if isinstance(agent_type, str) else None,
            details=safe_details,
        )

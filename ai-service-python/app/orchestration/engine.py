"""Deterministic DAG engine used by synchronous demos and Celery workers."""

from collections.abc import Callable
from time import perf_counter
from typing import Literal

from app.domain import (
    ExecutionContext,
    ExecutionError,
    ExecutionLog,
    WorkflowExecutionResult,
    WorkflowGraph,
)
from app.orchestration.graph import WorkflowGraphValidator
from app.orchestration.registry import AgentRegistry
from app.orchestration.retry import RetryPolicy


class WorkflowOrchestrator:
    def __init__(
        self,
        registry: AgentRegistry,
        retry_policy: RetryPolicy | None = None,
        cancellation_probe: Callable[[], bool] | None = None,
    ) -> None:
        self.registry = registry
        self.validator = WorkflowGraphValidator(registry.agent_types)
        self.retry_policy = retry_policy or RetryPolicy()
        self.cancellation_probe = cancellation_probe or (lambda: False)

    def execute(
        self,
        graph: WorkflowGraph,
        run_id: str,
        user_id: str | None = None,
        selected_language: str = "en",
        correlation_id: str | None = None,
        provider_mode: Literal["demo", "real"] = "demo",
        approved_node_keys: set[str] | None = None,
    ) -> WorkflowExecutionResult:
        started = perf_counter()
        resolved_correlation_id = correlation_id or f"run-{run_id}"
        logs: list[ExecutionLog] = []

        def log(
            level: str,
            code: str,
            message: str,
            node_id: str | None = None,
            agent_type: str | None = None,
            attempt: int = 1,
        ) -> None:
            logs.append(
                ExecutionLog(
                    sequence=len(logs) + 1,
                    level=level,
                    code=code,
                    message=message,
                    node_id=node_id,
                    agent_type=agent_type,
                    attempt=attempt,
                )
            )

        log("info", "run_validation_started", "Workflow validation started.")
        errors = self.validator.validate(graph)
        if not errors:
            errors = self._configuration_errors(graph)
        if errors:
            for error in errors:
                log("error", error.code, error.message, error.node_id, error.agent_type)
            log("error", "run_failed", "Workflow failed validation and was not executed.")
            return self._result(
                run_id,
                resolved_correlation_id,
                "failed",
                started,
                logs,
                demo_mode=provider_mode == "demo",
                errors=errors,
            )

        execution_order = self.validator.topological_sort(graph)
        log("info", "run_started", "Workflow execution started.")
        node_map = {node.id: node for node in graph.nodes}
        predecessors: dict[str, list[str]] = {node.id: [] for node in graph.nodes}
        for edge in graph.edges:
            predecessors[edge.target].append(edge.source)

        outputs = {}
        approved_nodes = approved_node_keys or set()
        for node_id in execution_order:
            if self.cancellation_probe():
                log("warning", "run_cancelled", "Workflow cancellation was acknowledged.")
                return self._result(
                    run_id,
                    resolved_correlation_id,
                    "cancelled",
                    started,
                    logs,
                    demo_mode=provider_mode == "demo",
                    execution_order=execution_order,
                    outputs=outputs,
                )

            node = node_map[node_id]
            requires_approval = (
                node.type == "approval" or node.config.get("require_approval") is True
            )
            if requires_approval and node_id not in approved_nodes:
                log(
                    "warning",
                    "approval_required",
                    f"{node.type.title()} is waiting for human approval.",
                    node_id,
                    node.type,
                )
                return self._result(
                    run_id,
                    resolved_correlation_id,
                    "waiting_for_approval",
                    started,
                    logs,
                    demo_mode=provider_mode == "demo",
                    execution_order=execution_order,
                    outputs=outputs,
                )
            agent = self.registry.get(node.type)
            assert agent is not None
            log(
                "info",
                "node_started",
                f"{node.type.title()} started.",
                node_id,
                node.type,
            )
            input_data = {
                predecessor_id: outputs[predecessor_id].data
                for predecessor_id in predecessors[node_id]
            }
            attempt = 1
            while True:
                context = ExecutionContext(
                    workflow_run_id=run_id,
                    user_id=user_id,
                    prior_node_outputs=outputs,
                    selected_language=selected_language,
                    provider_mode=provider_mode,
                    correlation_id=resolved_correlation_id,
                    retry_attempt=attempt,
                )
                result = agent.normalize_output(
                    agent.run(input_data, node.config, context, node_id)
                )
                if result.status == "success":
                    outputs[node_id] = result
                    log(
                        "info",
                        "node_succeeded",
                        f"{node.type.title()} completed.",
                        node_id,
                        node.type,
                        attempt,
                    )
                    break

                error = result.error or ExecutionError(
                    code="agent_failed",
                    message=f"{node.type.title()} failed.",
                    node_id=node_id,
                    agent_type=node.type,
                )
                if self.retry_policy.should_retry(error, attempt):
                    next_attempt = attempt + 1
                    log(
                        "warning",
                        "node_retrying",
                        (
                            f"{node.type.title()} hit a transient error; retrying "
                            f"attempt {next_attempt} of {self.retry_policy.max_attempts}."
                        ),
                        node_id,
                        node.type,
                        next_attempt,
                    )
                    if self.cancellation_probe():
                        log(
                            "warning",
                            "run_cancelled",
                            "Workflow cancellation was acknowledged.",
                        )
                        return self._result(
                            run_id,
                            resolved_correlation_id,
                            "cancelled",
                            started,
                            logs,
                            demo_mode=provider_mode == "demo",
                            execution_order=execution_order,
                            outputs=outputs,
                        )
                    self.retry_policy.wait(attempt)
                    attempt = next_attempt
                    continue

                outputs[node_id] = result
                log("error", error.code, error.message, node_id, node.type, attempt)
                log("error", "run_failed", "Workflow stopped after a node failure.")
                return self._result(
                    run_id,
                    resolved_correlation_id,
                    "failed",
                    started,
                    logs,
                    demo_mode=provider_mode == "demo",
                    execution_order=execution_order,
                    outputs=outputs,
                    errors=[error],
                )

        log("info", "run_succeeded", "Workflow execution completed successfully.")
        return self._result(
            run_id,
            resolved_correlation_id,
            "success",
            started,
            logs,
            demo_mode=provider_mode == "demo",
            execution_order=execution_order,
            outputs=outputs,
        )

    def _configuration_errors(self, graph: WorkflowGraph) -> list[ExecutionError]:
        errors: list[ExecutionError] = []
        for node in graph.nodes:
            agent = self.registry.get(node.type)
            if agent is not None:
                errors.extend(agent.validate_config(node.config, node.id))
        return errors

    @staticmethod
    def _result(
        run_id: str,
        correlation_id: str,
        status: str,
        started: float,
        logs: list[ExecutionLog],
        demo_mode: bool,
        execution_order: list[str] | None = None,
        outputs: dict | None = None,
        errors: list[ExecutionError] | None = None,
    ) -> WorkflowExecutionResult:
        resolved_outputs = outputs or {}
        artifacts = [
            artifact for result in resolved_outputs.values() for artifact in result.artifacts
        ]
        return WorkflowExecutionResult(
            run_id=run_id,
            correlation_id=correlation_id,
            status=status,
            demo_mode=demo_mode,
            execution_order=execution_order or [],
            outputs=resolved_outputs,
            artifacts=artifacts,
            logs=logs,
            errors=errors or [],
            duration_ms=max(0, round((perf_counter() - started) * 1000)),
        )

"""Research agent backed by an injected demo or real search provider."""

from typing import Any

from app.agents.base import AgentExecution, BaseAgent, TransientAgentError, validation_error
from app.domain import ExecutionContext, ExecutionError
from app.providers.contracts import SearchProvider
from app.providers.search import DemoSearchProvider


class ResearcherAgent(BaseAgent):
    agent_type = "researcher"
    input_schema = "topic and optional upstream context"
    output_schema = "topic, summary, key_points, and labeled sources"

    def __init__(self, search_provider: SearchProvider | None = None) -> None:
        self.search_provider = search_provider or DemoSearchProvider()

    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        errors: list[ExecutionError] = []
        topic = config.get("topic")
        if not isinstance(topic, str) or not topic.strip():
            errors.append(
                validation_error(
                    "invalid_config",
                    "Researcher requires a topic.",
                    node_id,
                    self.agent_type,
                    field="topic",
                )
            )

        source_count = config.get("source_count")
        if (
            isinstance(source_count, bool)
            or not isinstance(source_count, int)
            or not 1 <= source_count <= 20
        ):
            errors.append(
                validation_error(
                    "invalid_config",
                    "Researcher source_count must be an integer from 1 to 20.",
                    node_id,
                    self.agent_type,
                    field="source_count",
                )
            )

        if config.get("language") not in {"en", "ar"}:
            errors.append(
                validation_error(
                    "invalid_config",
                    "Researcher language must be en or ar.",
                    node_id,
                    self.agent_type,
                    field="language",
                )
            )
        if config.get("search_depth", "basic") not in {"basic", "advanced"}:
            errors.append(
                validation_error(
                    "invalid_config",
                    "Researcher search_depth must be basic or advanced.",
                    node_id,
                    self.agent_type,
                    field="search_depth",
                )
            )
        simulated_failures = config.get("simulate_transient_failures", 0)
        if (
            isinstance(simulated_failures, bool)
            or not isinstance(simulated_failures, int)
            or not 0 <= simulated_failures <= 3
        ):
            errors.append(
                validation_error(
                    "invalid_config",
                    "Researcher simulate_transient_failures must be an integer from 0 to 3.",
                    node_id,
                    self.agent_type,
                    field="simulate_transient_failures",
                )
            )
        return errors

    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        return []

    def execute(
        self,
        input_data: dict[str, Any],
        config: dict[str, Any],
        execution_context: ExecutionContext,
        node_id: str,
    ) -> AgentExecution:
        simulated_failures = config.get("simulate_transient_failures", 0)
        if (
            execution_context.provider_mode == "demo"
            and execution_context.retry_attempt <= simulated_failures
        ):
            raise TransientAgentError(
                "demo_transient_provider_error",
                "Researcher encountered a simulated transient provider error.",
            )

        topic = config["topic"].strip()
        source_count = config["source_count"]
        language = config["language"]
        search = self.search_provider.search(
            topic,
            source_count,
            config.get("search_depth", "basic"),
            language,
        )
        sources = [source.model_dump(mode="json") for source in search.sources]

        if execution_context.provider_mode == "demo":
            if language == "ar":
                summary = f"بحث تجريبي حتمي عن «{topic}». هذه محاكاة وليست نتيجة بحث ويب حقيقي."
                points = [
                    f"نقطة تجريبية {index}: زاوية منظمة لدراسة {topic}."
                    for index in range(1, min(source_count, 5) + 1)
                ]
            else:
                summary = (
                    f"Deterministic demo research about {topic}. "
                    "This is simulated data, not live web research."
                )
                points = [
                    f"Demo point {index}: a structured perspective for examining {topic}."
                    for index in range(1, min(source_count, 5) + 1)
                ]
            warnings = ["DEMO MODE: sources are simulated and must not be treated as web research."]
        else:
            summary = (
                f"تم استرجاع {len(sources)} مصادر من {search.provider} حول «{topic}». "
                "تُحفظ الحقائق المقتطفة أدناه منفصلة عن أي صياغة لاحقة."
                if language == "ar"
                else (
                    f"Retrieved {len(sources)} sources from {search.provider} about {topic}. "
                    "The source snippets below remain separate from later synthesis."
                )
            )
            points = [source["snippet"] for source in sources[:5]]
            warnings = []

        data = {
            "topic": topic,
            "summary": summary,
            "key_points": points,
            "sources": sources,
            "language": language,
            "provider_request_id": search.request_id,
        }
        if execution_context.provider_mode == "demo":
            data["demo_notice"] = "DEMO MODE — simulated research; no web search was performed."

        return AgentExecution(
            output_type="research",
            data=data,
            warnings=warnings,
            provider=search.provider,
        )

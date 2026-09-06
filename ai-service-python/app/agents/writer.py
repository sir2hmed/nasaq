"""Writer agent consuming structured research without changing source metadata."""

from typing import Any

from app.agents.base import AgentExecution, BaseAgent, validation_error
from app.domain import ExecutionContext, ExecutionError
from app.providers.contracts import LanguageModelProvider
from app.providers.llm import DemoLanguageModelProvider


class WriterAgent(BaseAgent):
    agent_type = "writer"
    input_schema = "Researcher structured output"
    output_schema = "title, content, sources, word count, language, and format"

    def __init__(self, language_model_provider: LanguageModelProvider | None = None) -> None:
        self.language_model_provider = language_model_provider or DemoLanguageModelProvider()

    def validate_config(self, config: dict[str, Any], node_id: str) -> list[ExecutionError]:
        allowed = {
            "style": {"professional", "educational", "conversational"},
            "length": {"short", "medium", "long"},
            "format": {"article", "script", "summary"},
            "language": {"same_as_input", "en", "ar"},
        }
        errors: list[ExecutionError] = []
        for field, choices in allowed.items():
            if config.get(field) not in choices:
                errors.append(
                    validation_error(
                        "invalid_config",
                        f"Writer {field} is missing or unsupported.",
                        node_id,
                        self.agent_type,
                        field=field,
                        allowed=sorted(choices),
                    )
                )
        return errors

    def validate_input(self, input_data: dict[str, Any], node_id: str) -> list[ExecutionError]:
        if self._research(input_data) is None:
            return [
                validation_error(
                    "invalid_input",
                    "Writer requires structured Researcher output.",
                    node_id,
                    self.agent_type,
                    expected="research",
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
        research = self._research(input_data)
        assert research is not None
        language = config["language"]
        if language == "same_as_input":
            language = research.get("language", execution_context.selected_language)

        sources = research["sources"]
        generated = self.language_model_provider.generate(research, config, language)
        data = {
            "title": generated.title,
            "content": generated.content,
            "source_references": sources,
            "word_count": len(generated.content.split()),
            "language": language,
            "format": config["format"],
            "style": config["style"],
        }
        warnings: list[str] = []
        if execution_context.provider_mode == "demo":
            data["demo_notice"] = "DEMO MODE — content is based only on simulated research data."
            warnings.append("DEMO MODE: written content is based on simulated research sources.")

        return AgentExecution(
            output_type="text",
            data=data,
            warnings=warnings,
            provider=generated.provider,
            model=generated.model,
        )

    @staticmethod
    def _research(input_data: dict[str, Any]) -> dict[str, Any] | None:
        for value in input_data.values():
            required = {"topic", "summary", "key_points", "sources"}
            if isinstance(value, dict) and required <= value.keys():
                return value
        return None

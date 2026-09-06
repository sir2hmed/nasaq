import json
from pathlib import Path

import httpx

from app.domain import WorkflowGraph
from app.orchestration import AgentRegistry, WorkflowOrchestrator
from app.orchestration.retry import RetryPolicy
from app.providers.llm import OpenAIResponsesProvider
from app.providers.search import TavilySearchProvider
from app.providers.speech import OpenAISpeechProvider
from tests.test_orchestration import graph_payload


def research_writer_graph() -> WorkflowGraph:
    payload = graph_payload()
    payload["nodes"] = payload["nodes"][:2]
    payload["edges"] = payload["edges"][:1]
    return WorkflowGraph.model_validate(payload)


def successful_search_response() -> dict:
    return {
        "request_id": "tavily-request-42",
        "results": [
            {
                "title": "Source title preserved exactly",
                "url": "https://example.org/research/source-42",
                "content": "A source-grounded fact returned by the search provider.",
                "score": 0.98,
                "published_date": "2026-07-14",
            }
        ],
    }


def test_tavily_adapter_sends_the_official_contract_and_preserves_metadata() -> None:
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, json=successful_search_response())

    result = TavilySearchProvider(
        "server-side-search-key",
        transport=httpx.MockTransport(handler),
    ).search("visual AI workflows", 4, "advanced", "en")

    assert requests[0].headers["authorization"] == "Bearer server-side-search-key"
    assert json.loads(requests[0].content) == {
        "query": "visual AI workflows",
        "search_depth": "advanced",
        "max_results": 4,
        "include_answer": False,
        "include_raw_content": False,
    }
    assert result.request_id == "tavily-request-42"
    assert result.sources[0].model_dump() == {
        "title": "Source title preserved exactly",
        "url": "https://example.org/research/source-42",
        "snippet": "A source-grounded fact returned by the search provider.",
        "score": 0.98,
        "published_at": "2026-07-14",
        "is_demo": False,
    }


def test_real_pipeline_uses_search_and_llm_and_preserves_sources(tmp_path: Path) -> None:
    llm_requests: list[httpx.Request] = []

    def search_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=successful_search_response())

    def llm_handler(request: httpx.Request) -> httpx.Response:
        llm_requests.append(request)
        structured = {
            "title": "A grounded provider article",
            "content": "This article uses only the supplied source metadata.",
        }
        return httpx.Response(
            200,
            json={
                "output": [
                    {
                        "type": "message",
                        "content": [{"type": "output_text", "text": json.dumps(structured)}],
                    }
                ]
            },
        )

    registry = AgentRegistry(
        tmp_path,
        search_provider=TavilySearchProvider(
            "valid-search-key", transport=httpx.MockTransport(search_handler)
        ),
        language_model_provider=OpenAIResponsesProvider(
            "valid-llm-key",
            "gpt-5.6",
            transport=httpx.MockTransport(llm_handler),
        ),
    )
    result = WorkflowOrchestrator(registry).execute(
        research_writer_graph(),
        run_id="real-provider-run",
        correlation_id="real-provider-correlation",
        provider_mode="real",
    )

    assert result.status == "success"
    assert result.demo_mode is False
    research = result.outputs["researcher_01"]
    writer = result.outputs["writer_01"]
    assert research.metadata.provider == "tavily"
    assert research.metadata.demo_mode is False
    assert research.data["provider_request_id"] == "tavily-request-42"
    assert "demo_notice" not in research.data
    assert writer.metadata.provider == "openai"
    assert writer.metadata.model == "gpt-5.6"
    assert writer.metadata.demo_mode is False
    assert writer.data["source_references"] == research.data["sources"]
    assert "demo_notice" not in writer.data

    llm_payload = json.loads(llm_requests[0].content)
    assert llm_payload["text"]["format"]["type"] == "json_schema"
    assert llm_payload["text"]["format"]["strict"] is True
    supplied_research = json.loads(llm_payload["input"][1]["content"])["research"]
    assert supplied_research["sources"] == research.data["sources"]


def test_real_mode_missing_credentials_is_safe_and_not_retried(tmp_path: Path) -> None:
    delays: list[float] = []
    registry = AgentRegistry(
        tmp_path,
        search_provider=TavilySearchProvider(None),
        language_model_provider=OpenAIResponsesProvider(None, "gpt-5.6"),
    )
    result = WorkflowOrchestrator(
        registry,
        retry_policy=RetryPolicy(sleep_fn=delays.append),
    ).execute(
        research_writer_graph(),
        run_id="real-missing-key",
        correlation_id="real-missing-key-correlation",
        provider_mode="real",
    )

    assert result.status == "failed"
    assert result.demo_mode is False
    assert result.errors[0].code == "provider_credentials_missing"
    assert result.errors[0].retryable is False
    assert result.errors[0].details == {"provider": "tavily"}
    assert "SEARCH_API_KEY" in result.errors[0].message
    assert result.outputs["researcher_01"].metadata.provider == "tavily"
    assert result.outputs["researcher_01"].metadata.demo_mode is False
    assert delays == []


def test_real_transient_provider_failure_retries_then_succeeds(tmp_path: Path) -> None:
    attempts = 0
    delays: list[float] = []

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            return httpx.Response(503, json={"detail": "temporarily unavailable"})
        return httpx.Response(200, json=successful_search_response())

    graph_payload_value = graph_payload()
    graph_payload_value["nodes"] = graph_payload_value["nodes"][:1]
    graph_payload_value["edges"] = []
    result = WorkflowOrchestrator(
        AgentRegistry(
            tmp_path,
            search_provider=TavilySearchProvider(
                "valid-search-key", transport=httpx.MockTransport(handler)
            ),
        ),
        retry_policy=RetryPolicy(
            sleep_fn=delays.append,
            jitter_ratio=0,
        ),
    ).execute(
        WorkflowGraph.model_validate(graph_payload_value),
        run_id="real-retry",
        correlation_id="real-retry-correlation",
        provider_mode="real",
    )

    assert result.status == "success"
    assert attempts == 2
    assert delays == [0.5]
    assert [(log.code, log.attempt) for log in result.logs if log.code == "node_retrying"] == [
        ("node_retrying", 2)
    ]


def test_openai_speech_adapter_uses_audio_speech_contract() -> None:
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(200, content=b"provider-mp3-bytes")

    speech = OpenAISpeechProvider(
        "server-side-tts-key",
        "tts-1",
        transport=httpx.MockTransport(handler),
    ).synthesize("A bilingual Nasaq narration.", "alloy")

    assert requests[0].url.path == "/v1/audio/speech"
    assert requests[0].headers["authorization"] == "Bearer server-side-tts-key"
    assert json.loads(requests[0].content) == {
        "model": "tts-1",
        "input": "A bilingual Nasaq narration.",
        "voice": "alloy",
        "response_format": "mp3",
    }
    assert speech.provider == "openai"
    assert speech.model == "tts-1"
    assert speech.audio == b"provider-mp3-bytes"

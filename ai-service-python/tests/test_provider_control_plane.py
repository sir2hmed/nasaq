import json

import httpx

import app.tasks.executions as execution_tasks
from app.config import Settings
from app.providers.factory import build_provider_bundle
from app.providers.gemini_provider import GeminiProvider
from app.providers.openai_provider import OpenAIProvider
from app.services.provider_resolution import ProviderResolution
from tests.test_orchestration import graph_payload


def test_openai_and_gemini_use_selected_models_and_normalized_shape() -> None:
    def openai_handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path.endswith("/chat/completions")
        assert request.headers["authorization"] == "Bearer test-openai-key"
        return httpx.Response(
            200,
            json={
                "id": "request-1",
                "choices": [{"message": {"content": "openai output"}, "finish_reason": "stop"}],
                "usage": {"total_tokens": 2},
            },
        )

    def gemini_handler(request: httpx.Request) -> httpx.Response:
        assert "test-gemini-key" not in str(request.url)
        assert request.headers["x-goog-api-key"] == "test-gemini-key"
        return httpx.Response(
            200,
            json={
                "candidates": [{"content": {"parts": [{"text": "gemini output"}]}}],
                "usageMetadata": {"totalTokenCount": 2},
            },
        )

    openai = OpenAIProvider(
        api_key="test-openai-key",
        default_model="gpt-4o-mini",
        transport=httpx.MockTransport(openai_handler),
    )
    gemini = GeminiProvider(
        api_key="test-gemini-key",
        default_model="gemini-2.0-flash",
        transport=httpx.MockTransport(gemini_handler),
    )
    openai_result = openai.generate("test")
    gemini_result = gemini.generate("test")
    assert (openai_result.provider, openai_result.model, openai_result.content) == (
        "openai",
        "gpt-4o-mini",
        "openai output",
    )
    assert (gemini_result.provider, gemini_result.model, gemini_result.content) == (
        "gemini",
        "gemini-2.0-flash",
        "gemini output",
    )


def test_live_bundle_requires_laravel_resolution_and_never_uses_environment_key_by_default() -> (
    None
):
    settings = Settings(
        llm_provider="openai",
        llm_api_key="environment-key",
        allow_environment_provider_fallback=False,
    )
    unavailable = build_provider_bundle(settings, "real")
    assert unavailable.language_model.provider_name == "unconfigured"

    resolved = ProviderResolution("real", "gemini", "gemini-2.0-flash", "resolved-key")
    bundle = build_provider_bundle(settings, "real", resolved)
    assert bundle.language_model.provider_name == "gemini"
    assert bundle.language_model.model == "gemini-2.0-flash"


def test_safe_execution_metadata_excludes_provider_key() -> None:
    resolution = ProviderResolution("real", "openai", "gpt-4o", "test-secret")
    safe_metadata = {
        "provider": resolution.provider,
        "model": resolution.model,
        "state": resolution.state,
    }
    assert "test-secret" not in json.dumps(safe_metadata)


def test_live_task_resolves_laravel_provider_before_constructing_bundle(
    tmp_path, monkeypatch
) -> None:
    captured = {}
    resolution = ProviderResolution("real", "openai", "gpt-4o-mini", "test-resolution-key")

    monkeypatch.setattr(execution_tasks.ProviderResolutionClient, "resolve", lambda *_: resolution)
    monkeypatch.setattr(execution_tasks, "get_settings", lambda: Settings(artifact_root=tmp_path))

    class FakeBundle:
        search = speech = drive = youtube = email = object()
        language_model = object()

    def fake_bundle(settings, mode, selected_resolution):
        captured.update(
            {
                "mode": mode,
                "provider": selected_resolution.provider,
                "model": selected_resolution.model,
            }
        )
        return FakeBundle()

    class FakeResult:
        run_id = "11111111-1111-4111-8111-111111111111"
        correlation_id = "22222222-2222-4222-8222-222222222222"
        status = "success"

    class FakeOrchestrator:
        def __init__(self, *_args, **_kwargs):
            pass

        def execute(self, *_args, **_kwargs):
            return FakeResult()

    monkeypatch.setattr(execution_tasks, "build_provider_bundle", fake_bundle)
    monkeypatch.setattr(execution_tasks, "WorkflowOrchestrator", FakeOrchestrator)
    monkeypatch.setattr(execution_tasks, "publish_execution_events", lambda *_args: None)
    monkeypatch.setattr(execution_tasks.CancellationStore, "clear", lambda *_args: None)
    monkeypatch.setattr(execution_tasks.CancellationStore, "is_requested", lambda *_args: False)

    payload = {
        "run_id": "11111111-1111-4111-8111-111111111111",
        "correlation_id": "22222222-2222-4222-8222-222222222222",
        "user_id": "42",
        "selected_language": "en",
        "workflow": graph_payload(),
        "demo_mode": False,
        "callback": {
            "base_url": "http://laravel.test/api/internal",
            "token": "test-callback-token",
        },
    }
    execution_tasks.execute_workflow_task.run(payload)
    assert captured == {"mode": "real", "provider": "openai", "model": "gpt-4o-mini"}

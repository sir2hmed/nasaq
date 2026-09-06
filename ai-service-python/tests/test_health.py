from starlette.testclient import TestClient

from app.config import Settings
from app.main import create_app


def make_client() -> TestClient:
    settings = Settings(
        check_dependencies=False,
        allowed_origins=["http://localhost:5173"],
    )
    return TestClient(create_app(settings))


def test_liveness_reports_service_version() -> None:
    with make_client() as client:
        response = client.get("/live")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "ai-service",
        "version": "0.1.0",
    }


def test_readiness_reports_demo_mode_and_redis_contract() -> None:
    with make_client() as client:
        response = client.get("/health")

    assert response.status_code == 200
    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["mode"] == "demo"
    assert payload["checks"]["redis"]["ready"] is True
    assert payload["checks"]["celery"]["ready"] is True


def test_cors_allows_only_configured_frontend() -> None:
    with make_client() as client:
        response = client.options(
            "/health",
            headers={
                "Origin": "http://localhost:5173",
                "Access-Control-Request-Method": "GET",
            },
        )

    assert response.status_code == 200
    assert response.headers["access-control-allow-origin"] == "http://localhost:5173"

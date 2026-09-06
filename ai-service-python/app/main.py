"""FastAPI application factory."""

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api.demo_executions import router as demo_executions_router
from app.api.health import router as health_router
from app.api.internal_executions import router as internal_executions_router
from app.api.provider_probe import router as provider_probe_router
from app.config import Settings, get_settings


def create_app(settings: Settings | None = None) -> FastAPI:
    resolved = settings or get_settings()
    app = FastAPI(
        title=resolved.app_name,
        version=resolved.app_version,
        docs_url="/docs" if resolved.app_env != "production" else None,
        redoc_url=None,
    )
    app.state.settings = resolved
    app.add_middleware(
        CORSMiddleware,
        allow_origins=resolved.allowed_origins,
        allow_credentials=False,
        allow_methods=["GET", "POST"],
        allow_headers=["Content-Type", "X-Correlation-ID", "X-Nasaq-Service-Token"],
    )
    app.include_router(health_router)
    app.include_router(internal_executions_router)
    app.include_router(provider_probe_router)
    if resolved.app_env != "production":
        app.include_router(demo_executions_router)
    return app


app = create_app()

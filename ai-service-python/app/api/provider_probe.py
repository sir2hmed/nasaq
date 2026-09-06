"""Protected, low-cost provider reachability probe for the Laravel admin plane."""

from secrets import compare_digest

from fastapi import APIRouter, Header, HTTPException, Request
from pydantic import BaseModel, Field

from app.providers.errors import (
    MissingProviderCredential,
    ProviderAuthenticationError,
    ProviderError,
    TransientProviderError,
    UnsupportedProvider,
)
from app.providers.provider_factory import ProviderFactory

router = APIRouter(prefix="/internal/providers", tags=["internal"])


class ProviderProbeRequest(BaseModel):
    provider: str = Field(pattern="^(openai|gemini)$")
    model: str = Field(min_length=1, max_length=120)
    credentials: dict[str, str]
    base_url: str | None = Field(default=None, max_length=2048)


@router.post("/probe")
def probe_provider(
    payload: ProviderProbeRequest,
    request: Request,
    service_token: str | None = Header(default=None, alias="X-Nasaq-Service-Token"),
) -> dict:
    settings = request.app.state.settings
    if service_token is None or not compare_digest(service_token, settings.ai_service_token):
        raise HTTPException(status_code=401, detail="Invalid internal service token.")

    key = payload.credentials.get("api_key")
    if not key:
        return {
            "data": {
                "success": False,
                "status": "unconfigured",
                "message": "Provider credentials are not configured.",
            }
        }

    try:
        provider = ProviderFactory.create_llm_provider(
            payload.provider,
            api_key=key,
            default_model=payload.model,
            base_url=payload.base_url,
            timeout_seconds=min(settings.provider_timeout_seconds, 15.0),
        )
        provider.generate("Reply with OK.", max_tokens=1)
    except MissingProviderCredential:
        result = (False, "unconfigured", "Provider credentials are not configured.")
    except ProviderAuthenticationError:
        result = (False, "authentication_failed", "Provider rejected the configured credentials.")
    except TransientProviderError as exc:
        status = "rate_limited" if exc.code == "provider_rate_limited" else "unreachable"
        result = (
            False,
            status,
            "Provider is temporarily unavailable."
            if status == "unreachable"
            else "Provider rate limit was reached.",
        )
    except UnsupportedProvider, ProviderError:
        result = (False, "unsupported", "Provider configuration is not supported.")
    except Exception:
        result = (False, "unknown", "Provider test could not be completed.")
    else:
        result = (True, "reachable", "Provider authenticated and responded to a bounded probe.")

    return {"data": {"success": result[0], "status": result[1], "message": result[2]}}

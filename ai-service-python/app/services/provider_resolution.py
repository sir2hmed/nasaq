"""Fetch the execution-only provider configuration from Laravel at task start."""

from dataclasses import dataclass

import httpx


@dataclass(frozen=True, slots=True)
class ProviderResolution:
    mode: str
    provider: str
    model: str | None = None
    api_key: str | None = None
    base_url: str | None = None
    timeout_seconds: float | None = None
    state: str = "configured"
    candidates: tuple[dict[str, str], ...] = ()


class ProviderResolutionClient:
    def __init__(self, base_url: str, service_token: str, timeout_seconds: float) -> None:
        self.base_url = base_url.rstrip("/")
        self.service_token = service_token
        self.timeout_seconds = timeout_seconds

    def resolve(self, run_id: str, correlation_id: str) -> ProviderResolution:
        try:
            with httpx.Client(timeout=self.timeout_seconds) as client:
                response = client.get(
                    f"{self.base_url}/runs/{run_id}/provider-resolution",
                    headers={
                        "X-Nasaq-Service-Token": self.service_token,
                        "X-Correlation-ID": correlation_id,
                        "Accept": "application/json",
                    },
                )
        except httpx.TimeoutException, httpx.NetworkError:
            return ProviderResolution("real", "unavailable", state="unreachable")

        data = (
            response.json().get("data")
            if response.headers.get("content-type", "").startswith("application/json")
            else None
        )
        if not isinstance(data, dict):
            return ProviderResolution("real", "unavailable", state="unknown")
        if response.status_code == 422:
            return ProviderResolution(
                "real", "unavailable", state=str(data.get("state", "unconfigured"))
            )
        if not response.is_success:
            return ProviderResolution("real", "unavailable", state="unreachable")
        if data.get("mode") == "demo":
            return ProviderResolution("demo", "demo")
        credentials = data.get("credentials")
        api_key = credentials.get("api_key") if isinstance(credentials, dict) else None
        provider, model = data.get("provider"), data.get("model")
        if (
            not isinstance(provider, str)
            or not isinstance(model, str)
            or not isinstance(api_key, str)
            or not api_key
        ):
            return ProviderResolution("real", "unavailable", state="unconfigured")
        candidates = tuple(candidate for candidate in data.get("candidates", []) if isinstance(candidate, dict) and all(isinstance(candidate.get(key), str) and candidate[key] for key in ("provider", "model", "api_key")))
        return ProviderResolution(
            "real",
            provider,
            model,
            api_key,
            data.get("base_url") if isinstance(data.get("base_url"), str) else None,
            float(data["timeout_seconds"])
            if isinstance(data.get("timeout_seconds"), (int, float))
            else None,
            candidates=candidates,
        )

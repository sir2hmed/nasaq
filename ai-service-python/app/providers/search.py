"""Deterministic demo search and real Tavily Search API adapter."""

import re

import httpx
from pydantic import ValidationError

from app.providers.contracts import SearchResponse, SearchSource
from app.providers.errors import (
    InvalidProviderResponse,
    MissingProviderCredential,
    ProviderAuthenticationError,
    TransientProviderError,
    UnsupportedProvider,
)


class DemoSearchProvider:
    provider_name = "demo"

    def search(
        self,
        query: str,
        max_results: int,
        search_depth: str,
        language: str,
    ) -> SearchResponse:
        slug = re.sub(r"[^a-z0-9]+", "-", query.casefold()).strip("-") or "topic"
        if language == "ar":
            source_title = "مصدر تجريبي"
            snippet = "مقتطف مُنشأ محليًا لاختبار انتقال البيانات بين الوكلاء."
        else:
            source_title = "Demo source"
            snippet = "Locally generated sample text for testing structured agent handoffs."
        return SearchResponse(
            provider=self.provider_name,
            sources=[
                SearchSource(
                    title=f"[DEMO] {source_title} {index}",
                    url=f"demo://nasaq/research/{slug}/{index}",
                    snippet=snippet,
                    is_demo=True,
                )
                for index in range(1, max_results + 1)
            ],
        )


class TavilySearchProvider:
    provider_name = "tavily"

    def __init__(
        self,
        api_key: str | None,
        base_url: str = "https://api.tavily.com",
        timeout_seconds: float = 30.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.timeout_seconds = timeout_seconds
        self.transport = transport

    def search(
        self,
        query: str,
        max_results: int,
        search_depth: str,
        language: str,
    ) -> SearchResponse:
        if not self.api_key:
            raise MissingProviderCredential(self.provider_name, "SEARCH_API_KEY")
        try:
            with httpx.Client(timeout=self.timeout_seconds, transport=self.transport) as client:
                response = client.post(
                    f"{self.base_url}/search",
                    headers={"Authorization": f"Bearer {self.api_key}"},
                    json={
                        "query": query,
                        "search_depth": search_depth,
                        "max_results": max_results,
                        "include_answer": False,
                        "include_raw_content": False,
                    },
                )
        except (httpx.TimeoutException, httpx.NetworkError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        if response.status_code in {401, 403}:
            raise ProviderAuthenticationError(self.provider_name)
        if response.status_code == 429 or response.status_code >= 500:
            raise TransientProviderError(self.provider_name)
        if not response.is_success:
            raise InvalidProviderResponse(self.provider_name)

        try:
            payload = response.json()
            sources = [
                SearchSource(
                    title=item["title"],
                    url=item["url"],
                    snippet=item["content"],
                    score=item.get("score"),
                    published_at=item.get("published_date"),
                    is_demo=False,
                )
                for item in payload["results"]
            ]
            invalid_urls = any(
                not source.url.startswith(("https://", "http://")) for source in sources
            )
            if not sources or invalid_urls:
                raise ValueError("Tavily sources must contain real HTTP URLs.")
            return SearchResponse(
                provider=self.provider_name,
                sources=sources,
                request_id=payload.get("request_id"),
            )
        except (KeyError, TypeError, ValueError, ValidationError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc


class UnavailableSearchProvider:
    def __init__(self, provider_name: str) -> None:
        self.provider_name = provider_name or "unconfigured"

    def search(
        self,
        query: str,
        max_results: int,
        search_depth: str,
        language: str,
    ) -> SearchResponse:
        raise UnsupportedProvider(self.provider_name, "search")

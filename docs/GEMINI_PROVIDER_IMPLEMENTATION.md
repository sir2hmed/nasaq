# Gemini API Key Provider

Gemini is available in **Admin → AI Providers & Agent Routing** for Researcher, Writer, Email, Planner, and General Assistant routes. Its form accepts exactly one credential: **Gemini API Key**. It does not show or accept a project ID, organization ID, service account, OAuth flow, region, or Base URL.

The provider uses Google's official Gemini API endpoint internally. The recommended default model is `gemini-3.7-flash`; the catalog also lists `gemini-3.6-flash` and `gemini-2.5-flash`.

Save & Test encrypts the key, makes a bounded backend probe, and records `verified` only on success. A verified route can be made primary or a fallback. The Writer execution resolver returns verified routes in primary/fallback order and the worker tries the next verified candidate if the first provider raises a provider error.

Changed: Gemini catalog migration, provider-routing form/controller, internal provider resolution, worker resolution/factory, fallback provider, and tests/docs.

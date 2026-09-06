# API Provider Add, Test, and Use Repair Report

Date: 2026-08-25

## Root causes

1. The frontend provider-routing writes did not initialise Laravel's CSRF cookie. An already-authenticated administrator could therefore receive a session/CSRF failure depending on how the session began.
2. A saved configuration did not retain the selected agent category. It could be tested, but category ownership was lost before activation.
3. Fresh configurations were not rendered in a location with an activation action. A successful test therefore left the administrator with no way to make the provider primary.
4. The Add Provider entry point selected OpenAI by default, contrary to the Gemini-first path.

## Repair

- Added CSRF initialisation to every provider-routing write action.
- Added `ai_provider_category_id` to provider configurations and enforce it during activation.
- Save configurations as `draft`, transition through `testing`, and finish as `verified` or `failed` following the bounded backend probe.
- Added a Saved provider configurations panel with Test Connection / Fix & Test Again / Activate as Primary actions.
- Made Gemini and `gemini-3.7-flash` the initial Add Provider selection.
- Gemini retains one credential input only, using a masked, browser-safe presentation after saving.

## Security

All provider-routing routes remain restricted to active authenticated administrators. CSRF is established before mutations. Credentials are encrypted in Laravel, are sent to Gemini only by the backend probe/worker, and are never returned in raw form. Activation requires a verified configuration and uses a transaction to retain one primary route per category.

## Agent use

Writer execution obtains its provider from the service-only central routing endpoint. A verified active Gemini Writer route is resolved there and constructed through the Gemini adapter in the worker. A missing verified Writer route returns a controlled configuration error.

## Live-key note

No secret key was used in automated checks. An administrator must complete the live connection test with their Gemini key; the result is truthful and can be either verified or failed.

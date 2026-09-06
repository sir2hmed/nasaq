# API Provider Test Matrix

| Area | Coverage |
| --- | --- |
| Authorization | Guest/normal user cannot use provider-routing APIs; admin routes use existing Sanctum, active, and admin middleware. |
| Encryption | Configuration test asserts raw API keys are encrypted at rest and absent from JSON. |
| Activation | Unverified configuration is rejected; successful, mocked OpenAI probe verifies then activates a primary. |
| Validation | Provider/category capability and registered model are validated server-side. |
| Routing | Primary replacement and fallback priorities occur in locked database transactions. |
| UI | ESLint and production build cover the redesigned responsive page. |

Run after Docker Desktop starts:

`docker compose run --rm laravel php artisan migrate --force`

`docker compose run --rm laravel php artisan test --filter=AiProviderRoutingTest`

`cd frontend; npm run lint; npm run build`

# API Providers & Agent Routing

The administrator now manages providers by agent category instead of a single global selector. Categories are database seeded: researcher, writer, image, video, publisher, email, planner, and general chat.

Provider configurations progress from `draft`/`key_entered` to `verified` or `failed`. Only verified configurations can be activated as a category primary or fallback. One primary is enforced transactionally; fallbacks are priority ordered.

Supported catalog entries include OpenAI, Gemini, Anthropic, Groq, OpenRouter, Mistral, Together, Hugging Face, Cohere, DeepSeek, xAI, Stability, Replicate, and Runway. OpenAI and Gemini have live test adapters. Other catalog entries accurately remain unavailable for activation until their official execution adapter is implemented.

To configure: open **Admin → AI Providers**, select a category, provider, model, add the key, confirm the administrator password, save, test, then activate it. Add a verified configuration as a fallback when appropriate.

To add a provider or category, insert a provider definition/capability/model entry or category row through a migration. The API consumes database definitions; the frontend does not hardcode the catalog.

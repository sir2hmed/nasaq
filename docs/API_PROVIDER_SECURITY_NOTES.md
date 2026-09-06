# Provider Security Notes

All provider-routing APIs are inside the existing authenticated, active administrator route group. State changes require the administrator password and use Laravel's session/CSRF protections.

Keys are encrypted with Laravel `Crypt` before persistence. API responses return only a masked form, audit metadata excludes credentials, and connection-test messages are stored as sanitized status text. Tests are rate limited to ten per minute.

No provider is eligible for primary or fallback activation until a real supported adapter test reports success. Unsupported catalog providers remain non-verified and cannot be activated.

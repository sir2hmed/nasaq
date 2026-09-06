# Provider Add, Test, Activate, and Use Test Matrix

| Path | Check | Result |
| --- | --- | --- |
| Access | Guest/normal user cannot read or mutate provider routing | Automated Laravel coverage |
| Add | Admin saves Gemini Writer configuration with only API key | Automated Laravel coverage |
| Secret handling | Encrypted at rest; raw key absent from response | Automated Laravel coverage |
| Test | Mocked successful probe marks configuration verified | Automated Laravel coverage |
| Test failure | Failed probe prevents activation and records safe message | Automated Laravel coverage |
| Activate | Verified configuration becomes the only category primary | Automated Laravel coverage |
| Category integrity | Configuration cannot activate for a different category | Automated Laravel coverage |
| Runtime | Worker resolves verified Writer route and constructs Gemini adapter | Existing Laravel/Python provider-resolution coverage |
| Browser | Add form opens, shows Gemini-first fields, and exposes post-test actions | Manual local acceptance after rebuild |
| Live Gemini | Real key validates with Google | Requires administrator key; not claimed as completed |

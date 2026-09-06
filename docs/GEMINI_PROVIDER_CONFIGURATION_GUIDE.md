# Gemini Provider Configuration Guide

1. Sign in as an administrator and open **Admin → AI Providers & Agent Routing**.
2. Select **Add provider configuration**.
3. Choose an agent category, keep **Google Gemini**, and select `gemini-3.7-flash`.
4. Paste the Gemini API key into **Gemini API Key** and select **Save & Test Connection**.
5. Wait for the connection result. The saved entry shows a masked key only.
6. If verified, select **Activate as Primary**. If it fails, replace the key through a new configuration and test again.
7. Run a workflow with a Writer Agent. The worker resolves the active verified Writer route before it creates the Gemini adapter.

No additional password is requested while the administrator session is valid. If the session expires, sign in again.

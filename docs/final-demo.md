# Final demonstration scenarios

Use demo mode unless a step explicitly says otherwise. Demo outputs are visibly
labeled simulated and do not send email or publish externally.

## Scenario A — core MVP

1. Sign in, open **Workflows**, and copy **Research to Article**.
2. Drag or select Researcher, Writer, and Export agents as needed; connect them
   Researcher → Writer → Export.
3. Configure a research topic, Writer style/length, and Markdown/PDF/DOCX export.
4. Validate, save, reload, and confirm nodes, edges, positions, and configuration.
5. Run in demo mode. Watch node statuses and the persisted log timeline.
6. Open the research sources and article preview; download all three real files.

Expected result: a successful asynchronous run with safe simulated research and
writing plus genuine Markdown, PDF, and DOCX artifacts.

## Scenario B — Arabic and RTL

1. Switch to **العربية** and confirm the shell and editor flow right-to-left.
2. Create or copy a research workflow and configure an Arabic topic/output.
3. Save, reload, run in demo mode, and inspect the Arabic article.
4. Download PDF and DOCX and confirm readable Arabic layout.

Expected result: the locale persists, `html[lang="ar"][dir="rtl"]` is active,
the page has no horizontal overflow, and the workflow model is unchanged.

## Scenario C — full product demo

1. Open **Full Product Demo**.
2. Verify Researcher → Writer(script) → Video → Approval → Publisher → Email,
   with Video → Export as the artifact branch.
3. Run demo mode and wait for the approval request.
4. Review the preview, approve, and observe the resumed task.
5. Inspect the video plan/MP4, simulated publication, simulated email, logs, and
   export artifacts. Reload to confirm the latest run remains visible.

Expected result: seven agents complete; approval pauses and resumes execution;
Publisher and Email are clearly simulated; all logs and outputs remain durable.

## Scenario D — visible failure and recovery

1. Copy **Research to Article**, choose real mode, and leave a required provider
   key unset (or use the documented deterministic transient-failure fixture in
   the Phase 7 verifier).
2. Run and observe classified retry events and the terminal safe error.
3. Read the user-facing log; confirm no key, provider body, or stack trace leaks.
4. Supply a valid server-side key or return to demo mode, then rerun.

Expected result: transient failures make at most three attempts, non-retryable
credential failures stop safely, the API remains responsive, and the corrected
workflow succeeds.

## Evidence command

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\scripts\test-all.ps1
```

Use `-SkipDocker` only for a native preflight. The final acceptance result
requires the cumulative live Compose gate.

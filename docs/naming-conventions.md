# Naming conventions

| Area | Convention | Example |
|---|---|---|
| Product text | `Nasaq AI` / `نسق` | Page title |
| Directories | lowercase kebab-case | `ai-service-python` |
| React components | PascalCase `.jsx` | `WorkflowCanvas.jsx` |
| JavaScript functions/variables | camelCase | `validateWorkflow` |
| Python modules/functions | snake_case | `workflow_executor.py` |
| Python classes | PascalCase | `ResearcherAgent` |
| PHP classes | PascalCase | `WorkflowExecutionController` |
| PHP methods/variables | camelCase | `startRun` |
| Database tables/columns | plural/snake_case | `workflow_runs`, `node_key` |
| API paths | plural kebab-case nouns | `/api/workflows/{workflow}` |
| JSON properties | snake_case across service boundaries | `workflow_run_id` |
| Environment variables | upper snake case | `INTERNAL_CALLBACK_TOKEN` |
| Node keys | stable type-prefixed strings | `researcher_01` |
| Run IDs | UUID | `9f…` |
| Event types | upper snake case | `NODE_SUCCEEDED` |
| Status values | lower snake case | `waiting_for_approval` |
| Translation keys | dotted semantic paths | `workflow.actions.run` |

Provider secrets are referenced by connection IDs; they are never embedded in
workflow JSON. Timestamps use ISO 8601 UTC over APIs and timezone-aware database
types.


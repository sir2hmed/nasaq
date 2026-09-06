<?php

namespace App\Http\Controllers;

use App\Http\Requests\Execution\StartWorkflowRunRequest;
use App\Http\Resources\AgentOutputResource;
use App\Http\Resources\ExecutionLogResource;
use App\Http\Resources\WorkflowRunResource;
use App\Models\ExecutionLog;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AIOrchestratorClient;
use App\Services\WorkflowGraphValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkflowRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WorkflowRun::class);
        $perPage = min(max($request->integer('per_page', 20), 1), 50);
        $ownedRuns = $request->user()->workflowRuns();
        $statusCounts = (clone $ownedRuns)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $runs = (clone $ownedRuns)
            ->with(['workflow', 'logs'])
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'runs' => WorkflowRunResource::collection($runs->items())->resolve($request),
            ],
            'message' => 'Workflow runs retrieved.',
            'errors' => null,
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'status_counts' => $statusCounts,
            ],
        ]);
    }

    public function store(
        StartWorkflowRunRequest $request,
        Workflow $workflow,
        WorkflowGraphValidator $validator,
        AIOrchestratorClient $orchestrator,
    ): JsonResponse {
        Gate::authorize('view', $workflow);
        Gate::authorize('create', WorkflowRun::class);

        $existing = WorkflowRun::query()
            ->where('user_id', $request->user()->id)
            ->where('workflow_id', $workflow->id)
            ->where('idempotency_key', $request->validated('idempotency_key'))
            ->first();
        if ($existing !== null) {
            return $this->runResponse($request, $existing, 'Existing workflow run retrieved.', 200);
        }

        $validation = $validator->validate($workflow->graph_json);
        if (! $validation['valid']) {
            $messages = collect([...$validation['errors'], ...$validation['warnings']])
                ->pluck('message')
                ->all();
            throw ValidationException::withMessages(['workflow' => $messages]);
        }

        $run = DB::transaction(function () use ($request, $workflow): WorkflowRun {
            $run = $request->user()->workflowRuns()->create([
                'workflow_id' => $workflow->id,
                'workflow_snapshot' => $workflow->graph_json,
                'status' => 'queued',
                'demo_mode' => $request->validated('mode') === 'demo',
                'correlation_id' => (string) Str::uuid(),
                'idempotency_key' => $request->validated('idempotency_key'),
            ]);
            $workflow->update(['last_run_at' => now()]);

            return $run;
        });

        try {
            $acknowledgement = $orchestrator->start($run->load('user'));
            $run->update(['task_id' => $acknowledgement['task_id']]);
        } catch (Throwable) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_summary' => 'The orchestration service could not start this run.',
            ]);
            ExecutionLog::query()->create([
                'workflow_run_id' => $run->id,
                'event_id' => (string) Str::uuid(),
                'level' => 'error',
                'event_type' => 'RUN_FAILED',
                'message' => 'The orchestration service could not start this run.',
                'context_json' => ['correlation_id' => $run->correlation_id],
                'occurred_at' => now(),
            ]);

            return $this->runResponse(
                $request,
                $run,
                'Workflow execution could not be started.',
                502,
            );
        }

        return $this->runResponse($request, $run->refresh(), 'Workflow run accepted.', 202);
    }

    public function show(Request $request, WorkflowRun $run): JsonResponse
    {
        Gate::authorize('view', $run);

        return $this->runResponse($request, $run, 'Workflow run retrieved.');
    }

    public function logs(Request $request, WorkflowRun $run): JsonResponse
    {
        Gate::authorize('view', $run);
        $logs = $run->logs()->get();

        return response()->json([
            'data' => [
                'logs' => ExecutionLogResource::collection($logs)->resolve($request),
            ],
            'message' => 'Execution logs retrieved.',
            'errors' => null,
        ]);
    }

    public function outputs(Request $request, WorkflowRun $run): JsonResponse
    {
        Gate::authorize('view', $run);
        $outputs = $run->outputs()->get();

        return response()->json([
            'data' => [
                'outputs' => AgentOutputResource::collection($outputs)->resolve($request),
            ],
            'message' => 'Agent outputs retrieved.',
            'errors' => null,
        ]);
    }

    public function cancel(
        Request $request,
        WorkflowRun $run,
        AIOrchestratorClient $orchestrator,
    ): JsonResponse {
        Gate::authorize('cancel', $run);
        if (in_array($run->status, ['success', 'failed', 'cancelled'], true)) {
            return $this->runResponse(
                $request,
                $run,
                'A completed workflow run cannot be cancelled.',
                409,
            );
        }
        if ($run->cancellation_requested_at !== null) {
            return $this->runResponse($request, $run, 'Cancellation was already requested.');
        }

        try {
            $orchestrator->cancel($run);
        } catch (Throwable) {
            return $this->runResponse(
                $request,
                $run,
                'Workflow cancellation could not be requested.',
                502,
            );
        }

        $run->update(['cancellation_requested_at' => now()]);

        return $this->runResponse(
            $request,
            $run->refresh(),
            'Workflow cancellation requested.',
            202,
        );
    }

    public function approve(
        Request $request,
        WorkflowRun $run,
        string $nodeKey,
        AIOrchestratorClient $orchestrator,
    ): JsonResponse {
        Gate::authorize('decideApproval', $run);
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $run, $nodeKey, $validated): void {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $approval = $lockedRun->approvalRequests()
                ->where('node_key', $nodeKey)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedRun->status !== 'waiting_for_approval' || $approval->status !== 'pending') {
                throw ValidationException::withMessages([
                    'approval' => ['This approval request is no longer pending.'],
                ]);
            }

            $approval->update([
                'status' => 'approved',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'comment' => $validated['comment'] ?? null,
            ]);
            $lockedRun->update([
                'status' => 'queued',
                'current_node_key' => null,
                'task_id' => null,
                'error_summary' => null,
            ]);
            $this->recordApprovalDecision(
                $lockedRun,
                $nodeKey,
                'APPROVAL_APPROVED',
                'Human review approved; workflow resume was queued.',
            );
        });

        $run->refresh();
        $approvedNodeKeys = $run->approvalRequests()
            ->where('status', 'approved')
            ->pluck('node_key')
            ->all();
        try {
            $acknowledgement = $orchestrator->start($run->load('user'), $approvedNodeKeys);
            $run->update(['task_id' => $acknowledgement['task_id']]);
        } catch (Throwable) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_summary' => 'The orchestration service could not resume this run.',
            ]);
            ExecutionLog::query()->create([
                'workflow_run_id' => $run->id,
                'event_id' => (string) Str::uuid(),
                'level' => 'error',
                'event_type' => 'RUN_FAILED',
                'message' => 'The orchestration service could not resume this run.',
                'context_json' => ['correlation_id' => $run->correlation_id],
                'occurred_at' => now(),
            ]);

            return $this->runResponse($request, $run, 'Workflow execution could not be resumed.', 502);
        }

        return $this->runResponse(
            $request,
            $run->refresh(),
            'Approval recorded and workflow resume accepted.',
            202,
        );
    }

    public function reject(Request $request, WorkflowRun $run, string $nodeKey): JsonResponse
    {
        Gate::authorize('decideApproval', $run);
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $run, $nodeKey, $validated): void {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $approval = $lockedRun->approvalRequests()
                ->where('node_key', $nodeKey)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedRun->status !== 'waiting_for_approval' || $approval->status !== 'pending') {
                throw ValidationException::withMessages([
                    'approval' => ['This approval request is no longer pending.'],
                ]);
            }

            $approval->update([
                'status' => 'rejected',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'comment' => $validated['comment'] ?? null,
            ]);
            $lockedRun->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_summary' => 'The output was rejected during human review.',
            ]);
            $this->recordApprovalDecision(
                $lockedRun,
                $nodeKey,
                'APPROVAL_REJECTED',
                'Human review rejected the output; the workflow stopped safely.',
                'warning',
            );
        });

        return $this->runResponse(
            $request,
            $run->refresh(),
            'Approval rejected and workflow stopped safely.',
        );
    }

    private function recordApprovalDecision(
        WorkflowRun $run,
        string $nodeKey,
        string $eventType,
        string $message,
        string $level = 'info',
    ): void {
        ExecutionLog::query()->create([
            'workflow_run_id' => $run->id,
            'event_id' => (string) Str::uuid(),
            'node_key' => $nodeKey,
            'level' => $level,
            'event_type' => $eventType,
            'message' => $message,
            'context_json' => ['correlation_id' => $run->correlation_id],
            'occurred_at' => now(),
        ]);
    }

    private function runResponse(
        Request $request,
        WorkflowRun $run,
        string $message,
        int $status = 200,
    ): JsonResponse {
        $run->loadMissing(['workflow', 'logs', 'approvalRequests.previewOutput']);

        return response()->json([
            'data' => [
                'run' => WorkflowRunResource::make($run)->resolve($request),
            ],
            'message' => $message,
            'errors' => $status >= 400 ? ['service' => [$message]] : null,
        ], $status);
    }
}

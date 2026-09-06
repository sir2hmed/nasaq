<?php

namespace App\Services;

use App\Models\AgentOutput;
use App\Models\ApprovalRequest;
use App\Models\ExecutionLog;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExecutionEventService
{
    private const ARTIFACT_MIME_TYPES = [
        'text/markdown; charset=utf-8',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'video/mp4',
        'message/rfc822',
    ];

    /** @param array<string, mixed> $event */
    public function apply(WorkflowRun $run, array $event): bool
    {
        return DB::transaction(function () use ($run, $event): bool {
            $locked = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (ExecutionLog::query()->where('event_id', $event['event_id'])->exists()) {
                return false;
            }

            $this->validateIdentity($locked, $event);
            $occurredAt = Carbon::parse($event['occurred_at']);
            $this->applyTransition($locked, $event, $occurredAt);

            ExecutionLog::query()->create([
                'workflow_run_id' => $locked->id,
                'event_id' => $event['event_id'],
                'node_key' => $event['node_key'] ?? null,
                'level' => $this->level($event['event_type']),
                'event_type' => $event['event_type'],
                'message' => $event['message'],
                'context_json' => [
                    'attempt' => $event['attempt'],
                    'correlation_id' => $event['correlation_id'],
                ],
                'occurred_at' => $occurredAt,
            ]);

            if ($event['event_type'] === 'NODE_SUCCEEDED') {
                $this->persistOutput($locked, $event);
            }
            if ($event['event_type'] === 'APPROVAL_REQUIRED') {
                $this->persistApprovalRequest($locked, $event);
            }

            return true;
        });
    }

    /** @param array<string, mixed> $event */
    private function persistApprovalRequest(WorkflowRun $run, array $event): void
    {
        $preview = AgentOutput::query()
            ->where('workflow_run_id', $run->id)
            ->whereNull('file_path')
            ->latest('id')
            ->first();

        ApprovalRequest::query()->firstOrCreate([
            'workflow_run_id' => $run->id,
            'node_key' => $event['node_key'],
        ], [
            'status' => 'pending',
            'preview_output_id' => $preview?->id,
        ]);
    }

    /** @param array<string, mixed> $event */
    private function validateIdentity(WorkflowRun $run, array $event): void
    {
        if (! hash_equals($run->correlation_id, $event['correlation_id'])) {
            throw ValidationException::withMessages([
                'correlation_id' => ['Correlation ID does not match the workflow run.'],
            ]);
        }

        if (str_starts_with($event['event_type'], 'NODE_') ||
            $event['event_type'] === 'APPROVAL_REQUIRED') {
            $nodeKey = $event['node_key'] ?? null;
            $nodeKeys = collect($run->workflow_snapshot['nodes'] ?? [])->pluck('id');
            if (! is_string($nodeKey) || ! $nodeKeys->containsStrict($nodeKey)) {
                throw ValidationException::withMessages([
                    'node_key' => ['Node does not belong to the workflow snapshot.'],
                ]);
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function applyTransition(WorkflowRun $run, array $event, Carbon $occurredAt): void
    {
        $terminal = in_array($run->status, ['success', 'failed', 'cancelled'], true);
        if ($terminal) {
            throw ValidationException::withMessages([
                'event_type' => ['A terminal workflow run cannot accept new events.'],
            ]);
        }

        $attributes = match ($event['event_type']) {
            'RUN_STARTED' => [
                'status' => 'running',
                'started_at' => $run->started_at ?? $occurredAt,
            ],
            'NODE_STARTED', 'NODE_RETRYING', 'NODE_SUCCEEDED' => [
                'status' => 'running',
                'current_node_key' => $event['node_key'],
            ],
            'APPROVAL_REQUIRED' => [
                'status' => 'waiting_for_approval',
                'current_node_key' => $event['node_key'],
            ],
            'NODE_FAILED' => [
                'status' => 'running',
                'current_node_key' => $event['node_key'],
                'error_summary' => $event['message'],
            ],
            'RUN_FAILED' => [
                'status' => 'failed',
                'current_node_key' => $run->current_node_key,
                'completed_at' => $occurredAt,
                'duration_ms' => $event['data']['duration_ms'] ?? null,
                'error_summary' => $event['message'],
            ],
            'RUN_SUCCEEDED' => [
                'status' => 'success',
                'current_node_key' => null,
                'completed_at' => $occurredAt,
                'duration_ms' => $event['data']['duration_ms'] ?? null,
                'error_summary' => null,
            ],
            'RUN_CANCELLED' => [
                'status' => 'cancelled',
                'current_node_key' => null,
                'completed_at' => $occurredAt,
            ],
            default => [],
        };
        $run->update($attributes);
    }

    /** @param array<string, mixed> $event */
    private function persistOutput(WorkflowRun $run, array $event): void
    {
        $result = $event['data']['result'] ?? null;
        $validated = Validator::make(['result' => $result], [
            'result' => ['required', 'array'],
            'result.agent_type' => ['required', 'string', 'max:64'],
            'result.node_id' => ['required', 'string', 'max:64'],
            'result.status' => ['required', Rule::in(['success'])],
            'result.output_type' => ['required', 'string', 'max:40'],
            'result.data' => ['required', 'array'],
            'result.artifacts' => ['present', 'array', 'max:20'],
            'result.metadata' => ['required', 'array'],
            'result.warnings' => ['present', 'array'],
        ])->validate();
        $result = $validated['result'];
        if ($result['node_id'] !== $event['node_key']) {
            throw ValidationException::withMessages([
                'data.result.node_id' => ['Result node ID does not match the callback node.'],
            ]);
        }

        AgentOutput::query()->updateOrCreate([
            'workflow_run_id' => $run->id,
            'node_key' => $event['node_key'],
            'output_type' => $result['output_type'],
            'file_path' => null,
        ], [
            'agent_type' => $result['agent_type'],
            'content_json' => $result['data'],
            'text_content' => is_string($result['data']['content'] ?? null)
                ? $result['data']['content']
                : null,
            'public_url' => $this->publicUrl($result['data']),
            'metadata_json' => [
                ...$result['metadata'],
                'warnings' => $result['warnings'],
            ],
        ]);

        foreach ($result['artifacts'] as $artifact) {
            $this->persistArtifact($run, $event['node_key'], $result['agent_type'], $artifact);
        }
    }

    /** @param array<string, mixed> $data */
    private function publicUrl(array $data): ?string
    {
        foreach (['public_url', 'url', 'share_url', 'watch_url'] as $key) {
            if (is_string($data[$key] ?? null) && filter_var($data[$key], FILTER_VALIDATE_URL)) {
                return $data[$key];
            }
        }

        $publication = $data['publication'] ?? null;
        if (is_array($publication) && is_string($publication['url'] ?? null) &&
            filter_var($publication['url'], FILTER_VALIDATE_URL)) {
            return $publication['url'];
        }

        return null;
    }

    /** @param array<string, mixed> $artifact */
    private function persistArtifact(
        WorkflowRun $run,
        string $nodeKey,
        string $agentType,
        array $artifact,
    ): void {
        $validated = Validator::make($artifact, [
            'id' => ['required', 'string', 'max:120'],
            'storage_key' => ['required', 'string', 'max:900', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'mime_type' => ['required', 'string', Rule::in(self::ARTIFACT_MIME_TYPES)],
            'file_name' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/\x00-\x1F\x7F]+$/u'],
            'file_size' => ['required', 'integer', 'min:1'],
            'checksum_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();
        if (str_contains($validated['storage_key'], '..') ||
            str_starts_with($validated['storage_key'], '/') ||
            ! str_starts_with($validated['storage_key'], $run->id.'/')) {
            throw ValidationException::withMessages([
                'data.result.artifacts' => ['Artifact storage key is unsafe.'],
            ]);
        }

        $filePath = 'artifacts/'.$validated['storage_key'];
        AgentOutput::query()->updateOrCreate([
            'workflow_run_id' => $run->id,
            'node_key' => $nodeKey,
            'output_type' => 'artifact',
            'file_path' => $filePath,
        ], [
            'agent_type' => $agentType,
            'mime_type' => $validated['mime_type'],
            'file_size' => $validated['file_size'],
            'metadata_json' => [
                'artifact_id' => $validated['id'],
                'file_name' => $validated['file_name'],
                'checksum_sha256' => $validated['checksum_sha256'],
            ],
        ]);
    }

    private function level(string $eventType): string
    {
        return match ($eventType) {
            'NODE_FAILED', 'RUN_FAILED' => 'error',
            'NODE_RETRYING', 'APPROVAL_REQUIRED' => 'warning',
            default => 'info',
        };
    }
}

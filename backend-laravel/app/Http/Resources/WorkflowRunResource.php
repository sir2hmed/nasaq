<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $nodeStatuses = collect($this->workflow_snapshot['nodes'] ?? [])
            ->mapWithKeys(fn (array $node): array => [$node['id'] => 'pending']);

        if ($this->relationLoaded('logs')) {
            foreach ($this->logs as $log) {
                if ($log->node_key === null) {
                    continue;
                }
                $status = match ($log->event_type) {
                    'NODE_STARTED' => 'running',
                    'NODE_RETRYING' => 'retrying',
                    'NODE_SUCCEEDED' => 'success',
                    'NODE_FAILED' => 'failed',
                    'APPROVAL_REQUIRED' => 'waiting_for_approval',
                    default => null,
                };
                if ($status !== null) {
                    $nodeStatuses[$log->node_key] = $status;
                }
            }
        }

        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workflow_name' => $this->workflow?->name,
            'status' => $this->status,
            'current_node_key' => $this->current_node_key,
            'node_statuses' => $nodeStatuses,
            'demo_mode' => $this->demo_mode,
            'correlation_id' => $this->correlation_id,
            'task_id' => $this->task_id,
            'cancellation_requested' => $this->cancellation_requested_at !== null,
            'cancellation_requested_at' => $this->cancellation_requested_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'duration_ms' => $this->duration_ms,
            'error_summary' => $this->error_summary,
            'approvals' => $this->relationLoaded('approvalRequests')
                ? ApprovalRequestResource::collection($this->approvalRequests)->resolve($request)
                : [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

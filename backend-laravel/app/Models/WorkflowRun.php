<?php

namespace App\Models;

use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workflow_id',
    'user_id',
    'workflow_snapshot',
    'status',
    'current_node_key',
    'started_at',
    'completed_at',
    'duration_ms',
    'error_summary',
    'demo_mode',
    'correlation_id',
    'task_id',
    'cancellation_requested_at',
    'idempotency_key',
])]
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ExecutionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<AgentOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(AgentOutput::class)->orderBy('id');
    }

    /** @return HasMany<ApprovalRequest, $this> */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class)->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'workflow_snapshot' => 'array',
            'demo_mode' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'cancellation_requested_at' => 'datetime',
        ];
    }
}

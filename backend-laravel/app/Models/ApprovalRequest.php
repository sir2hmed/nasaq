<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workflow_run_id',
    'node_key',
    'status',
    'preview_output_id',
    'decided_by',
    'decided_at',
    'comment',
])]
class ApprovalRequest extends Model
{
    /** @return BelongsTo<WorkflowRun, $this> */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /** @return BelongsTo<AgentOutput, $this> */
    public function previewOutput(): BelongsTo
    {
        return $this->belongsTo(AgentOutput::class, 'preview_output_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}

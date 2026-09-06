<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workflow_run_id',
    'event_id',
    'node_key',
    'level',
    'event_type',
    'message',
    'context_json',
    'occurred_at',
])]
class ExecutionLog extends Model
{
    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}

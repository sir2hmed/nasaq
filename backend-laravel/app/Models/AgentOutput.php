<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workflow_run_id',
    'node_key',
    'agent_type',
    'output_type',
    'content_json',
    'text_content',
    'file_path',
    'public_url',
    'mime_type',
    'file_size',
    'metadata_json',
])]
class AgentOutput extends Model
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
            'content_json' => 'array',
            'metadata_json' => 'array',
            'file_size' => 'integer',
        ];
    }
}

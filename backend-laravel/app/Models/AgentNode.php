<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workflow_id',
    'node_key',
    'agent_type',
    'configuration_json',
    'position_x',
    'position_y',
])]
class AgentNode extends Model
{
    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'configuration_json' => 'array',
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }
}

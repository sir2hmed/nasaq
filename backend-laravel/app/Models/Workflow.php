<?php

namespace App\Models;

use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'name',
    'description',
    'graph_json',
    'status',
    'version',
    'last_run_at',
])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AgentNode, $this> */
    public function agentNodes(): HasMany
    {
        return $this->hasMany(AgentNode::class);
    }

    /** @return HasMany<WorkflowEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class);
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** @return HasOne<WorkflowRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(WorkflowRun::class)->orderByDesc('created_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'graph_json' => 'array',
            'version' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }
}

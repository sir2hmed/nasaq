<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkflowRun> */
class WorkflowRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'user_id' => User::factory(),
            'workflow_snapshot' => Workflow::factory()->definition()['graph_json'],
            'status' => 'queued',
            'demo_mode' => true,
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}

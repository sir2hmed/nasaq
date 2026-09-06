<?php

namespace Database\Seeders;

use App\Models\AgentOutput;
use App\Models\ExecutionLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrowserAcceptanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SampleWorkflowSeeder::class);
        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        $workflow = Workflow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Full Product Demo')
            ->firstOrFail();
        $run = WorkflowRun::query()->updateOrCreate([
            'user_id' => $user->id,
            'workflow_id' => $workflow->id,
            'idempotency_key' => 'b2f8e8ca-7516-4b2c-8a57-734f18de1201',
        ], [
            'workflow_snapshot' => $workflow->graph_json,
            'status' => 'success',
            'current_node_key' => null,
            'started_at' => now()->subSeconds(12),
            'completed_at' => now(),
            'duration_ms' => 12000,
            'error_summary' => null,
            'demo_mode' => true,
            'correlation_id' => '1f457862-e809-441d-a13c-a5ffd86a37a6',
            'task_id' => '5de0739f-36a6-4c73-a29e-3261fd47051a',
            'cancellation_requested_at' => null,
        ]);

        $run->logs()->delete();
        $run->outputs()->delete();
        $events = [
            [null, 'RUN_STARTED', 'Browser acceptance run started.'],
            ['researcher_01', 'NODE_SUCCEEDED', 'Researcher completed with simulated sources.'],
            ['writer_01', 'NODE_SUCCEEDED', 'Writer completed the bilingual script.'],
            ['video_01', 'NODE_SUCCEEDED', 'Video scene plan completed.'],
            ['approval_01', 'NODE_SUCCEEDED', 'Human review was approved.'],
            ['publisher_01', 'NODE_SUCCEEDED', 'Publication was simulated safely.'],
            ['email_01', 'NODE_SUCCEEDED', 'Email delivery was simulated safely.'],
            ['export_01', 'NODE_SUCCEEDED', 'Export created a real Markdown file.'],
            [null, 'RUN_SUCCEEDED', 'Browser acceptance run completed.'],
        ];
        foreach ($events as $index => [$nodeKey, $eventType, $message]) {
            ExecutionLog::query()->create([
                'workflow_run_id' => $run->id,
                'event_id' => (string) Str::uuid(),
                'node_key' => $nodeKey,
                'level' => 'info',
                'event_type' => $eventType,
                'message' => $message,
                'context_json' => [
                    'attempt' => 1,
                    'correlation_id' => $run->correlation_id,
                ],
                'occurred_at' => now()->subSeconds(count($events) - $index),
            ]);
        }

        $this->output($run, 'researcher_01', 'researcher', 'research', [
            'summary' => 'Deterministic browser QA research for the complete demo.',
            'key_points' => [
                'Every workflow handoff remains visible.',
                'Arabic and English share the same execution model.',
            ],
            'sources' => [[
                'title' => 'Nasaq browser acceptance source',
                'url' => 'https://example.com/nasaq-browser-acceptance',
                'snippet' => 'A safe deterministic source card used only for local browser QA.',
            ]],
            'demo_notice' => 'DEMO MODE — simulated research; no web search was performed.',
        ]);
        AgentOutput::query()->create([
            'workflow_run_id' => $run->id,
            'node_key' => 'writer_01',
            'agent_type' => 'writer',
            'output_type' => 'text',
            'text_content' => 'A complete persisted script remains visible after the workflow page is reloaded.',
            'content_json' => ['title' => 'Persisted Nasaq browser demo'],
        ]);
        $this->output($run, 'video_01', 'video', 'video_plan', [
            'summary' => 'Three accessible video scenes are ready for rendering.',
            'scene_count' => 3,
        ]);
        $this->output($run, 'approval_01', 'approval', 'approval', [
            'summary' => 'Human review approved this demo output.',
            'decision' => 'approved',
        ]);
        AgentOutput::query()->create([
            'workflow_run_id' => $run->id,
            'node_key' => 'publisher_01',
            'agent_type' => 'publisher',
            'output_type' => 'publication',
            'content_json' => [
                'provider' => 'youtube',
                'resource_id' => 'demo-browser-resource',
                'url' => 'https://youtube.example.invalid/nasaq/browser-demo',
                'simulated' => true,
            ],
            'public_url' => 'https://youtube.example.invalid/nasaq/browser-demo',
        ]);
        $this->output($run, 'email_01', 'email', 'email_delivery', [
            'recipients' => ['reviewer@example.test'],
            'subject' => 'Nasaq browser acceptance demo',
            'delivery_status' => 'simulated',
            'simulated' => true,
        ]);

        $markdown = "# Nasaq browser acceptance\n\nThis is a real private Markdown artifact.\n";
        $storageKey = "artifacts/{$run->id}/export_01/browser-acceptance.md";
        Storage::disk('local')->put($storageKey, $markdown);
        AgentOutput::query()->create([
            'workflow_run_id' => $run->id,
            'node_key' => 'export_01',
            'agent_type' => 'export',
            'output_type' => 'artifact',
            'file_path' => $storageKey,
            'mime_type' => 'text/markdown; charset=utf-8',
            'file_size' => strlen($markdown),
            'metadata_json' => [
                'file_name' => 'browser-acceptance.md',
                'checksum_sha256' => hash('sha256', $markdown),
            ],
        ]);
        $workflow->update(['last_run_at' => now()]);
    }

    /** @param array<string, mixed> $content */
    private function output(
        WorkflowRun $run,
        string $nodeKey,
        string $agentType,
        string $outputType,
        array $content,
    ): void {
        AgentOutput::query()->create([
            'workflow_run_id' => $run->id,
            'node_key' => $nodeKey,
            'agent_type' => $agentType,
            'output_type' => $outputType,
            'content_json' => $content,
        ]);
    }
}

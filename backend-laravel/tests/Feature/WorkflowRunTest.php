<?php

namespace Tests\Feature;

use App\Models\AgentOutput;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai_orchestrator.url' => 'http://ai-service.test',
            'services.ai_orchestrator.service_token' => 'phase-six-service-token',
            'services.ai_orchestrator.callback_base_url' => 'http://laravel.test/api/internal',
            'services.ai_orchestrator.callback_token' => 'phase-six-callback-token',
            'services.ai_orchestrator.timeout_seconds' => 5,
        ]);
    }

    public function test_user_starts_an_owned_workflow_with_an_immutable_snapshot(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        $idempotencyKey = (string) Str::uuid();
        Http::fake(fn (Request $request) => Http::response([
            'accepted' => true,
            'task_id' => '33333333-3333-4333-8333-333333333333',
            'run_id' => $request['run_id'],
            'correlation_id' => $request['correlation_id'],
        ], 202));

        $run = $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/run", [
                'mode' => 'demo',
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.demo_mode', true)
            ->assertJsonPath('data.run.task_id', '33333333-3333-4333-8333-333333333333')
            ->json('data.run');

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run['id'],
            'user_id' => $user->id,
            'workflow_id' => $workflow->id,
            'idempotency_key' => $idempotencyKey,
            'status' => 'queued',
        ]);
        $this->assertSame($this->graph(), WorkflowRun::findOrFail($run['id'])->workflow_snapshot);
        Http::assertSent(function (Request $request) use ($run): bool {
            return $request->url() === 'http://ai-service.test/internal/executions'
                && $request->hasHeader('X-Nasaq-Service-Token', 'phase-six-service-token')
                && $request['run_id'] === $run['id']
                && $request['selected_language'] === 'ar'
                && $request['demo_mode'] === true
                && $request['callback']['token'] === 'phase-six-callback-token'
                && $request['workflow']['nodes'][1]['id'] === 'writer_01';
        });
    }

    public function test_user_can_start_a_real_provider_run(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        Http::fake(fn (Request $request) => Http::response([
            'accepted' => true,
            'task_id' => '44444444-4444-4444-8444-444444444444',
            'run_id' => $request['run_id'],
            'correlation_id' => $request['correlation_id'],
        ], 202));

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/run", [
                'mode' => 'real',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.run.demo_mode', false);

        $this->assertDatabaseHas('workflow_runs', [
            'workflow_id' => $workflow->id,
            'demo_mode' => false,
        ]);
        Http::assertSent(fn (Request $request): bool => $request['demo_mode'] === false);
    }

    public function test_start_is_idempotent_and_does_not_dispatch_twice(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        $key = (string) Str::uuid();
        Http::fake(fn (Request $request) => Http::response([
            'accepted' => true,
            'task_id' => (string) Str::uuid(),
            'run_id' => $request['run_id'],
            'correlation_id' => $request['correlation_id'],
        ], 202));

        $first = $this->actingAs($user)->postJson("/api/workflows/{$workflow->id}/run", [
            'mode' => 'demo',
            'idempotency_key' => $key,
        ])->assertAccepted()->json('data.run.id');
        $second = $this->postJson("/api/workflows/{$workflow->id}/run", [
            'mode' => 'demo',
            'idempotency_key' => $key,
        ])->assertOk()->json('data.run.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('workflow_runs', 1);
        Http::assertSentCount(1);
    }

    public function test_invalid_workflow_configuration_is_not_dispatched(): void
    {
        $user = User::factory()->create();
        $graph = $this->graph();
        $graph['nodes'][0]['config']['topic'] = '';
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $graph]);
        Http::fake();

        $this->actingAs($user)->postJson("/api/workflows/{$workflow->id}/run", [
            'mode' => 'demo',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['workflow']);

        $this->assertDatabaseCount('workflow_runs', 0);
        Http::assertNothingSent();
    }

    public function test_workflow_run_start_is_rate_limited_with_a_safe_error_envelope(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($user)->postJson("/api/workflows/{$workflow->id}/run", [
                'mode' => 'invalid',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertUnprocessable();
        }

        $this->postJson("/api/workflows/{$workflow->id}/run", [
            'mode' => 'invalid',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertTooManyRequests()
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.request.0.code', 'rate_limited')
            ->assertJsonPath('errors.request.0.status', 429)
            ->assertHeader('X-Correlation-ID');
    }

    public function test_orchestrator_start_failure_is_persisted_without_leaking_details(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        Http::fake(['*' => Http::response(['detail' => 'provider secret'], 500)]);

        $this->actingAs($user)->postJson("/api/workflows/{$workflow->id}/run", [
            'mode' => 'demo',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(502)
            ->assertJsonPath('data.run.status', 'failed')
            ->assertJsonMissing(['provider secret']);

        $this->assertDatabaseHas('execution_logs', [
            'event_type' => 'RUN_FAILED',
            'message' => 'The orchestration service could not start this run.',
        ]);
    }

    public function test_callback_requires_service_token_and_matching_identity(): void
    {
        [$user, $workflow, $run] = $this->runFixture();
        $event = $this->event($run, 'RUN_STARTED');

        $this->postJson("/api/internal/executions/{$run->id}/events", $event)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid internal service token.');

        $event['correlation_id'] = (string) Str::uuid();
        $this->withHeader('X-Nasaq-Service-Token', 'phase-six-callback-token')
            ->postJson("/api/internal/executions/{$run->id}/events", $event)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['correlation_id']);

        $this->assertDatabaseCount('execution_logs', 0);
    }

    public function test_callbacks_persist_status_logs_outputs_and_are_idempotent(): void
    {
        [$user, $workflow, $run] = $this->runFixture();
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];

        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'RUN_STARTED'),
        )->assertOk();
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'NODE_STARTED', 'writer_01'),
        )->assertOk();
        $succeeded = $this->event($run, 'NODE_SUCCEEDED', 'writer_01', [
            'result' => $this->agentResult('writer_01', 'writer'),
        ]);
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $succeeded,
        )->assertOk()->assertJsonPath('data.duplicate', false);
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $succeeded,
        )->assertOk()->assertJsonPath('data.duplicate', true);
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'RUN_SUCCEEDED', data: ['duration_ms' => 321]),
        )->assertOk();

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(321, $run->duration_ms);
        $this->assertDatabaseCount('execution_logs', 4);
        $this->assertDatabaseHas('agent_outputs', [
            'workflow_run_id' => $run->id,
            'node_key' => 'writer_01',
            'agent_type' => 'writer',
            'output_type' => 'text',
            'text_content' => 'A deterministic demo article.',
        ]);

        $this->actingAs($user)->getJson("/api/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'success')
            ->assertJsonPath('data.run.node_statuses.writer_01', 'success');
        $this->getJson("/api/runs/{$run->id}/logs")
            ->assertOk()
            ->assertJsonCount(4, 'data.logs');
        $this->getJson("/api/runs/{$run->id}/outputs")
            ->assertOk()
            ->assertJsonPath('data.outputs.0.text_content', 'A deterministic demo article.');
    }

    public function test_publisher_callback_persists_the_external_resource_url(): void
    {
        [$user, $workflow, $run] = $this->runFixture();
        $snapshot = $run->workflow_snapshot;
        $snapshot['nodes'][] = [
            'id' => 'publisher_01',
            'type' => 'publisher',
            'position' => ['x' => 1080, 'y' => 180],
            'config' => ['destination' => 'google_drive'],
        ];
        $run->update(['workflow_snapshot' => $snapshot]);
        $result = $this->agentResult('publisher_01', 'publisher');
        $result['output_type'] = 'publication';
        $result['data'] = [
            'publication' => [
                'provider' => 'google_drive',
                'resource_id' => 'drive-file-123',
                'url' => 'https://drive.google.com/file/d/drive-file-123/view',
                'simulated' => false,
            ],
        ];

        $this->withHeader('X-Nasaq-Service-Token', 'phase-six-callback-token')
            ->postJson(
                "/api/internal/executions/{$run->id}/events",
                $this->event($run, 'NODE_SUCCEEDED', 'publisher_01', ['result' => $result]),
            )->assertOk();

        $this->assertDatabaseHas('agent_outputs', [
            'workflow_run_id' => $run->id,
            'node_key' => 'publisher_01',
            'public_url' => 'https://drive.google.com/file/d/drive-file-123/view',
        ]);
        $this->actingAs($user)->getJson("/api/runs/{$run->id}/outputs")
            ->assertOk()
            ->assertJsonPath(
                'data.outputs.0.public_url',
                'https://drive.google.com/file/d/drive-file-123/view',
            );
    }

    public function test_approval_request_persists_preview_and_owner_can_resume(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];
        $writerSucceeded = $this->event($run, 'NODE_SUCCEEDED', 'writer_01', [
            'result' => $this->agentResult('writer_01', 'writer'),
        ]);
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $writerSucceeded,
        )->assertOk();
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'APPROVAL_REQUIRED', 'export_01'),
        )->assertOk();

        $this->assertDatabaseHas('approval_requests', [
            'workflow_run_id' => $run->id,
            'node_key' => 'export_01',
            'status' => 'pending',
        ]);
        $this->actingAs($owner)->getJson("/api/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'waiting_for_approval')
            ->assertJsonPath('data.run.node_statuses.export_01', 'waiting_for_approval')
            ->assertJsonPath('data.run.approvals.0.status', 'pending')
            ->assertJsonPath(
                'data.run.approvals.0.preview_output.text_content',
                'A deterministic demo article.',
            );

        Http::fake(fn (Request $request) => Http::response([
            'accepted' => true,
            'task_id' => '55555555-5555-4555-8555-555555555555',
            'run_id' => $request['run_id'],
            'correlation_id' => $request['correlation_id'],
        ], 202));
        $this->postJson("/api/runs/{$run->id}/approval/export_01/approve", [
            'comment' => 'Reviewed and ready.',
        ])->assertAccepted()
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.approvals.0.status', 'approved');

        $this->assertDatabaseHas('approval_requests', [
            'workflow_run_id' => $run->id,
            'node_key' => 'export_01',
            'status' => 'approved',
            'decided_by' => $owner->id,
            'comment' => 'Reviewed and ready.',
        ]);
        Http::assertSent(fn (Request $request): bool => $request['approved_node_keys'] === ['export_01']
            && $request['demo_mode'] === true
        );
        $this->postJson("/api/runs/{$run->id}/approval/export_01/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approval']);
    }

    public function test_rejection_is_owner_only_and_stops_without_dispatch(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'APPROVAL_REQUIRED', 'export_01'),
        )->assertOk();
        Http::fake();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)
            ->postJson("/api/runs/{$run->id}/approval/export_01/reject")
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson("/api/runs/{$run->id}/approval/export_01/reject", [
                'comment' => 'The output needs revision.',
            ])->assertOk()
            ->assertJsonPath('data.run.status', 'failed')
            ->assertJsonPath('data.run.approvals.0.status', 'rejected');

        $this->assertDatabaseHas('approval_requests', [
            'workflow_run_id' => $run->id,
            'node_key' => 'export_01',
            'status' => 'rejected',
            'decided_by' => $owner->id,
            'comment' => 'The output needs revision.',
        ]);
        $this->assertDatabaseHas('execution_logs', [
            'workflow_run_id' => $run->id,
            'event_type' => 'APPROVAL_REJECTED',
        ]);
        Http::assertNothingSent();
    }

    public function test_owner_can_request_idempotent_best_effort_cancellation(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $taskId = (string) Str::uuid();
        $run->update(['task_id' => $taskId]);
        Http::fake(fn (Request $request) => Http::response([
            'accepted' => true,
            'run_id' => $run->id,
            'correlation_id' => $run->correlation_id,
            'task_id' => $taskId,
        ], 202));

        $this->actingAs($owner)->postJson("/api/runs/{$run->id}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.run.cancellation_requested', true)
            ->assertJsonPath('data.run.status', 'queued');
        $this->postJson("/api/runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.run.cancellation_requested', true);

        $this->assertNotNull($run->refresh()->cancellation_requested_at);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === "http://ai-service.test/internal/executions/{$run->id}/cancel"
            && $request->hasHeader('X-Nasaq-Service-Token', 'phase-six-service-token')
            && $request['correlation_id'] === $run->correlation_id
            && $request['task_id'] === $taskId
        );

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->postJson("/api/runs/{$run->id}/cancel")->assertForbidden();
    }

    public function test_terminal_run_cancellation_is_rejected_without_dispatch(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $run->update(['status' => 'success', 'task_id' => (string) Str::uuid()]);
        Http::fake();

        $this->actingAs($owner)->postJson("/api/runs/{$run->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('data.run.status', 'success');

        Http::assertNothingSent();
    }

    public function test_retry_and_cancellation_callbacks_remain_visible(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];

        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'RUN_STARTED'),
        )->assertOk();
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'NODE_RETRYING', 'researcher_01', attempt: 2),
        )->assertOk();

        $this->actingAs($owner)->getJson("/api/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'running')
            ->assertJsonPath('data.run.node_statuses.researcher_01', 'retrying');
        $this->getJson("/api/runs/{$run->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.logs.1.event_type', 'NODE_RETRYING')
            ->assertJsonPath('data.logs.1.context.attempt', 2);

        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'RUN_CANCELLED'),
        )->assertOk();
        $this->getJson("/api/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'cancelled');
    }

    public function test_artifact_download_is_real_and_owner_authorized(): void
    {
        Storage::fake('local');
        [$owner, $workflow, $run] = $this->runFixture();
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];
        $result = $this->agentResult('export_01', 'export');
        $result['output_type'] = 'files';
        $result['data'] = ['formats' => ['markdown']];
        $result['artifacts'] = [[
            'id' => 'artifact-markdown',
            'storage_key' => "{$run->id}/export_01/demo-output.md",
            'mime_type' => 'text/markdown; charset=utf-8',
            'file_name' => 'demo-output.md',
            'file_size' => 24,
            'checksum_sha256' => str_repeat('a', 64),
        ]];
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'NODE_SUCCEEDED', 'export_01', ['result' => $result]),
        )->assertOk();

        $output = AgentOutput::query()->where('output_type', 'artifact')->firstOrFail();
        Storage::disk('local')->put($output->file_path, 'DEMO MODE markdown file');

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/api/outputs/{$output->id}/download")->assertForbidden();
        $this->actingAs($owner)->get("/api/outputs/{$output->id}/download")
            ->assertOk()
            ->assertDownload('demo-output.md');
    }

    public function test_artifact_callbacks_and_downloads_reject_unsafe_paths_and_mime_types(): void
    {
        Storage::fake('local');
        [$owner, $workflow, $run] = $this->runFixture();
        $result = $this->agentResult('export_01', 'export');
        $result['output_type'] = 'files';
        $result['artifacts'] = [[
            'id' => 'artifact-html',
            'storage_key' => "{$run->id}/export_01/untrusted.html",
            'mime_type' => 'text/html',
            'file_name' => 'untrusted.html',
            'file_size' => 24,
            'checksum_sha256' => str_repeat('c', 64),
        ]];

        $this->withHeader('X-Nasaq-Service-Token', 'phase-six-callback-token')
            ->postJson(
                "/api/internal/executions/{$run->id}/events",
                $this->event($run, 'NODE_SUCCEEDED', 'export_01', ['result' => $result]),
            )->assertUnprocessable();
        $this->assertDatabaseCount('agent_outputs', 0);

        $output = AgentOutput::query()->create([
            'workflow_run_id' => $run->id,
            'node_key' => 'export_01',
            'agent_type' => 'export',
            'output_type' => 'artifact',
            'file_path' => '../outside-private-storage.txt',
            'mime_type' => 'text/markdown; charset=utf-8',
            'file_size' => 10,
            'metadata_json' => ['file_name' => "unsafe\r\nname.md"],
        ]);
        Storage::disk('local')->put('outside-private-storage.txt', 'not downloadable');

        $this->actingAs($owner)
            ->getJson("/api/outputs/{$output->id}/download")
            ->assertNotFound()
            ->assertJsonPath('errors.request.0.code', 'not_found');
    }

    public function test_video_artifact_is_owner_authorized_streamable_and_downloadable(): void
    {
        Storage::fake('local');
        [$owner, $workflow, $run] = $this->runFixture();
        $snapshot = $run->workflow_snapshot;
        $snapshot['nodes'][] = [
            'id' => 'video_01',
            'type' => 'video',
            'position' => ['x' => 1080, 'y' => 180],
            'config' => [
                'scene_duration' => 3,
                'max_scenes' => 6,
                'narration' => 'silent',
                'voice' => 'alloy',
            ],
        ];
        $run->update(['workflow_snapshot' => $snapshot]);
        $headers = ['X-Nasaq-Service-Token' => 'phase-six-callback-token'];
        $result = $this->agentResult('video_01', 'video');
        $result['output_type'] = 'video';
        $result['data'] = [
            'title' => 'Demo video',
            'scene_count' => 3,
            'duration_seconds' => 9.0,
            'has_audio' => false,
        ];
        $result['artifacts'] = [[
            'id' => 'artifact-video',
            'storage_key' => "{$run->id}/video_01/nasaq-video.mp4",
            'mime_type' => 'video/mp4',
            'file_name' => 'nasaq-video.mp4',
            'file_size' => 32,
            'checksum_sha256' => str_repeat('b', 64),
        ]];
        $this->withHeaders($headers)->postJson(
            "/api/internal/executions/{$run->id}/events",
            $this->event($run, 'NODE_SUCCEEDED', 'video_01', ['result' => $result]),
        )->assertOk();

        $output = AgentOutput::query()
            ->where('agent_type', 'video')
            ->where('output_type', 'artifact')
            ->firstOrFail();
        Storage::disk('local')->put($output->file_path, 'playable MP4 fixture bytes');

        $this->actingAs($owner)->getJson("/api/runs/{$run->id}/outputs")
            ->assertOk()
            ->assertJsonPath('data.outputs.1.mime_type', 'video/mp4')
            ->assertJsonPath('data.outputs.1.stream_url', "/api/outputs/{$output->id}/stream")
            ->assertJsonPath('data.outputs.1.download_url', "/api/outputs/{$output->id}/download");

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/api/outputs/{$output->id}/stream")->assertForbidden();
        $this->actingAs($owner)->get("/api/outputs/{$output->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4')
            ->assertHeader('content-disposition', 'inline; filename=nasaq-video.mp4');
        $this->get("/api/outputs/{$output->id}/download")
            ->assertOk()
            ->assertDownload('nasaq-video.mp4');
    }

    public function test_run_visibility_is_limited_to_the_owner(): void
    {
        [$owner, $workflow, $run] = $this->runFixture();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson('/api/runs')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.status_counts', []);
        $this->getJson("/api/runs/{$run->id}")->assertForbidden();
        $this->getJson("/api/runs/{$run->id}/logs")->assertForbidden();
        $this->getJson("/api/runs/{$run->id}/outputs")->assertForbidden();

        $this->actingAs($owner)->getJson('/api/runs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.status_counts.queued', 1);
        $this->getJson("/api/workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow.latest_run_id', $run->id)
            ->assertJsonPath('data.workflow.last_run_status', 'queued');
    }

    public function test_workflow_summary_uses_the_latest_run_without_uuid_aggregation(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        WorkflowRun::factory()->for($workflow)->for($user)->create([
            'workflow_snapshot' => $this->graph(),
            'status' => 'failed',
            'created_at' => now()->subMinute(),
        ]);
        WorkflowRun::factory()->for($workflow)->for($user)->create([
            'workflow_snapshot' => $this->graph(),
            'status' => 'success',
            'created_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('data.workflows.0.last_run_status', 'success');
    }

    /** @return array{User, Workflow, WorkflowRun} */
    private function runFixture(): array
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['graph_json' => $this->graph()]);
        $run = WorkflowRun::factory()->for($workflow)->for($user)->create([
            'workflow_snapshot' => $this->graph(),
        ]);

        return [$user, $workflow, $run];
    }

    /** @return array<string, mixed> */
    private function event(
        WorkflowRun $run,
        string $type,
        ?string $nodeKey = null,
        array $data = [],
        int $attempt = 1,
    ): array {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'correlation_id' => $run->correlation_id,
            'node_key' => $nodeKey,
            'occurred_at' => now()->toIso8601String(),
            'attempt' => $attempt,
            'message' => str($type)->lower()->replace('_', ' ')->ucfirst()->toString().'.',
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function agentResult(string $nodeKey, string $agentType): array
    {
        return [
            'agent_type' => $agentType,
            'node_id' => $nodeKey,
            'status' => 'success',
            'output_type' => 'text',
            'data' => [
                'title' => 'Demo article',
                'content' => 'A deterministic demo article.',
                'language' => 'en',
                'format' => 'article',
            ],
            'artifacts' => [],
            'metadata' => [
                'provider' => 'demo',
                'model' => null,
                'duration_ms' => 1,
                'demo_mode' => true,
            ],
            'warnings' => ['DEMO MODE'],
            'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function graph(): array
    {
        return [
            'version' => 1,
            'name' => 'Phase 6 workflow',
            'description' => 'Cross-service execution',
            'nodes' => [
                [
                    'id' => 'researcher_01',
                    'type' => 'researcher',
                    'position' => ['x' => 120, 'y' => 180],
                    'config' => [
                        'topic' => 'Visual AI workflows',
                        'source_count' => 3,
                        'language' => 'en',
                        'search_depth' => 'basic',
                    ],
                ],
                [
                    'id' => 'writer_01',
                    'type' => 'writer',
                    'position' => ['x' => 460, 'y' => 180],
                    'config' => [
                        'style' => 'professional',
                        'length' => 'medium',
                        'format' => 'article',
                        'language' => 'same_as_input',
                    ],
                ],
                [
                    'id' => 'export_01',
                    'type' => 'export',
                    'position' => ['x' => 800, 'y' => 180],
                    'config' => ['formats' => ['markdown', 'pdf', 'docx']],
                ],
            ],
            'edges' => [
                ['id' => 'edge_research_writer', 'source' => 'researcher_01', 'target' => 'writer_01'],
                ['id' => 'edge_writer_export', 'source' => 'writer_01', 'target' => 'export_01'],
            ],
        ];
    }
}

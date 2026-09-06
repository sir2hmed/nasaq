<?php

namespace App\Services;

use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AIOrchestratorClient
{
    /** @return array<string, mixed> */
    public function start(WorkflowRun $run, array $approvedNodeKeys = []): array
    {
        $baseUrl = rtrim((string) config('services.ai_orchestrator.url'), '/');
        $serviceToken = config('services.ai_orchestrator.service_token');
        $callbackToken = config('services.ai_orchestrator.callback_token');
        $callbackBaseUrl = config('services.ai_orchestrator.callback_base_url');
        if (! is_string($serviceToken) || $serviceToken === '' ||
            ! is_string($callbackToken) || $callbackToken === '' ||
            ! is_string($callbackBaseUrl) || $callbackBaseUrl === '') {
            throw new RuntimeException('AI orchestration service is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Nasaq-Service-Token' => $serviceToken,
                'X-Correlation-ID' => $run->correlation_id,
            ])
            ->timeout((int) config('services.ai_orchestrator.timeout_seconds', 15))
            ->post("$baseUrl/internal/executions", [
                'run_id' => $run->id,
                'correlation_id' => $run->correlation_id,
                'workflow' => $this->executionWorkflow($run->workflow_snapshot),
                'user_id' => (string) $run->user_id,
                'selected_language' => $run->user->preferred_locale,
                'demo_mode' => $run->demo_mode,
                'approved_node_keys' => array_values($approvedNodeKeys),
                'callback' => [
                    'base_url' => $callbackBaseUrl,
                    'token' => $callbackToken,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI orchestration service rejected the execution request.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['accepted'] ?? false) !== true ||
            ($payload['run_id'] ?? null) !== $run->id ||
            ($payload['correlation_id'] ?? null) !== $run->correlation_id ||
            ! is_string($payload['task_id'] ?? null)) {
            throw new RuntimeException('AI orchestration service returned an invalid acknowledgement.');
        }

        return $payload;
    }

    public function cancel(WorkflowRun $run): void
    {
        if (! is_string($run->task_id) || $run->task_id === '') {
            throw new RuntimeException('Workflow run does not have a queued task.');
        }

        $baseUrl = rtrim((string) config('services.ai_orchestrator.url'), '/');
        $serviceToken = config('services.ai_orchestrator.service_token');
        if (! is_string($serviceToken) || $serviceToken === '') {
            throw new RuntimeException('AI orchestration service is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Nasaq-Service-Token' => $serviceToken,
                'X-Correlation-ID' => $run->correlation_id,
            ])
            ->timeout((int) config('services.ai_orchestrator.timeout_seconds', 15))
            ->post("$baseUrl/internal/executions/{$run->id}/cancel", [
                'correlation_id' => $run->correlation_id,
                'task_id' => $run->task_id,
            ]);

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) ||
            ($payload['accepted'] ?? false) !== true ||
            ($payload['run_id'] ?? null) !== $run->id ||
            ($payload['correlation_id'] ?? null) !== $run->correlation_id ||
            ($payload['task_id'] ?? null) !== $run->task_id) {
            throw new RuntimeException('AI orchestration service rejected cancellation.');
        }
    }

    /**
     * Preserve empty node configs as JSON objects at the strict Python boundary.
     *
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private function executionWorkflow(array $workflow): array
    {
        $workflow['nodes'] = array_map(function (array $node): array {
            if (($node['config'] ?? null) === []) {
                $node['config'] = (object) [];
            }

            return $node;
        }, $workflow['nodes'] ?? []);

        return $workflow;
    }
}

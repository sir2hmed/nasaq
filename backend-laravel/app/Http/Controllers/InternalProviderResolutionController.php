<?php

namespace App\Http\Controllers;

use App\Models\AiProviderCategory;
use App\Models\AiProviderCategoryRoute;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;

class InternalProviderResolutionController extends Controller
{
    /**
     * This endpoint is intentionally service-token-only. It is never a browser API.
     * The credential is returned only to the worker process for the lifetime of a run.
     */
    public function __invoke(WorkflowRun $run): JsonResponse
    {
        if ($run->demo_mode) {
            return response()->json([
                'data' => ['mode' => 'demo', 'provider' => 'demo', 'model' => null],
                'message' => 'Deterministic demo provider resolved.',
                'errors' => null,
            ])->header('Cache-Control', 'no-store, private');
        }

        $routed = $this->resolveRoutedCandidates('writer');
        if ($routed !== []) {
            $primary = $routed[0];

            return response()->json([
                'data' => [
                    'mode' => 'real',
                    'provider' => $primary['provider'],
                    'model' => $primary['model'],
                    'credentials' => ['api_key' => $primary['api_key']],
                    'candidates' => $routed,
                    'base_url' => null,
                    'timeout_seconds' => 15,
                ],
                'message' => 'Verified Writer Agent provider route resolved.',
                'errors' => null,
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()->json([
            'data' => ['state' => 'unconfigured'],
            'message' => 'No verified provider is configured for the Writer Agent. Ask an Admin to configure one.',
            'errors' => null,
        ], 422)->header('Cache-Control', 'no-store, private');
    }

    /** @return list<array{provider: string, model: string, api_key: string}> */
    private function resolveRoutedCandidates(string $categoryKey): array
    {
        $category = AiProviderCategory::query()->where('category_key', $categoryKey)->first();
        if (! $category) return [];

        return AiProviderCategoryRoute::query()
            ->with('configuration.definition')
            ->where('ai_provider_category_id', $category->id)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->get()
            ->filter(fn ($route) => $route->configuration->status === 'verified' && in_array($route->configuration->definition->provider_key, ['openai', 'gemini'], true) && filled($route->configuration->credential()))
            ->map(fn ($route) => ['provider' => $route->configuration->definition->provider_key, 'model' => $route->configuration->model_key, 'api_key' => $route->configuration->credential()])
            ->values()
            ->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AiProviderConfiguration;
use App\Models\IntegrationAuditLog;
use App\Models\PlatformIntegration;
use App\Services\AdminAuditLogger;
use App\Services\ProviderProbeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiProviderConfigurationController extends Controller
{
    private array $availableModels = [
        'openai' => ['gpt-4o', 'gpt-4o-mini'],
        'gemini' => ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
    ];

    public function index(): JsonResponse
    {
        $this->ensureConfigurations();
        $providers = AiProviderConfiguration::query()->with('platformIntegration')->get()->keyBy('provider');
        $defaultProvider = $providers->firstWhere('is_default', true)?->provider;

        return response()->json([
            'data' => [
                'platform_default_provider' => $defaultProvider,
                'providers' => collect(['openai', 'gemini'])->mapWithKeys(function (string $provider) use ($providers): array {
                    $config = $providers->get($provider);
                    $integration = $config?->platformIntegration;
                    $configured = $integration !== null && ! empty($integration->encrypted_credentials);
                    $available = $configured && (bool) $integration->is_enabled && (bool) $config?->is_enabled;

                    return [$provider => [
                        'provider' => $provider,
                        'display_name' => $provider === 'openai' ? 'OpenAI' : 'Google Gemini',
                        'default_model' => $config?->default_model,
                        'is_enabled' => (bool) $config?->is_enabled,
                        'is_default' => (bool) $config?->is_default,
                        'configured' => $configured,
                        'available_for_execution' => $available,
                        'connection_status' => $integration?->connection_status ?? 'unconfigured',
                        'last_tested_at' => $integration?->last_tested_at?->toISOString(),
                        'last_test_message' => $integration?->last_test_message,
                        'available_models' => $this->availableModels[$provider],
                    ]];
                })->all(),
            ],
            'message' => 'AI provider configurations retrieved.',
            'errors' => null,
        ]);
    }

    public function update(Request $request, AdminAuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'platform_default' => ['required', Rule::in(['openai', 'gemini'])],
            'openai_default_model' => ['nullable', Rule::in($this->availableModels['openai'])],
            'gemini_default_model' => ['nullable', Rule::in($this->availableModels['gemini'])],
        ]);
        $admin = $request->user();

        DB::transaction(function () use ($validated, $admin, $request, $audit): void {
            $this->ensureConfigurations();
            $configs = AiProviderConfiguration::query()->with('platformIntegration')->lockForUpdate()->get()->keyBy('provider');
            $selected = $configs->get($validated['platform_default']);
            $integration = $selected?->platformIntegration;
            if (! $selected || ! $integration || empty($integration->encrypted_credentials) || ! $integration->is_enabled) {
                abort(422, 'The selected provider must be configured and enabled before it can be the execution default.');
            }

            foreach (['openai', 'gemini'] as $provider) {
                $config = $configs->get($provider);
                $modelKey = "{$provider}_default_model";
                $config->update([
                    'default_model' => $validated[$modelKey] ?? $config->default_model,
                    'is_default' => $provider === $validated['platform_default'],
                    'is_enabled' => $config->platformIntegration?->is_enabled ?? false,
                    'connection_status' => $config->platformIntegration?->connection_status ?? 'unconfigured',
                    'updated_by' => $admin->id,
                ]);
            }

            IntegrationAuditLog::create([
                'platform_integration_id' => $integration->id,
                'admin_user_id' => $admin->id,
                'action' => 'update_provider_config',
                'changed_fields' => ['platform_default' => $validated['platform_default'], 'models_updated' => true],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $audit->log($request, 'provider.default_updated', 'provider', $validated['platform_default'], [
                'platform_default' => $validated['platform_default'],
                'models_updated' => true,
            ]);
        });

        return $this->index();
    }

    public function test(Request $request, string $provider, PlatformIntegrationController $integrations, ProviderProbeClient $probe, AdminAuditLogger $audit): JsonResponse
    {
        return $integrations->test($request, $provider, $probe, $audit);
    }

    private function ensureConfigurations(): void
    {
        foreach (['openai' => 'gpt-4o', 'gemini' => 'gemini-2.0-flash'] as $provider => $model) {
            $integration = PlatformIntegration::query()->where('provider', $provider)->first();
            $configuration = AiProviderConfiguration::firstOrCreate(['provider' => $provider], [
                'platform_integration_id' => $integration?->id,
                'default_model' => $model,
                'is_enabled' => (bool) $integration?->is_enabled,
                'is_default' => false,
                'connection_status' => $integration?->connection_status ?? 'unconfigured',
            ]);
            if ($integration && $configuration->platform_integration_id !== $integration->id) {
                $configuration->update([
                    'platform_integration_id' => $integration->id,
                    'is_enabled' => (bool) $integration->is_enabled,
                    'connection_status' => $integration->connection_status,
                ]);
            }
        }
    }
}

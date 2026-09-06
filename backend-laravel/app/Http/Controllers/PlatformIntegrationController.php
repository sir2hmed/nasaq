<?php

namespace App\Http\Controllers;

use App\Models\AiProviderConfiguration;
use App\Models\IntegrationAuditLog;
use App\Models\PlatformIntegration;
use App\Services\AdminAuditLogger;
use App\Services\ProviderProbeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformIntegrationController extends Controller
{
    private array $supportedProviders = [
        'openai' => 'OpenAI GPT-4o / Responses',
        'gemini' => 'Google Gemini 1.5 Flash/Pro',
        'tavily' => 'Tavily Web Search',
        'serpapi' => 'SerpAPI Search Adapter',
        'youtube' => 'YouTube Data API v3',
        'google_drive' => 'Google Drive API v3',
        'gmail' => 'Gmail API Provider',
        'smtp' => 'SMTP Mail Provider',
        'tts' => 'OpenAI Audio TTS',
    ];

    public function index(): JsonResponse
    {
        $existing = PlatformIntegration::all()->keyBy('provider');

        $integrations = collect($this->supportedProviders)->map(function ($displayName, $provider) use ($existing) {
            $item = $existing->get($provider);

            if (! $item) {
                return [
                    'provider' => $provider,
                    'display_name' => $displayName,
                    'configured' => false,
                    'masked_credentials' => '',
                    'is_enabled' => false,
                    'connection_status' => 'unconfigured',
                    'last_tested_at' => null,
                    'last_test_message' => null,
                    'configuration' => null,
                ];
            }

            return $this->formatIntegrationResponse($item);
        })->values();

        return response()->json([
            'data' => ['integrations' => $integrations],
            'message' => 'Platform integrations retrieved successfully.',
            'errors' => null,
        ]);
    }

    public function show(string $provider): JsonResponse
    {
        $this->validateProvider($provider);

        $integration = PlatformIntegration::where('provider', $provider)->first();

        if (! $integration) {
            return response()->json([
                'data' => [
                    'integration' => [
                        'provider' => $provider,
                        'display_name' => $this->supportedProviders[$provider] ?? ucfirst($provider),
                        'configured' => false,
                        'masked_credentials' => '',
                        'is_enabled' => false,
                        'connection_status' => 'unconfigured',
                        'last_tested_at' => null,
                        'last_test_message' => null,
                        'configuration' => null,
                    ],
                ],
                'message' => 'Integration status retrieved.',
                'errors' => null,
            ]);
        }

        return response()->json([
            'data' => ['integration' => $this->formatIntegrationResponse($integration)],
            'message' => 'Integration status retrieved.',
            'errors' => null,
        ]);
    }

    public function update(Request $request, string $provider, AdminAuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $request->validate([
            'credentials' => ['required'],
            'configuration' => ['nullable', 'array'],
        ]);

        $admin = $request->user();

        $integration = PlatformIntegration::firstOrNew(['provider' => $provider]);
        $integration->display_name = $this->supportedProviders[$provider] ?? ucfirst($provider);
        $integration->setEncryptedCredentials($request->credentials);

        if ($request->has('configuration')) {
            $integration->configuration_json = $request->configuration;
        }

        $integration->connection_status = 'configured';
        $integration->updated_by = $admin->id;
        $integration->save();

        if (in_array($provider, ['openai', 'gemini'], true)) {
            $providerConfig = AiProviderConfiguration::firstOrNew(['provider' => $provider]);
            $providerConfig->platform_integration_id = $integration->id;
            $providerConfig->default_model ??= $provider === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o';
            $providerConfig->is_enabled = (bool) $integration->is_enabled;
            $providerConfig->is_default = $providerConfig->exists ? (bool) $providerConfig->is_default : false;
            $providerConfig->connection_status = 'configured';
            $providerConfig->updated_by = $admin->id;
            $providerConfig->save();
        }

        $action = $integration->wasRecentlyCreated ? 'create' : 'update';
        $this->logAudit($integration, $admin->id, $action, ['credentials_updated', 'configuration_updated'], $request);
        $audit->log($request, "provider.{$action}", 'provider', $provider, ['credentials_updated' => true, 'configuration_updated' => $request->has('configuration')]);

        return response()->json([
            'data' => ['integration' => $this->formatIntegrationResponse($integration)],
            'message' => 'Platform integration credentials updated securely.',
            'errors' => null,
        ]);
    }

    public function test(Request $request, string $provider, ProviderProbeClient $probe, AdminAuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $integration = PlatformIntegration::where('provider', $provider)->first();

        if (! $integration || empty($integration->encrypted_credentials)) {
            return response()->json([
                'data' => null,
                'message' => 'Cannot test unconfigured provider credentials.',
                'errors' => ['provider' => ['No credentials found for this provider.']],
            ], 422);
        }

        $decrypted = $integration->getDecryptedCredentials();
        if (! in_array($provider, ['openai', 'gemini'], true)) {
            $result = ['success' => false, 'status' => 'unsupported', 'message' => 'Connection testing is not available for this provider.'];
        } else {
            $config = AiProviderConfiguration::where('provider', $provider)->first();
            $result = $probe->probe(
                $provider,
                $config?->default_model ?? ($provider === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o'),
                $decrypted ?? [],
                data_get($integration->configuration_json, 'base_url'),
            );
        }

        $integration->update([
            'connection_status' => $result['status'],
            'last_tested_at' => now(),
            'last_test_message' => $result['message'],
        ]);

        AiProviderConfiguration::where('provider', $provider)->update([
            'connection_status' => $result['status'],
            'updated_by' => $request->user()->id,
        ]);

        $this->logAudit($integration, $request->user()->id, 'test', ['status' => $integration->connection_status], $request);
        $audit->log($request, 'provider.tested', 'provider', $provider, ['connection_status' => $integration->connection_status]);

        return response()->json([
            'data' => [
                'success' => $result['success'],
                'connection_status' => $integration->connection_status,
                'last_tested_at' => $integration->last_tested_at->toISOString(),
                'message' => $result['message'],
            ],
            'message' => $result['message'],
            'errors' => null,
        ]);
    }

    public function enable(Request $request, string $provider, AdminAuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $integration = PlatformIntegration::where('provider', $provider)->first();

        if (! $integration || empty($integration->encrypted_credentials)) {
            return response()->json([
                'data' => null,
                'message' => 'Cannot enable unconfigured provider credentials.',
                'errors' => ['provider' => ['No credentials found for this provider.']],
            ], 422);
        }

        $integration->update([
            'is_enabled' => true,
            'updated_by' => $request->user()->id,
        ]);

        AiProviderConfiguration::where('platform_integration_id', $integration->id)->update([
            'is_enabled' => true,
            'connection_status' => $integration->connection_status,
            'updated_by' => $request->user()->id,
        ]);

        $this->logAudit($integration, $request->user()->id, 'enable', ['is_enabled' => true], $request);
        $audit->log($request, 'provider.enabled', 'provider', $provider, ['is_enabled' => true]);

        return response()->json([
            'data' => ['integration' => $this->formatIntegrationResponse($integration)],
            'message' => 'Provider enabled successfully.',
            'errors' => null,
        ]);
    }

    public function disable(Request $request, string $provider, AdminAuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $integration = PlatformIntegration::where('provider', $provider)->first();

        if ($integration) {
            $integration->update([
                'is_enabled' => false,
                'updated_by' => $request->user()->id,
            ]);

            AiProviderConfiguration::where('platform_integration_id', $integration->id)->update([
                'is_enabled' => false,
                'is_default' => false,
                'updated_by' => $request->user()->id,
            ]);

            $this->logAudit($integration, $request->user()->id, 'disable', ['is_enabled' => false], $request);
            $audit->log($request, 'provider.disabled', 'provider', $provider, ['is_enabled' => false]);
        }

        return response()->json([
            'data' => ['integration' => $integration ? $this->formatIntegrationResponse($integration) : null],
            'message' => 'Provider disabled successfully.',
            'errors' => null,
        ]);
    }

    public function destroyCredentials(Request $request, string $provider, AdminAuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $admin = $request->user();

        $integration = PlatformIntegration::where('provider', $provider)->first();

        if ($integration) {
            $integration->update([
                'encrypted_credentials' => null,
                'is_enabled' => false,
                'connection_status' => 'unconfigured',
                'last_test_message' => 'Credentials deleted by administrator.',
                'updated_by' => $admin->id,
            ]);

            AiProviderConfiguration::where('platform_integration_id', $integration->id)->update([
                'is_enabled' => false,
                'is_default' => false,
                'connection_status' => 'unconfigured',
                'updated_by' => $admin->id,
            ]);

            $this->logAudit($integration, $admin->id, 'delete', ['credentials_removed' => true], $request);
            $audit->log($request, 'provider.credentials_deleted', 'provider', $provider, ['credentials_removed' => true]);
        }

        return response()->json([
            'data' => ['integration' => $integration ? $this->formatIntegrationResponse($integration) : null],
            'message' => 'Platform integration credentials removed safely.',
            'errors' => null,
        ]);
    }

    private function validateProvider(string $provider): void
    {
        if (! array_key_exists($provider, $this->supportedProviders)) {
            abort(404, "Unsupported integration provider '{$provider}'.");
        }
    }

    private function formatIntegrationResponse(PlatformIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'provider' => $integration->provider,
            'display_name' => $integration->display_name,
            'configured' => ! empty($integration->encrypted_credentials),
            'masked_credentials' => $integration->getMaskedCredentials(),
            'is_enabled' => (bool) $integration->is_enabled,
            'connection_status' => $integration->connection_status,
            'last_tested_at' => $integration->last_tested_at?->toISOString(),
            'last_test_message' => $integration->last_test_message,
            'configuration' => $integration->configuration_json,
        ];
    }

    private function logAudit(PlatformIntegration $integration, int $adminId, string $action, array $changedFields, Request $request): void
    {
        IntegrationAuditLog::create([
            'platform_integration_id' => $integration->id,
            'admin_user_id' => $adminId,
            'action' => $action,
            'changed_fields' => $changedFields,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}

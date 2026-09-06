<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integration\ConnectIntegrationRequest;
use App\Http\Resources\IntegrationConnectionResource;
use App\Models\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationConnectionController extends Controller
{
    private const PROVIDERS = ['google_drive', 'youtube', 'smtp'];

    public function index(Request $request): JsonResponse
    {
        $connections = $request->user()->integrationConnections()->get()->keyBy('provider');
        $items = collect(self::PROVIDERS)->map(function (string $provider) use ($connections, $request): array {
            $connection = $connections->get($provider);
            if ($connection instanceof IntegrationConnection) {
                return IntegrationConnectionResource::make($connection)->resolve($request);
            }

            return [
                'provider' => $provider,
                'status' => 'disconnected',
                'connected' => false,
                'configured_fields' => [],
                'metadata' => [],
                'connected_at' => null,
                'expires_at' => null,
            ];
        })->values();

        return response()->json([
            'data' => ['integrations' => $items],
            'message' => 'Integration connections retrieved.',
            'errors' => null,
        ]);
    }

    public function connect(ConnectIntegrationRequest $request, string $provider): JsonResponse
    {
        $validated = $request->validated();
        $credentials = $validated['credentials'];
        $metadata = $this->safeMetadata($provider, $credentials);
        $connection = $request->user()->integrationConnections()->updateOrCreate(
            ['provider' => $provider],
            [
                'status' => 'connected',
                'encrypted_credentials' => $credentials,
                'metadata_json' => $metadata,
                'connected_at' => now(),
                'expires_at' => $validated['expires_at'] ?? null,
            ],
        );

        return response()->json([
            'data' => ['integration' => IntegrationConnectionResource::make($connection)->resolve($request)],
            'message' => 'Integration connected. Credentials are encrypted and never returned.',
            'errors' => null,
        ]);
    }

    public function status(Request $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);
        $connection = $request->user()->integrationConnections()->where('provider', $provider)->first();

        return response()->json([
            'data' => [
                'integration' => $connection
                    ? IntegrationConnectionResource::make($connection)->resolve($request)
                    : [
                        'provider' => $provider,
                        'status' => 'disconnected',
                        'connected' => false,
                        'configured_fields' => [],
                        'metadata' => [],
                        'connected_at' => null,
                        'expires_at' => null,
                    ],
            ],
            'message' => 'Integration status retrieved.',
            'errors' => null,
        ]);
    }

    public function disconnect(Request $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);
        $request->user()->integrationConnections()->where('provider', $provider)->delete();

        return response()->json([
            'data' => ['provider' => $provider, 'connected' => false],
            'message' => 'Integration disconnected and stored credentials removed.',
            'errors' => null,
        ]);
    }

    /** @param array<string, mixed> $credentials */
    private function safeMetadata(string $provider, array $credentials): array
    {
        return match ($provider) {
            'google_drive' => ['folder_configured' => ! empty($credentials['folder_id'])],
            'youtube' => ['privacy_status' => $credentials['privacy_status'] ?? 'private'],
            'smtp' => [
                'host' => $credentials['host'],
                'port' => $credentials['port'],
                'from_email' => $credentials['from_email'],
                'encryption' => $credentials['encryption'],
            ],
            default => [],
        };
    }

    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
    }
}

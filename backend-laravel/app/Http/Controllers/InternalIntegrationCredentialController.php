<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class InternalIntegrationCredentialController extends Controller
{
    public function __invoke(User $user, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['google_drive', 'youtube', 'smtp'], true), 404);
        $connection = $user->integrationConnections()
            ->where('provider', $provider)
            ->where('status', 'connected')
            ->first();

        if ($connection === null) {
            return response()->json([
                'data' => null,
                'message' => 'The requested integration is not connected.',
                'errors' => null,
            ], 404);
        }

        return response()->json([
            'data' => [
                'provider' => $provider,
                'credentials' => $connection->encrypted_credentials,
                'metadata' => $connection->metadata_json ?? [],
                'expires_at' => $connection->expires_at?->toISOString(),
            ],
            'message' => 'Internal integration credentials retrieved.',
            'errors' => null,
        ])->header('Cache-Control', 'no-store, private');
    }
}

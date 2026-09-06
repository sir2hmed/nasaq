<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ProviderProbeClient
{
    /** @return array{success: bool, status: string, message: string} */
    public function probe(string $provider, string $model, array|string $credentials, ?string $baseUrl = null): array
    {
        $url = rtrim((string) config('services.ai_orchestrator.url'), '/');
        $token = config('services.ai_orchestrator.service_token');
        if ($url === '' || ! is_string($token) || $token === '') {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Provider test service is unavailable.'];
        }

        try {
            $response = Http::acceptJson()->asJson()->withHeaders([
                'X-Nasaq-Service-Token' => $token,
            ])->timeout(15)->post("{$url}/internal/providers/probe", [
                'provider' => $provider,
                'model' => $model,
                'credentials' => $credentials,
                'base_url' => $baseUrl,
            ]);
        } catch (\Throwable) {
            return ['success' => false, 'status' => 'unreachable', 'message' => 'Provider test service is unreachable.'];
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Provider test returned an invalid response.'];
        }

        return [
            'success' => $response->successful() && ($data['success'] ?? false) === true,
            'status' => in_array($data['status'] ?? null, ['reachable', 'unreachable', 'authentication_failed', 'quota_or_billing_error', 'rate_limited', 'unsupported', 'unknown'], true) ? $data['status'] : 'unknown',
            'message' => is_string($data['message'] ?? null) ? $data['message'] : 'Provider test completed with an unknown result.',
        ];
    }
}

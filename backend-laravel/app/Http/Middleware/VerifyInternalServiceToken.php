<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalServiceToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.ai_orchestrator.callback_token');
        $provided = $request->header('X-Nasaq-Service-Token');

        if (! is_string($configured) || $configured === '' ||
            ! is_string($provided) || ! hash_equals($configured, $provided)) {
            return new JsonResponse([
                'data' => null,
                'message' => 'Invalid internal service token.',
                'errors' => null,
            ], 401);
        }

        return $next($request);
    }
}

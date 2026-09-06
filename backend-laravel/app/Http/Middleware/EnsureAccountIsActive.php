<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            Auth::guard('web')->logout();

            try {
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            } catch (Throwable $e) {
                // Session store is not bound to request in non-session API contexts
            }

            return response()->json([
                'data' => null,
                'message' => 'Your account has been suspended. Please contact an administrator.',
                'errors' => ['account' => ['Your account has been suspended.']],
            ], 403);
        }

        return $next($request);
    }
}

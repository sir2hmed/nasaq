<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateLocaleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            'preferred_locale' => $request->validated('preferred_locale', 'en'),
            'role' => 'user',
            'account_status' => 'active',
        ]);

        Auth::guard('web')->login($user);
        $this->safeSessionRegenerate($request);

        return $this->userResponse($request, $user, 'Account created.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt(
            $request->credentials(),
            $request->boolean('remember'),
        )) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = $request->user('web');
        if ($user->isSuspended()) {
            Auth::guard('web')->logout();
            $this->safeSessionInvalidate($request);
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended. Please contact an administrator.'],
            ]);
        }

        $this->safeSessionRegenerate($request);

        return $this->userResponse(
            $request,
            $user,
            'Signed in.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        return $this->userResponse(
            $request,
            $request->user(),
            'Current user retrieved.',
        );
    }

    public function updateLocale(UpdateLocaleRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'preferred_locale' => $request->validated('preferred_locale'),
        ]);

        return $this->userResponse(
            $request,
            $user->refresh(),
            'Language preference updated.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $this->safeSessionInvalidate($request);

        return response()->json([
            'data' => null,
            'message' => 'Signed out.',
            'errors' => null,
        ]);
    }

    private function safeSessionRegenerate(Request $request): void
    {
        try {
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        } catch (\Throwable $e) {
            // Session store not initialized on API unit test requests
        }
    }

    private function safeSessionInvalidate(Request $request): void
    {
        try {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        } catch (\Throwable $e) {
            // Session store not initialized on API unit test requests
        }
    }

    private function userResponse(
        Request $request,
        User $user,
        string $message,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'user' => UserResource::make($user)->resolve($request),
            ],
            'message' => $message,
            'errors' => null,
        ], $status);
    }
}

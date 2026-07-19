<?php

namespace App\Http\Controllers;

use App\Enums\ChequeAction;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Session (cookie) login for the same-origin SPA — Sanctum stateful auth.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('username', 'password');

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'username' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'username' => 'This account has been deactivated.',
            ]);
        }

        $request->session()->regenerate();

        $this->logger->log($user, ChequeAction::Login, null, 'Signed in.');

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * The currently authenticated user (used by the SPA to hydrate on load).
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->logger->log($user, ChequeAction::Logout, null, 'Signed out.');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }
}

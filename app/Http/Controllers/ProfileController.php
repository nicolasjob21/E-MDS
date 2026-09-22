<?php

namespace App\Http\Controllers;

use App\Enums\ChequeAction;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogger;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in user's own account: the Profile and Change Password pages behind the user menu.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** Profile: full name and email. Username and role stay as the admin set them. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        $this->logger->log($user, ChequeAction::UpdatedProfile, null, 'Updated their profile.');

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    /**
     * Change Password. The current session stays signed in: Sanctum's AuthenticateSession
     * compares the hash it stored at login with the user's, so the new hash is written back
     * to the session here (what Django's `update_session_auth_hash` does).
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password')])->save();

        if ($request->hasSession()) {
            /** @var SessionGuard $guard */
            $guard = Auth::guard('web');
            $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));
        }

        $this->logger->log($user, ChequeAction::ChangedPassword, null, 'Changed their password.');

        return response()->json(['data' => ['message' => 'Your password has been changed.']]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\ChequeAction;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::orderBy('name')->get());
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        $this->logger->log(
            $request->user(),
            ChequeAction::CreatedUser,
            null,
            "Created user '{$user->username}' ({$user->role->value}).",
        );

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // Don't overwrite the password with a blank when it's left empty on edit.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        $this->logger->log(
            $request->user(),
            ChequeAction::UpdatedUser,
            null,
            "Updated user '{$user->username}'.",
        );

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $username = $user->username;
        $user->delete();

        $this->logger->log(
            $request->user(),
            ChequeAction::DeletedUser,
            null,
            "Deleted user '{$username}'.",
        );

        return response()->json(['data' => ['message' => 'User deleted.']]);
    }
}

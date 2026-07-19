<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * The current user's most recent notifications, plus their unread count for the badge.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->take($request->integer('limit', 15))
            ->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a single notification as read (e.g. when the user opens it).
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $target = $request->user()->notifications()->findOrFail($notification);
        $target->markAsRead();

        return response()->json(['data' => new NotificationResource($target->fresh())]);
    }

    /**
     * Mark every unread notification for the current user as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }
}

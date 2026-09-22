<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /** The signed-in user's dashboard: what is waiting on them, and every register at a glance. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->for($request->user())]);
    }
}

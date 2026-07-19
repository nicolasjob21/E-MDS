<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChequeLogResource;
use App\Models\ChequeLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChequeLogController extends Controller
{
    /**
     * Admin only: the audit log, filterable by user, action and date range.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ChequeLog::query()->latest('created_at')->latest('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('username', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        return ChequeLogResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }
}

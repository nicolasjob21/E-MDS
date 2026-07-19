<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Http\Requests\ApproveUpdateRequestRequest;
use App\Http\Requests\RejectUpdateRequestRequest;
use App\Http\Requests\StoreUpdateRequestRequest;
use App\Http\Resources\ChequeUpdateRequestResource;
use App\Models\Cheque;
use App\Models\ChequeUpdateRequest;
use App\Services\UpdateRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChequeUpdateRequestController extends Controller
{
    public function __construct(private readonly UpdateRequestService $requests) {}

    /**
     * Admin only: list update requests, filterable by status (defaults to pending).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ChequeUpdateRequest::query()
            ->with(['requestedBy', 'reviewedBy', 'cheque.usedBy'])
            ->latest('id');

        $status = $request->query('status', RequestStatus::Pending->value);
        if (in_array($status, array_column(RequestStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        return ChequeUpdateRequestResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }

    /**
     * The update-request history for a single cheque (newest first), with outcomes.
     */
    public function forCheque(Cheque $cheque): AnonymousResourceCollection
    {
        return ChequeUpdateRequestResource::collection(
            $cheque->updateRequests()
                ->with(['requestedBy', 'reviewedBy'])
                ->latest('id')
                ->get()
        );
    }

    /**
     * Staff only: request that a cheque's details be corrected, with a reason.
     */
    public function store(StoreUpdateRequestRequest $request, Cheque $cheque): JsonResponse
    {
        $updateRequest = $this->requests->create(
            $request->user(),
            $cheque,
            [
                'payee_name' => $request->input('payee_name'),
                'amount' => $request->input('amount'),
                'cheque_date' => $request->date('cheque_date'),
            ],
            $request->string('reason')->toString(),
        );

        return response()->json([
            'data' => new ChequeUpdateRequestResource($updateRequest),
        ], 201);
    }

    /**
     * Admin only: approve a pending request, applying the corrected details.
     */
    public function approve(ApproveUpdateRequestRequest $request, ChequeUpdateRequest $updateRequest): JsonResponse
    {
        $updateRequest = $this->requests->approve(
            $request->user(),
            $updateRequest,
            $request->filled('review_note') ? $request->string('review_note')->toString() : null,
        );

        return response()->json(['data' => new ChequeUpdateRequestResource($updateRequest)]);
    }

    /**
     * Admin only: reject a pending request, leaving the cheque unchanged.
     */
    public function reject(RejectUpdateRequestRequest $request, ChequeUpdateRequest $updateRequest): JsonResponse
    {
        $updateRequest = $this->requests->reject(
            $request->user(),
            $updateRequest,
            $request->filled('review_note') ? $request->string('review_note')->toString() : null,
        );

        return response()->json(['data' => new ChequeUpdateRequestResource($updateRequest)]);
    }
}

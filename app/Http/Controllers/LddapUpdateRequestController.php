<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Http\Requests\ApproveUpdateRequestRequest;
use App\Http\Requests\RejectUpdateRequestRequest;
use App\Http\Requests\StoreLddapUpdateRequestRequest;
use App\Http\Requests\UpdateLddapRequest;
use App\Http\Resources\LddapUpdateRequestResource;
use App\Models\Lddap;
use App\Models\LddapUpdateRequest;
use App\Services\LddapUpdateRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LddapUpdateRequestController extends Controller
{
    public function __construct(private readonly LddapUpdateRequestService $requests) {}

    /**
     * Admin only: list LDDAP update requests, filterable by status (defaults to pending).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LddapUpdateRequest::query()
            ->with(['requestedBy', 'reviewedBy', 'lddap.lddapCheck', 'lddap.usedBy'])
            ->latest('id');

        $status = $request->query('status', RequestStatus::Pending->value);
        if (in_array($status, array_column(RequestStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        return LddapUpdateRequestResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }

    /**
     * The update-request history for a single LDDAP (newest first), with outcomes.
     */
    public function forLddap(Lddap $lddap): AnonymousResourceCollection
    {
        return LddapUpdateRequestResource::collection(
            $lddap->updateRequests()
                ->with(['requestedBy', 'reviewedBy'])
                ->latest('id')
                ->get()
        );
    }

    /**
     * Staff only: propose corrected details for an LDDAP, with a reason.
     */
    public function store(StoreLddapUpdateRequestRequest $request, Lddap $lddap): JsonResponse
    {
        $updateRequest = $this->requests->create(
            $request->user(),
            $lddap,
            [
                'lddap_no' => $request->string('lddap_no')->toString(),
                'obj_no' => $request->input('obj_no'),
                'payee_name' => $request->input('payee_name'),
                'amount' => $request->input('amount'),
            ],
            $request->string('reason')->toString(),
        );

        return response()->json(['data' => new LddapUpdateRequestResource($updateRequest)], 201);
    }

    /**
     * Admin only: correct an LDDAP's details directly, taking effect immediately. A reason is
     * required and the change is recorded in the record's correction history.
     */
    public function applyDirect(UpdateLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $record = $this->requests->applyDirect(
            $request->user(),
            $lddap,
            [
                'lddap_no' => $request->string('lddap_no')->toString(),
                'obj_no' => $request->input('obj_no'),
                'payee_name' => $request->input('payee_name'),
                'amount' => $request->input('amount'),
            ],
            $request->string('reason')->toString(),
        );

        return response()->json(['data' => new LddapUpdateRequestResource($record)]);
    }

    /**
     * Admin only: approve a pending request, applying the corrected details.
     */
    public function approve(ApproveUpdateRequestRequest $request, LddapUpdateRequest $updateRequest): JsonResponse
    {
        $updateRequest = $this->requests->approve(
            $request->user(),
            $updateRequest,
            $request->filled('review_note') ? $request->string('review_note')->toString() : null,
        );

        return response()->json(['data' => new LddapUpdateRequestResource($updateRequest)]);
    }

    /**
     * Admin only: reject a pending request, leaving the LDDAP unchanged.
     */
    public function reject(RejectUpdateRequestRequest $request, LddapUpdateRequest $updateRequest): JsonResponse
    {
        $updateRequest = $this->requests->reject(
            $request->user(),
            $updateRequest,
            $request->filled('review_note') ? $request->string('review_note')->toString() : null,
        );

        return response()->json(['data' => new LddapUpdateRequestResource($updateRequest)]);
    }
}

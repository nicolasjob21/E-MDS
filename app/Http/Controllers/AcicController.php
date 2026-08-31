<?php

namespace App\Http\Controllers;

use App\Enums\AcicStatus;
use App\Http\Requests\AddAcicRangeRequest;
use App\Http\Requests\AssignChequesToAcicRequest;
use App\Http\Requests\ForwardAcicRequest;
use App\Http\Requests\ReassignAcicRequest;
use App\Http\Requests\StoreAcicRequest;
use App\Http\Resources\AcicResource;
use App\Http\Resources\ChequeResource;
use App\Models\Acic;
use App\Models\User;
use App\Services\AcicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcicController extends Controller
{
    public function __construct(private readonly AcicService $acics) {}

    /**
     * List ACIC records, newest number first, optionally filtered by status
     * ("all" or any AcicStatus value — the teller table filters on forwarded / completed).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', 'all');

        $query = Acic::query()
            ->with(['usedBy', 'receivedBy', 'completedBy', 'createdBy'])
            ->withCount(['cheques', 'lddaps'])
            ->orderByDesc('acic_number');

        if (AcicStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        // The table's category tabs. This filters on what the ACIC actually carries, not on how
        // it was opened, so an ACIC holding both appears under either.
        match ((string) $request->query('category', 'all')) {
            'cheques' => $query->has('cheques'),
            'lddaps' => $query->has('lddaps'),
            default => null,
        };

        return AcicResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }

    /**
     * One ACIC with the cheques on it.
     */
    public function show(Acic $acic): JsonResponse
    {
        $acic->load(['usedBy', 'receivedBy', 'completedBy', 'createdBy', 'cheques.usedBy', 'lddaps.usedBy', 'lddaps.lddapCheck']);

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /**
     * The number the next ACIC will take — the lowest unused one on file, or null when the
     * series is exhausted.
     */
    public function next(): JsonResponse
    {
        return response()->json(['data' => ['acic_number' => $this->acics->nextNumber()]]);
    }

    /** Admin only: register a block of ACIC numbers. */
    public function addRange(AddAcicRangeRequest $request): JsonResponse
    {
        $result = $this->acics->addRange(
            $request->user(),
            $request->integer('start_at'),
            $request->integer('end_at'),
        );

        return response()->json([
            'data' => $result + [
                'message' => "Registered {$result['count']} ACIC number(s) — #{$result['from']} to #{$result['to']}.",
            ],
        ], 201);
    }

    /** How much of the ACIC series is registered, and how much is still free. */
    public function series(): JsonResponse
    {
        return response()->json(['data' => $this->acics->series()]);
    }

    /**
     * Cheques eligible to be put on an ACIC: approved and not already on one.
     */
    public function linkableCheques(): AnonymousResourceCollection
    {
        return ChequeResource::collection($this->acics->linkableCheques());
    }

    /**
     * Admin/staff: open the next ACIC in the sequence.
     */
    public function store(StoreAcicRequest $request): JsonResponse
    {
        $acic = $this->acics->create($request->user());

        return response()->json(['data' => new AcicResource($acic)], 201);
    }

    /**
     * Admin/staff: put approved cheques on an ACIC ("Use ACIC").
     */
    public function assignCheques(AssignChequesToAcicRequest $request, Acic $acic): JsonResponse
    {
        $acic = $this->acics->assignCheques(
            $request->user(),
            $acic,
            $request->input('cheque_ids', []),
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /**
     * Teller only: mark a forwarded ACIC as completed once the transaction is finished.
     */
    public function complete(Request $request, Acic $acic): JsonResponse
    {
        $acic = $this->acics->complete($request->user(), $acic);

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /**
     * Admin only: forward an ACIC, recording the date and recipient — either a system user or
     * the typed-in name of whoever accepted it.
     */
    /**
     * Sign an ACIC off. Only a Used ACIC can be approved, and only an approved one can be
     * forwarded or printed.
     */
    public function approve(Request $request, Acic $acic): JsonResponse
    {
        return response()->json([
            'data' => new AcicResource($this->acics->approve($request->user(), $acic)),
        ]);
    }

    /**
     * Swap one record on an ACIC for another, releasing the one coming off back to the pool.
     */
    public function reassign(ReassignAcicRequest $request, Acic $acic): JsonResponse
    {
        $acic = $this->acics->reassign(
            $request->user(),
            $acic,
            $request->string('type')->toString(),
            $request->integer('release_id'),
            $request->integer('assign_id'),
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }

    public function forward(ForwardAcicRequest $request, Acic $acic): JsonResponse
    {
        $receivedBy = $request->filled('received_by')
            ? User::findOrFail($request->integer('received_by'))
            : null;

        $acic = $this->acics->forward(
            $request->user(),
            $acic,
            $receivedBy,
            $request->string('received_name')->toString() ?: null,
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }
}

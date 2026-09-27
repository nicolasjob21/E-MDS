<?php

namespace App\Http\Controllers;

use App\Enums\AcicTellerStatus;
use App\Http\Requests\AcceptAcicRequest;
use App\Http\Requests\AddAcicRangeRequest;
use App\Http\Requests\AssignChequesByAcicNoRequest;
use App\Http\Requests\AssignChequesToAcicRequest;
use App\Http\Requests\ForwardAcicToTellerRequest;
use App\Http\Requests\ForwardChequesToPayeeRequest;
use App\Http\Requests\IndexAcicsRequest;
use App\Http\Requests\ReassignAcicRequest;
use App\Http\Requests\ReturnAcicToAdminRequest;
use App\Http\Requests\StoreAcicRequest;
use App\Http\Requests\TellerCompleteAcicRequest;
use App\Http\Requests\TellerForwardAcicRequest;
use App\Http\Requests\TellerQueueRequest;
use App\Http\Requests\TellerRtsAcicRequest;
use App\Http\Resources\AcicResource;
use App\Http\Resources\ChequeResource;
use App\Models\Acic;
use App\Services\AcicService;
use App\Services\AcicTellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcicController extends Controller
{
    public function __construct(
        private readonly AcicService $acics,
        private readonly AcicTellerService $teller,
    ) {}

    /**
     * List ACIC records, newest number first. A teller sees only those forwarded to the
     * tellers; the filters narrow that further. Status is the one the table shows — the
     * teller's, once the ACIC is with the tellers (see Acic::displayStatus()).
     */
    public function index(IndexAcicsRequest $request): AnonymousResourceCollection
    {
        $query = Acic::query()
            ->with(['usedBy', 'receivedBy', 'completedBy', 'createdBy', 'acceptedBy'])
            ->withCount(['cheques', 'lddaps'])
            ->visibleTo($request->user())
            ->when($request->status(), fn ($q, $status) => $q->inDisplayStatus($status))
            ->when($request->searchTerm(), fn ($q, $term) => $q->matching($term))
            ->orderByDesc('acic_number');

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
    public function show(Request $request, Acic $acic): JsonResponse
    {
        // A teller sees an ACIC only once it has been forwarded to the tellers.
        abort_unless($acic->isVisibleTo($request->user()), 404);

        $acic->load(['usedBy', 'receivedBy', 'completedBy', 'createdBy', 'cheques.usedBy', 'cheques.acic', 'cheques.releasedBy', 'lddaps.usedBy', 'lddaps.lddapCheck']);

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
     * Admin/staff: "Assign Cheque to ACIC" by number — the ticked For Signature cheques onto an
     * existing open ACIC or the next one in the series. Many cheques may share one number.
     */
    public function assignChequesByNumber(AssignChequesByAcicNoRequest $request): JsonResponse
    {
        $acic = $this->acics->assignChequesToNumber(
            $request->user(),
            $request->integer('acic_no'),
            $request->input('cheque_ids', []),
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }

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

    // ----------------------------------------------------- the teller's half, ACIC-wide

    /** Admin: send a whole ACIC — cheque or LDDAP — to the tellers. */
    public function forwardToTeller(ForwardAcicToTellerRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->forwardToTeller($request->user(), $acic, $request->validated()));
    }

    /** Teller: claim a Pending ACIC. The first to get here takes it. */
    public function acceptByTeller(AcceptAcicRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->accept($request->user(), $acic, $request->expectedStatus()));
    }

    /** The accepting teller: take it to Land Bank or to the payee. */
    public function tellerForward(TellerForwardAcicRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->forward(
            $request->user(), $acic, $request->validated('to'), $request->expectedStatus(),
        ));
    }

    /** The accepting teller's Action: Completed. */
    public function tellerComplete(TellerCompleteAcicRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->complete($request->user(), $acic, $request->expectedStatus()));
    }

    /** The accepting teller: forward one, several or all cheques to their payees, with who received them. */
    public function forwardChequesToPayee(ForwardChequesToPayeeRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->forwardChequesToPayee(
            $request->user(),
            $acic,
            $request->validated('cheque_ids'),
            trim((string) $request->validated('received_by')),
            (string) $request->validated('date_received'),
            (string) $request->validated('unit'),
            $request->expectedStatus(),
        ));
    }

    /** The accepting teller's Action: RTS, with a reason and a status for each check. */
    public function tellerRts(TellerRtsAcicRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->rts(
            $request->user(), $acic, $request->validated('reason'), $request->outcomes(), $request->expectedStatus(),
        ));
    }

    /** Teller: hand it back to the admin, with a reason. */
    public function returnToAdmin(ReturnAcicToAdminRequest $request, Acic $acic): JsonResponse
    {
        return $this->asResource($this->teller->returnToAdmin(
            $request->user(), $acic, $request->validated('reason'), $request->expectedStatus(),
        ));
    }

    /** An ACIC's teller history, oldest first — every forward and every return it has had. */
    public function history(Request $request, Acic $acic): JsonResponse
    {
        // A teller sees an ACIC — and its history — only once it has been forwarded to the tellers.
        abort_unless($acic->isVisibleTo($request->user()), 404);

        $rows = $acic->history()->with('user:id,name')->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status?->value,
                'from_status_label' => $h->from_status?->label(),
                'to_status' => $h->to_status?->value,
                'to_status_label' => $h->to_status?->label(),
                'action' => $h->action,
                'user' => $h->user?->only(['id', 'name']),
                'details' => $h->details,
                'note' => $h->note,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * The teller dashboard. Every ACIC that has been sent to a teller, in the five lists the
     * page shows — and filterable by type, status and date forwarded.
     */
    public function tellerQueue(TellerQueueRequest $request): JsonResponse
    {
        $user = $request->user();

        $base = fn () => Acic::query()
            ->whereNotNull('forwarded_to_teller_at')
            ->with(AcicTellerService::WITH)
            ->withCount(['cheques', 'lddaps'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('forwarded_to_teller_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('forwarded_to_teller_at', '<=', $request->date('to')))
            // The table's search and status: they only narrow each list below.
            ->when($request->searchTerm(), fn ($q, $term) => $q->matching($term))
            ->when($request->status(), fn ($q, $status) => $q->where('teller_status', $status))
            ->orderByDesc('forwarded_to_teller_at');

        $mine = fn ($q) => $q->where('accepted_by', $user->id);

        return response()->json([
            'data' => [
                // Pending is everyone's; the rest are the viewer's own (an admin sees all).
                'pending' => AcicResource::collection($base()->where('teller_status', AcicTellerStatus::Pending)->get()),
                'accepted' => AcicResource::collection($base()->where('teller_status', AcicTellerStatus::AcceptedByTeller)
                    ->when(! $user->isAdmin(), $mine)->get()),
                // Out with Land Bank or the payee, awaiting the Action.
                'forwarded' => AcicResource::collection($base()
                    ->whereIn('teller_status', [AcicTellerStatus::ForwardedToLandBank, AcicTellerStatus::ForwardedToPayee])
                    ->when(! $user->isAdmin(), $mine)->get()),
                // RTS'd — to forward again or hand back (older ACICs: returned by the bank).
                'rts' => AcicResource::collection($base()
                    ->whereIn('teller_status', [AcicTellerStatus::Rts, AcicTellerStatus::ReturnedByBank])
                    ->when(! $user->isAdmin(), $mine)->get()),
                'completed' => AcicResource::collection($base()->where('teller_status', AcicTellerStatus::Completed)
                    ->when(! $user->isAdmin(), $mine)->get()),
                'bank_name' => AcicTellerService::BANK,
            ],
        ]);
    }

    private function asResource(Acic $acic): JsonResponse
    {
        return response()->json(['data' => new AcicResource($acic->load([...AcicTellerService::WITH, 'usedBy', 'createdBy']))]);
    }
}

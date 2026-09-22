<?php

namespace App\Http\Controllers;

use App\Enums\LddapRoutingAction;
use App\Enums\NatureOfPayment;
use App\Enums\RequestStatus;
use App\Http\Requests\ActOnLddapRequest;
use App\Http\Requests\AddLddapCheckRangeRequest;
use App\Http\Requests\AssignLddapsByAcicNoRequest;
use App\Http\Requests\AssignLddapsToAcicRequest;
use App\Http\Requests\CancelLddapRequest;
use App\Http\Requests\FilterLddapsRequest;
use App\Http\Requests\ForwardLddapRequest;
use App\Http\Requests\LddapDetailsRequest;
use App\Http\Requests\ReceiveLddapRequest;
use App\Http\Requests\RtsLddapRequest;
use App\Http\Resources\AcicResource;
use App\Http\Resources\LddapResource;
use App\Models\Acic;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\LddapEditHistory;
use App\Models\Unit;
use App\Services\LddapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LddapController extends Controller
{
    /** Everything the resource reads through. */
    private const WITH = [
        'lddapCheck', 'usedBy', 'receivedBy', 'reviewedBy', 'acic.receivedBy', 'unit',
        'forwardUnit', 'forwardedBy', 'returnUnit', 'returnedBy', 'canceledBy',
    ];

    public function __construct(private readonly LddapService $lddaps) {}

    /**
     * What the register dialog's selects offer: every nature of payment, and every unit on file.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'natures' => NatureOfPayment::options(),
                'units' => Unit::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * The LDDAP table, highest check number first, filtered by the filter bar's query string
     * (see FilterLddapsRequest) — the filters combine, and the page links carry them.
     */
    public function index(FilterLddapsRequest $request): AnonymousResourceCollection
    {
        $query = Lddap::query()
            ->with(self::WITH)
            // Drives the "on hold" flag on each row.
            ->withCount([
                'updateRequests as pending_update_count' => fn ($q) => $q->where('status', RequestStatus::Pending),
                'routingHistory as rts_count' => fn ($q) => $q->where('action', LddapRoutingAction::Rts),
            ])
            ->when($request->status(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->nature(), fn ($q, $nature) => $q->where('nature_of_payment', $nature))
            ->when($request->search(), fn ($q, $term) => $q->search($term));

        // Ordered by the check number it took — the register's natural order.
        $query->orderByDesc(
            LddapCheck::query()->select('check_no')->whereColumn('lddap_checks.id', 'lddaps.lddap_check_id')
        );

        return LddapResource::collection(
            $query->paginate($request->perPage())->withQueryString()
        );
    }

    /**
     * The next `count` check numbers in the series (the first is what "Add Check Number" will
     * assign), plus how many are left.
     */
    public function nextNumbers(Request $request): JsonResponse
    {
        $count = $request->integer('count', 1);

        return response()->json([
            'data' => [
                'numbers' => $this->lddaps->nextNumbers($count),
                'available' => $this->lddaps->availableCount(),
            ],
        ]);
    }

    /**
     * Counts for the LDDAP check series (registered / available / used).
     */
    public function series(): JsonResponse
    {
        return response()->json(['data' => $this->lddaps->seriesCounts()]);
    }

    /**
     * LDDAPs eligible to go on an ACIC: approved and not already on one.
     */
    public function linkable(): AnonymousResourceCollection
    {
        return LddapResource::collection($this->lddaps->linkable()->load(self::WITH));
    }

    /** Register one LDDAP record. It goes out for routing and takes its check number later. */
    public function store(LddapDetailsRequest $request): JsonResponse
    {
        $lddap = $this->lddaps->register($request->user(), $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))], 201);
    }

    /**
     * Edit a record's details through the same form it was registered with. Registered and RTS
     * records only; the check number is never among the fields.
     */
    public function update(LddapDetailsRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->update($request->user(), $lddap, $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** Every edit the record has had — who, when, and which fields changed — newest first. */
    public function editHistory(Lddap $lddap): JsonResponse
    {
        $history = $lddap->editHistory()->with('user:id,name,username')->latest('id')->get();

        return response()->json([
            'data' => $history->map(fn (LddapEditHistory $entry) => [
                'id' => $entry->id,
                'user' => $entry->user?->only(['id', 'name', 'username']),
                'changes' => $entry->changes,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Mark a registered LDDAP as back from routing, so its check number can be added. */
    /** Forward: Registered → For Out. */
    public function forward(ForwardLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->forward($request->user(), $lddap, $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** Receive: For Out → Returned for ACIC. */
    public function receive(ReceiveLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->receive($request->user(), $lddap, $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** Admin only: Returned for ACIC → Approved. */
    public function approve(ActOnLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->approve($request->user(), $lddap, $request->input('note'));

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** Admin only: Returned for ACIC → RTS, with who received it, the unit, the date and why. */
    public function rts(RtsLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->rts($request->user(), $lddap, $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** Admin only: Returned for ACIC → Canceled, with the date and the required reason. */
    public function cancel(CancelLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->cancel($request->user(), $lddap, $request->validated());

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /** The record's routing trail, oldest first. */
    public function routingHistory(Lddap $lddap): JsonResponse
    {
        return response()->json([
            'data' => $lddap->routingHistory()->with(['user', 'unit'])->get()->map(fn ($h) => [
                'id' => $h->id,
                'action' => $h->action->value,
                'action_label' => $h->action->label(),
                'from_status' => $h->from_status?->value,
                'to_status' => $h->to_status->value,
                'to_status_label' => $h->to_status->label(),
                'user' => $h->user?->only(['id', 'name', 'username']),
                'unit_name' => $h->unit?->name,
                'counterparty' => $h->counterparty,
                'received_by_name' => $h->received_by_name,
                'received_on' => $h->received_on?->toDateString(),
                'acted_on' => $h->acted_on?->toDateString(),
                'note' => $h->note,
                'created_at' => $h->created_at,
            ]),
        ]);
    }

    /**
     * Teller only: confirm an LDDAP has been received.
     */
    public function confirmReceipt(Request $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->confirmReceipt($request->user(), $lddap);

        return response()->json(['data' => new LddapResource($lddap->load(self::WITH))]);
    }

    /**
     * Admin/staff: put approved LDDAPs on an ACIC. Many LDDAPs may share one ACIC number.
     */
    public function assignToAcic(AssignLddapsToAcicRequest $request, Acic $acic): JsonResponse
    {
        $acic = $this->lddaps->assignToAcic(
            $request->user(),
            $acic,
            $request->input('lddap_ids', []),
            $request->input('expected_check_nos'),
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /**
     * "Assign LDDAP to ACIC" from the LDDAP page: the user names the ACIC number. An existing
     * ACIC that still accepts records is used; the next unused number in the ACIC series is
     * opened on the spot; anything else is refused.
     */
    public function assignToAcicByNumber(AssignLddapsByAcicNoRequest $request): JsonResponse
    {
        $acic = $this->lddaps->assignToAcicNumber(
            $request->user(),
            $request->integer('acic_no'),
            $request->input('lddap_ids', []),
            $request->input('expected_check_nos'),
        );

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /**
     * Admin only: register a block of the LDDAP check series.
     */
    public function addRange(AddLddapCheckRangeRequest $request): JsonResponse
    {
        $result = $this->lddaps->addRange(
            $request->user(),
            $request->integer('start_at'),
            $request->integer('end_at'),
        );

        return response()->json([
            'data' => $result + [
                'message' => "Registered LDDAP check numbers {$result['from']}–{$result['to']} ({$result['count']} numbers).",
            ],
        ], 201);
    }
}

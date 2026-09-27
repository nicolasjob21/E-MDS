<?php

namespace App\Http\Controllers;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Http\Requests\AddChequeRangeRequest;
use App\Http\Requests\CheckDraftRequest;
use App\Http\Requests\ChequeExceptionRequest;
use App\Http\Requests\ChequePrepareRequest;
use App\Http\Requests\IndexChequesRequest;
use App\Http\Requests\ReleaseChequeRequest;
use App\Http\Requests\ReplaceChequeRequest;
use App\Http\Requests\SpoilChequeRequest;
use App\Http\Requests\UpdateChequeDetailsRequest;
use App\Http\Requests\UseChequeRequest;
use App\Http\Requests\UsePreviousAcicRequest;
use App\Http\Resources\AcicResource;
use App\Http\Resources\ChequeResource;
use App\Models\Cheque;
use App\Models\User;
use App\Services\AcicService;
use App\Services\ChequeFlowService;
use App\Services\ChequeService;
use App\Services\ChequeSpoilService;
use App\Services\ChequeStaleService;
use App\Support\AmountInWords;
use App\Support\Validity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ChequeController extends Controller
{
    public function __construct(
        private readonly ChequeService $cheques,
        private readonly ChequeFlowService $flow,
    ) {}

    /**
     * List cheques, optionally filtered by any ChequeStatus value (or "all") and by a search
     * term matching the cheque number or the number of the ACIC it sits on, paginated.
     */
    public function index(IndexChequesRequest $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', 'all');

        $query = Cheque::query()
            ->with(['usedBy', 'receivedBy', 'reviewedBy', 'acic', 'forwardedTo', 'releasedBy', 'replacedBy', 'replaces.spoiledFromAcic', 'spoiledBy', 'spoiledFromAcic']);

        if (ChequeStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        // The validity tab. Every one reads through the *effective* disposition, so a cheque
        // that ran out overnight lands under Stale even if the sweep has not run.
        // The tab is either one of the two derived views, or any status in the flow. Every one
        // reads through the *effective* status, so a cheque that ran out overnight lands under
        // Stale even if the sweep has not run.
        match (true) {
            $request->tab() === 'valid' => $query->perishable()
                ->whereDate('validity_until', '>', Validity::today()->addDays(Validity::ALERT_DAYS)->toDateString()),
            $request->tab() === 'expiring' => $query->expiringSoon(),
            ChequeStatus::tryFrom($request->tab()) !== null => $query->effectivelyIn(ChequeStatus::from($request->tab())),
            default => null,
        };

        // Nearest expiry first, with the cheques that have no date at the back.
        $request->sortsByExpiry()
            ? $query->orderByRaw('validity_until IS NULL')->orderBy('validity_until')->orderBy('cheque_number')
            : $query->orderBy('cheque_number');

        if (($search = $request->searchTerm()) !== null) {
            // Escape LIKE wildcards so a literal % or _ can't widen the match, and compare
            // lower-cased: LIKE is case-sensitive on Postgres but not on SQLite, and LOWER()
            // behaves the same on both.
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';

            $query->where(function ($q) use ($term) {
                $q->whereRaw("CAST(cheque_number AS TEXT) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(payee_name) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(account_no) LIKE ? ESCAPE '\\'", [$term]);
            });
        }

        if (($unit = $request->unit()) !== null) {
            $query->where('unit_name', $unit);
        }

        // The cheque date, both ends included.
        if ($request->filled('date_from')) {
            $query->whereDate('cheque_date', '>=', $request->string('date_from')->toString());
        }
        if ($request->filled('date_to')) {
            $query->whereDate('cheque_date', '<=', $request->string('date_to')->toString());
        }

        return ChequeResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }

    // ------------------------------------------------------- the disposition axis

    /**
     * Everything the cheque page needs to render the validity banner and the deposit queue:
     * how many cheques are expiring soon and where they are, and the tellers a cheque can be
     * forwarded to.
     */
    public function validitySummary(Request $request): JsonResponse
    {
        $soon = Cheque::query()->expiringSoon()->get(['status']);
        $count = fn (ChequeStatus ...$s) => $soon->whereIn('status', $s)->count();

        return response()->json([
            'data' => [
                'expiring_soon' => [
                    'total' => $soon->count(),
                    'assigned' => $count(ChequeStatus::Registered, ChequeStatus::ForChecking, ChequeStatus::ForCompliance, ChequeStatus::ForFinalPrint, ChequeStatus::ForSignature),
                    'released' => $count(ChequeStatus::ReleasedToPayee),
                    'for_deposit' => $count(ChequeStatus::ForwardedToTeller, ChequeStatus::AcceptedByTeller),
                ],
                'stale' => Cheque::query()->effectivelyIn(ChequeStatus::Stale)->count(),
                'for_deposit_mine' => Cheque::query()->effectivelyIn(ChequeStatus::AcceptedByTeller)
                    ->when(! $request->user()->isAdmin(),
                        fn ($q) => $q->whereHas('acic', fn ($a) => $a->where('accepted_by', $request->user()->id)))
                    ->count(),
                'tellers' => User::query()
                    ->where('role', UserRole::Teller)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name'])->all(),
                'bank_name' => AcicService::BANK,
                'validity_days' => Validity::DAYS,
                'alert_days' => Validity::ALERT_DAYS,
            ],
        ]);
    }

    /** Print Draft — submit the cheque to the admin in charge for checking. */
    public function printDraft(ChequePrepareRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->printDraft($request->user(), $cheque, $request->expectedStatus()));
    }

    /** Super Admin: the draft is correct — For Final Print. */
    public function approveDraft(CheckDraftRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->approveDraft(
            $request->user(), $cheque, $request->validated('comment'), $request->expectedStatus(),
        ));
    }

    /** Super Admin: the draft is not correct — For Compliance, with what to change. */
    public function returnDraft(CheckDraftRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->returnDraft(
            $request->user(), $cheque, (string) $request->validated('comment'), $request->expectedStatus(),
        ));
    }

    /** The final print came out right — For Signature. */
    public function confirmFinalPrint(ChequePrepareRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->confirmFinalPrint($request->user(), $cheque, $request->expectedStatus()));
    }

    /** Edit the details — before the first draft, or while For Compliance. */
    public function update(UpdateChequeDetailsRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->updateDetails(
            $request->user(), $cheque, $request->safe()->except('expected_status'), $request->expectedStatus(),
        ));
    }

    /** Branch A — hand a cheque on an ACIC to the payee. */
    public function release(ReleaseChequeRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->releaseToPayee(
            $request->user(), $cheque, $request->validated(), $request->expectedStatus(),
        ));
    }

    /** Cancel — final, before the cheque reaches an ACIC. */
    public function cancel(ChequeExceptionRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource($this->flow->cancel(
            $request->user(), $cheque, $request->validated('reason'), $request->expectedStatus(),
        ));
    }

    /**
     * Spoil — final, on an ACIC only. The number stays used, and the payment moves to a
     * replacement on the next available number, which the response carries as `replaced_by`.
     */
    public function spoil(SpoilChequeRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource(app(ChequeSpoilService::class)->spoil(
            $request->user(),
            $cheque,
            $request->validated('reason'),
            $request->expectedStatus(),
            $request->filled('replacement_number') ? $request->integer('replacement_number') : null,
        ));
    }

    /**
     * Admin/staff: "Use previous ACIC" — the replacement of a spoiled cheque takes its place on
     * the ACIC it came off, while that ACIC is still with the admin.
     */
    public function usePreviousAcic(UsePreviousAcicRequest $request, Cheque $cheque): JsonResponse
    {
        $acic = app(ChequeSpoilService::class)->usePreviousAcic($request->user(), $cheque, $request->expectedStatus());

        return response()->json(['data' => new AcicResource($acic)]);
    }

    /** Admin: issue a replacement for a stale cheque, on the next available number. */
    public function replace(ReplaceChequeRequest $request, Cheque $cheque): JsonResponse
    {
        return $this->asResource(
            app(ChequeStaleService::class)->replace($request->user(), $cheque, $request->validated('cheque_date')),
            201,
        );
    }

    /**
     * Every step the cheque has taken, oldest first — starting with the moment it was used.
     *
     * Using a cheque is recorded on the cheque itself (`used_by`, `used_at`), not as a history
     * row, so that first entry is built from those: it is there for every used cheque, however
     * old, and can never disagree with the cheque.
     */
    public function statusHistory(Cheque $cheque): JsonResponse
    {
        $rows = $cheque->statusHistory()->with(['user:id,name', 'acic:id,acic_number'])->orderBy('id')->get();
        $cheque->loadMissing(['usedBy:id,name', 'replaces:id,cheque_number']);

        $used = $cheque->used_at === null ? [] : [[
            'id' => 0,
            'from_status' => ChequeStatus::Available->value,
            'from_status_label' => ChequeStatus::Available->label(),
            'to_status' => ChequeStatus::Registered->value,
            'to_status_label' => ChequeStatus::Registered->label(),
            'action' => 'used',
            'user' => $cheque->usedBy?->only(['id', 'name']),
            'acic_number' => null,
            'details' => null,
            'note' => $cheque->replaces ? "Replaces cheque #{$cheque->replaces->cheque_number}." : null,
            'created_at' => $cheque->used_at->toIso8601String(),
        ]];

        return response()->json([
            'data' => collect($used)->concat($rows->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status?->value,
                'from_status_label' => $h->from_status?->label(),
                'to_status' => $h->to_status->value,
                'to_status_label' => $h->to_status->label(),
                'action' => $h->action,
                'user' => $h->user?->only(['id', 'name']),
                'acic_number' => $h->acic?->acic_number,
                'details' => $h->details,
                'note' => $h->note,
                'created_at' => $h->created_at?->toIso8601String(),
            ]))->values(),
        ]);
    }

    private function asResource(Cheque $cheque, int $status = 200): JsonResponse
    {
        return response()->json(
            ['data' => new ChequeResource($cheque->load(ChequeFlowService::WITH))],
            $status,
        );
    }

    /**
     * Summary counts plus the current next-in-line cheque.
     */
    public function summary(): JsonResponse
    {
        $next = $this->cheques->nextAvailable();

        return response()->json([
            'data' => [
                'counts' => $this->cheques->counts(),
                'next' => $next ? new ChequeResource($next) : null,
            ],
        ]);
    }

    /**
     * The next usable cheque number, or null if the sequence is exhausted.
     */
    public function next(): JsonResponse
    {
        $next = $this->cheques->nextAvailable();

        return response()->json([
            'data' => $next ? new ChequeResource($next) : null,
        ]);
    }

    /**
     * Consume the next cheque. Sequential order + concurrency safety are enforced in the service.
     */
    public function use(UseChequeRequest $request): JsonResponse
    {
        $cheque = $this->cheques->useNext(
            $request->user(),
            $request->integer('cheque_number'),
            [
                'payee_name' => $request->input('payee_name'),
                'account_no' => $request->filled('account_no') ? trim($request->string('account_no')) : null,
                'unit_name' => $request->input('unit_name') ?: null,
                'amount' => $request->input('amount'),
                'cheque_date' => $request->date('cheque_date'),
            ],
        );

        return response()->json(['data' => new ChequeResource($cheque)]);
    }

    /**
     * What the cheque view prints: the cheque's own fields, the amount spelled the cheque way,
     * the account it is drawn on, and its references.
     *
     * Before the draft is approved it prints as a **draft** (`draft: true`, watermarked, no ACIC);
     * from For Final Print on it prints clean. A cancelled, spoiled or stale cheque has no view.
     */
    public function print(Cheque $cheque): JsonResponse
    {
        $status = $cheque->status;

        if (! $status->showsDraft() && ! $status->showsFinal()) {
            throw ValidationException::withMessages([
                'cheque' => "Cheque #{$cheque->cheque_number} is {$status->describe()} — it has no printable view.",
            ]);
        }

        $cheque->load('acic');

        return response()->json([
            'data' => [
                'cheque_number' => $cheque->cheque_number,
                'cheque_date' => $cheque->cheque_date?->toDateString(),
                'payee_name' => $cheque->payee_name,
                'amount' => $cheque->amount,
                'amount_figures' => '₱'.number_format((float) $cheque->amount, 2, '.', ','),
                'amount_in_words' => AmountInWords::cheque($cheque->amount),
                // The account the office's cheques are drawn on.
                'account_no' => config('acic.account_no'),
                'bank_name' => config('acic.bank.name'),
                'bank_branch' => config('acic.bank.branch'),
                'acic_number' => $cheque->acic?->acic_number,
                // A cheque carries no LDDAP number; the slot is here for the reference line.
                'lddap_no' => null,
                // Printed as a draft until the admin in charge approves it.
                'draft' => $status->showsDraft(),
            ],
        ]);
    }

    /**
     * Admin only: register a newly issued cheque book by its printed first/last serial.
     */
    public function addRange(AddChequeRangeRequest $request): JsonResponse
    {
        $result = $this->cheques->addRange(
            $request->user(),
            $request->integer('start_at'),
            $request->integer('end_at'),
        );

        return response()->json([
            'data' => [
                'from' => $result['from'],
                'to' => $result['to'],
                'count' => $result['count'],
                'message' => "Registered cheque numbers {$result['from']}–{$result['to']}.",
            ],
        ], 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Http\Requests\AddLddapCheckRangeRequest;
use App\Http\Requests\AssignLddapsToAcicRequest;
use App\Http\Requests\ReviewLddapRequest;
use App\Http\Requests\UseChequeForLddapsRequest;
use App\Http\Resources\AcicResource;
use App\Http\Resources\LddapResource;
use App\Models\Acic;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Services\LddapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LddapController extends Controller
{
    /** Everything the resource reads through. */
    private const WITH = ['lddapCheck', 'usedBy', 'receivedBy', 'reviewedBy', 'acic.receivedBy'];

    public function __construct(private readonly LddapService $lddaps) {}

    /**
     * The LDDAP table, highest check number first.
     *
     * `status` is "all" or any LddapStatus value. `search` matches the LDDAP number, the OBJ
     * number, the LDDAP check number or the number of the ACIC it sits on.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Lddap::query()
            ->with(self::WITH)
            // Drives the "on hold" flag on each row.
            ->withCount(['updateRequests as pending_update_count' => fn ($q) => $q->where('status', RequestStatus::Pending)]);

        $status = LddapStatus::tryFrom((string) $request->query('status', 'all'));

        if ($status !== null) {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('lddap_no', 'like', "%{$search}%")
                    ->orWhere('obj_no', 'like', "%{$search}%")
                    ->orWhere('payee_name', 'like', "%{$search}%")
                    ->orWhereHas('lddapCheck', fn ($c) => $c->where('check_no', 'like', "%{$search}%"))
                    ->orWhereHas('acic', fn ($a) => $a->where('acic_number', 'like', "%{$search}%"));
            });
        }

        // Ordered by the check number it took — the register's natural order.
        $query->orderByDesc(
            LddapCheck::query()->select('check_no')->whereColumn('lddap_checks.id', 'lddaps.lddap_check_id')
        );

        return LddapResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
        );
    }

    /**
     * The check numbers the next `count` LDDAP rows would take, plus how many are left.
     */
    public function nextNumbers(Request $request): JsonResponse
    {
        $count = $request->integer('count', LddapService::MAX_BATCH);

        return response()->json([
            'data' => [
                'numbers' => $this->lddaps->nextNumbers($count),
                'available' => $this->lddaps->availableCount(),
                'max_batch' => LddapService::MAX_BATCH,
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

    /**
     * Admin/staff: register LDDAPs, each taking the next check number in the series.
     */
    public function useCheque(UseChequeForLddapsRequest $request): JsonResponse
    {
        $lddaps = $this->lddaps->useCheckNumbers(
            $request->user(),
            $request->integer('start_at'),
            $request->input('rows', []),
        );

        return response()->json(
            ['data' => LddapResource::collection($lddaps->load(self::WITH))],
            201,
        );
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
     * Admin only: record the review outcome (approved | compliance | cancelled).
     */
    public function review(ReviewLddapRequest $request, Lddap $lddap): JsonResponse
    {
        $lddap = $this->lddaps->review(
            $request->user(),
            $lddap,
            LddapStatus::from($request->string('status')->toString()),
            $request->input('review_note'),
        );

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

<?php

namespace App\Http\Controllers;

use App\Enums\ChequeStatus;
use App\Http\Requests\AddChequeRangeRequest;
use App\Http\Requests\IndexChequesRequest;
use App\Http\Requests\ReviewChequeRequest;
use App\Http\Requests\UseChequeRequest;
use App\Http\Resources\ChequeResource;
use App\Models\Cheque;
use App\Services\ChequeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChequeController extends Controller
{
    public function __construct(private readonly ChequeService $cheques) {}

    /**
     * List cheques, optionally filtered by any ChequeStatus value (or "all") and by a search
     * term matching the cheque number or the number of the ACIC it sits on, paginated.
     */
    public function index(IndexChequesRequest $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', 'all');

        $query = Cheque::query()
            ->with(['usedBy', 'receivedBy', 'reviewedBy', 'acic'])
            ->withCount(['updateRequests as pending_update_count' => fn ($q) => $q->where('status', 'pending')])
            ->orderBy('cheque_number');

        if (ChequeStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if (($search = $request->searchTerm()) !== null) {
            // Escape LIKE wildcards so a literal % or _ can't widen the match, and compare
            // lower-cased: LIKE is case-sensitive on Postgres but not on SQLite, and LOWER()
            // behaves the same on both.
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';

            $query->where(function ($q) use ($term) {
                $q->whereRaw("CAST(cheque_number AS TEXT) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereHas('acic', fn ($a) => $a->whereRaw("CAST(acic_number AS TEXT) LIKE ? ESCAPE '\\'", [$term]));
            });
        }

        return ChequeResource::collection(
            $query->paginate($request->integer('per_page', 50))->withQueryString()
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
                'amount' => $request->input('amount'),
                'cheque_date' => $request->date('cheque_date'),
            ],
        );

        return response()->json(['data' => new ChequeResource($cheque)]);
    }

    /**
     * Teller only: confirm a used cheque has been received (used -> received).
     */
    public function confirmReceipt(Request $request, Cheque $cheque): JsonResponse
    {
        $cheque = $this->cheques->confirmReceipt($request->user(), $cheque);

        return response()->json(['data' => new ChequeResource($cheque)]);
    }

    /**
     * Admin only: record the review outcome for an issued cheque
     * (approved | complies | disapproved), with a note where one is required.
     */
    public function review(ReviewChequeRequest $request, Cheque $cheque): JsonResponse
    {
        $cheque = $this->cheques->review(
            $request->user(),
            $cheque,
            $request->outcome(),
            $request->filled('review_note') ? $request->string('review_note')->toString() : null,
        );

        return response()->json(['data' => new ChequeResource($cheque)]);
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

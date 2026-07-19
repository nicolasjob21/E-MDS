<?php

namespace App\Http\Controllers;

use App\Enums\ChequeStatus;
use App\Http\Requests\AddChequeRangeRequest;
use App\Http\Requests\CashChequeRequest;
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
     * List cheques, optionally filtered by view (all | available | used | cashed), paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status', 'all');

        $query = Cheque::query()
            ->with('usedBy')
            ->orderBy('cheque_number');

        if (in_array($status, [ChequeStatus::Available->value, ChequeStatus::Used->value], true)) {
            $query->where('status', $status);
        } elseif ($status === 'cashed') {
            $query->whereNotNull('cashed_at');
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
     * Record a used cheque as cashed (received by a bank teller and turned into money).
     */
    public function cash(CashChequeRequest $request, Cheque $cheque): JsonResponse
    {
        $cheque = $this->cheques->cashCheque($request->user(), $cheque, [
            'teller_name' => $request->string('teller_name')->toString(),
            'cashed_at' => $request->date('cashed_at'),
        ]);

        return response()->json(['data' => new ChequeResource($cheque)]);
    }

    /**
     * Admin only: extend the sequence, continuing from the last existing number.
     */
    public function addRange(AddChequeRangeRequest $request): JsonResponse
    {
        $result = $this->cheques->addRange(
            $request->user(),
            $request->integer('count'),
            $request->filled('start_at') ? $request->integer('start_at') : null,
        );

        return response()->json([
            'data' => [
                'from' => $result['from'],
                'to' => $result['to'],
                'count' => $result['count'],
                'message' => "Added cheque numbers {$result['from']}–{$result['to']}.",
            ],
        ], 201);
    }
}

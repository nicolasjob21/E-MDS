<?php

namespace App\Http\Controllers;

use App\Models\Payee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayeeController extends Controller
{
    /**
     * Look registered payees up by name or account number, for the register dialog's search.
     * Capped so a single keystroke never pulls the whole register.
     */
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('search', ''));

        $query = Payee::query()->with('accounts')->orderBy('name');

        if ($term !== '') {
            $query->search($term);
        }

        return response()->json([
            'data' => $query->limit(15)->get()->map(fn (Payee $p) => $this->payload($p)),
        ]);
    }

    /** One payee with its accounts — what "Edit LDDAP Record" pre-fills the payee picker with. */
    public function show(Payee $payee): JsonResponse
    {
        return response()->json(['data' => $this->payload($payee->load('accounts'))]);
    }

    /** @return array<string, mixed> the payee as the picker takes it */
    private function payload(Payee $payee): array
    {
        return [
            'id' => $payee->id,
            'name' => $payee->name,
            'accounts' => $payee->accounts->map(fn ($a) => [
                'id' => $a->id,
                'account_no' => $a->account_no,
                'bank' => $a->bank,
                'label' => $a->label(),
            ])->values(),
        ];
    }
}

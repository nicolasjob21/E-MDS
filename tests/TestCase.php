<?php

namespace Tests;

use App\Enums\AcicNumberStatus;
use App\Models\AcicNumber;
use App\Models\Cheque;
use App\Models\User;
use App\Services\ChequeFlowService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register a block of ACIC numbers for a test to draw on.
     *
     * ACIC numbers are a registered series, so nothing can open an ACIC until some exist. Rows
     * are inserted directly rather than through `AcicService::addRange()` so this does not add a
     * user or an audit entry that a test might then be counting.
     */
    protected function seedAcicSeries(int $from = 1, int $to = 50): void
    {
        $now = now();
        $rows = [];

        for ($number = $from; $number <= $to; $number++) {
            $rows[] = [
                'acic_number' => $number,
                'status' => AcicNumberStatus::Available->value,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AcicNumber::insert($rows);
    }

    /**
     * Walk a freshly used cheque to For Signature — the one status "Assign Cheque to ACIC"
     * takes: its preparer prints a draft, a Super Admin approves it, and the final print is
     * confirmed. The Super Admin is made on first use (and reused), so a test counting users
     * should create its own first.
     */
    protected function readyForAcic(Cheque $cheque, ?User $preparer = null): Cheque
    {
        $flow = app(ChequeFlowService::class);
        $preparer ??= $cheque->usedBy ?? User::factory()->admin()->create();
        $checker = User::query()->where('role', 'super_admin')->first()
            ?? User::factory()->superAdmin()->create(['name' => 'Sue Super']);

        $cheque = $flow->printDraft($preparer, $cheque);
        $cheque = $flow->approveDraft($checker, $cheque);

        return $flow->confirmFinalPrint($preparer, $cheque);
    }
}

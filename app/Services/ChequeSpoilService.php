<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\User;
use App\Support\Validity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spoil a cheque: its number is used up, and the payment moves to a replacement cheque on the
 * next available number.
 *
 * One transaction does it all, or none of it:
 *
 *  1. The cheque must be Approved (the rule Void had), and still what the caller's page showed.
 *  2. The replacement claims the **lowest available** number through `ChequeService::useNext()`
 *     — the same locked, consecutive, never-reused rule as any other cheque. If the caller
 *     previewed a number and someone has taken it since, the whole step is refused.
 *  3. The replacement copies the spoiled cheque's details (payee, account no., unit, amount),
 *     dated today, at the start of the flow.
 *  4. The spoiled cheque keeps its number, records the reason, who and when, and the two are
 *     linked both ways (`replaced_by_id` / `replaces_id`).
 *  5. The spoiled cheque comes off its ACIC, which keeps no record that can never be forwarded;
 *     it remembers the ACIC in `spoiled_from_acic_id`. When the replacement reaches For
 *     Signature it may take the spoiled cheque's place there (usePreviousAcic()) while that
 *     ACIC is still with the admin, or go to a new ACIC the usual way.
 */
class ChequeSpoilService
{
    public function __construct(
        private readonly ChequeFlowService $flow,
        private readonly ChequeService $cheques,
        private readonly AcicService $acics,
    ) {}

    /**
     * @param  int|null  $replacementNumber  the number the caller's dialog showed; refused if it
     *                                       is no longer the next available one
     * @return Cheque the spoiled cheque, with `replacedBy` loaded
     *
     * @throws ValidationException
     */
    public function spoil(User $admin, Cheque $cheque, string $reason, ?string $expected = null, ?int $replacementNumber = null): Cheque
    {
        return DB::transaction(function () use ($admin, $cheque, $reason, $expected, $replacementNumber) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($expected !== null && $expected !== $cheque->status->value) {
                throw ValidationException::withMessages(['cheque' => ChequeFlowService::CONFLICT]);
            }

            if (! $cheque->status->canSpoil()) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is {$cheque->status->label()} — a cheque can only be spoiled while it is Approved.",
                ]);
            }

            $next = $this->cheques->nextAvailable();
            if ($next === null) {
                throw ValidationException::withMessages([
                    'replacement_number' => 'There are no available cheque numbers left for the replacement. Ask an admin to add a new range.',
                ]);
            }

            if ($replacementNumber !== null && $replacementNumber !== $next->cheque_number) {
                throw ValidationException::withMessages([
                    'replacement_number' => "Cheque number {$replacementNumber} is already used. Please refresh and try again.",
                ]);
            }

            try {
                $replacement = $this->cheques->useNext($admin, $next->cheque_number, [
                    'payee_name' => $cheque->payee_name,
                    'account_no' => $cheque->account_no,
                    'unit_name' => $cheque->unit_name,
                    'amount' => $cheque->amount,
                    'cheque_date' => Validity::today()->toDateString(),
                ]);
            } catch (ValidationException) {
                // Taken between the read above and the lock inside useNext().
                throw ValidationException::withMessages([
                    'replacement_number' => "Cheque number {$next->cheque_number} is already used. Please refresh and try again.",
                ]);
            }

            $replacement->update(['replaces_id' => $cheque->id]);

            $acicNumber = $cheque->acic?->acic_number;

            $spoiled = $this->flow->move($admin, $cheque, ChequeStatus::Spoiled, 'spoiled', null,
                fn () => [
                    'exception_reason' => $reason,
                    'spoiled_by' => $admin->id,
                    'spoiled_at' => Carbon::now(),
                    'replaced_by_id' => $replacement->id,
                    // Off the ACIC, but remembered — the replacement may take its place there.
                    'acic_id' => null,
                    'spoiled_from_acic_id' => $cheque->acic_id,
                ],
                "{$reason} — replaced by cheque #{$replacement->cheque_number}"
                    .($acicNumber !== null ? "; taken off ACIC #{$acicNumber}." : '.'));

            $this->flow->tell(
                User::query()->activeAdmins()->get()->push($cheque->usedBy)
                    ->filter(fn (?User $u) => $u !== null && $u->id !== $admin->id),
                'spoiled',
                "Cheque #{$cheque->cheque_number} spoiled",
                "{$admin->name} marked cheque #{$cheque->cheque_number}".($cheque->payee_name ? " ({$cheque->payee_name})" : '')
                    ." as spoiled. The payment moves to cheque #{$replacement->cheque_number}. Reason: {$reason}",
                $cheque->cheque_number,
            );

            return $spoiled->fresh(ChequeFlowService::WITH);
        });
    }

    /**
     * "Use previous ACIC": put a replacement on the ACIC its spoiled cheque came off, in that
     * cheque's place. Only a For Signature replacement with no ACIC yet, and only while that
     * ACIC is still with the admin — otherwise it has to go to a new ACIC.
     *
     * @throws ValidationException
     */
    public function usePreviousAcic(User $user, Cheque $cheque, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($user, $cheque, $expected) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($expected !== null && $expected !== $cheque->status->value) {
                throw ValidationException::withMessages(['cheque' => ChequeFlowService::CONFLICT]);
            }

            $spoiled = $cheque->replaces;

            if ($spoiled === null || $spoiled->status !== ChequeStatus::Spoiled || $spoiled->spoiled_from_acic_id === null) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} does not replace a spoiled cheque that was on an ACIC — assign it to an ACIC the usual way.",
                ]);
            }

            if ($cheque->status !== ChequeStatus::ForSignature || $cheque->acic_id !== null) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is {$cheque->status->describe()} — only a For Signature cheque with no ACIC can be assigned.",
                ]);
            }

            $acic = Acic::query()->whereKey($spoiled->spoiled_from_acic_id)->lockForUpdate()->firstOrFail();

            if (($why = $acic->notWithAdminBecause()) !== null) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} {$why}, so the replacement must go on a new ACIC.",
                ]);
            }

            return $this->acics->assignCheques($user, $acic, [$cheque->id]);
        });
    }
}

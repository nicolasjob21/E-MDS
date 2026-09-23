<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use App\Support\Validity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one move the system makes on its own: a cheque past its 90-day validity becomes Stale,
 * wherever in the flow it was sitting.
 *
 * Kept apart from `ChequeFlowService` because it is not a step anybody takes — the nightly
 * sweep and the guards both reach for it, and it has to be safe to call twice.
 */
class ChequeStaleService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ChequeFlowService $flow,
        private readonly ChequeService $cheques,
    ) {}

    /**
     * Mark a cheque stale.
     *
     * Idempotent: one already stale (or already settled) is returned untouched, with nothing
     * logged and nobody notified, so the sweep can run twice over the same row safely.
     */
    public function markStale(Cheque $cheque, ?User $actor = null, bool $notify = true): Cheque
    {
        return DB::transaction(function () use ($cheque, $actor, $notify) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if (! $cheque->status->isPerishable()) {
                return $cheque->fresh(ChequeFlowService::WITH);
            }

            $stage = $cheque->status->stage();
            $detail = $cheque->status === ChequeStatus::ReleasedToPayee
                ? "released to {$cheque->received_by_name} on ".($cheque->date_received?->toDateString() ?? '—').', not encashed'
                : $stage;

            $cheque = $this->flow->move($actor, $cheque, ChequeStatus::Stale, 'staled', null,
                fn () => ['stale_at' => Carbon::now()],
                'Auto-marked stale by system — exceeded '.Validity::DAYS."-day validity ({$detail}).");

            $this->logger->log($actor, ChequeAction::StaledCheque, $cheque->cheque_number,
                'Auto-marked stale by system — exceeded '.Validity::DAYS."-day validity ({$detail}).");

            if ($notify) {
                $this->flow->tell(
                    User::query()->activeAdmins()->get()->push($cheque->usedBy),
                    'stale',
                    "Cheque #{$cheque->cheque_number} is now stale",
                    "Cheque #{$cheque->cheque_number}".($cheque->payee_name ? " ({$cheque->payee_name})" : '').
                        ' passed its '.Validity::DAYS.'-day validity on '.
                        ($cheque->validity_until?->toDateString() ?? '—')." — {$detail}.",
                    $cheque->cheque_number,
                );
            }

            return $cheque->fresh(ChequeFlowService::WITH);
        });
    }

    /**
     * Issue a replacement for a stale cheque: the next available number, a fresh date and a
     * fresh 90 days. The stale one becomes Replaced, and the two are linked both ways. The old
     * number stays consumed — it is never handed out again.
     *
     * @throws ValidationException
     */
    public function replace(User $admin, Cheque $stale, ?string $chequeDate = null): Cheque
    {
        return DB::transaction(function () use ($admin, $stale, $chequeDate) {
            $stale = Cheque::query()->whereKey($stale->getKey())->lockForUpdate()->firstOrFail();

            if ($stale->replaced_by_id !== null) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$stale->cheque_number} has already been replaced.",
                ]);
            }

            // Read through the effective status: a cheque can be replaced the moment it
            // expires, without waiting for the nightly sweep to write it down.
            if ($stale->effectiveStatus() !== ChequeStatus::Stale) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$stale->cheque_number} is {$stale->effectiveStatus()->label()} — only a stale cheque can be replaced.",
                ]);
            }

            if ($stale->status !== ChequeStatus::Stale) {
                $this->markStale($stale, $admin, notify: false);
                $stale->refresh();
            }

            $date = Carbon::parse(Validity::date($chequeDate ?? Validity::today()->toDateString()));
            $next = $this->cheques->nextAvailable();

            if ($next === null) {
                throw ValidationException::withMessages([
                    'cheque' => 'There are no available cheque numbers left. Ask an admin to add a new range.',
                ]);
            }

            $replacement = $this->cheques->useNext($admin, $next->cheque_number, [
                'payee_name' => $stale->payee_name,
                'amount' => $stale->amount,
                'cheque_date' => $date->toDateString(),
            ]);

            $replacement->update(['replaces_id' => $stale->id]);

            $this->flow->move($admin, $stale, ChequeStatus::Replaced, 'replaced', null,
                fn () => ['replaced_by_id' => $replacement->id],
                "Replaced by cheque #{$replacement->cheque_number}.");

            $this->logger->log($admin, ChequeAction::ReplacedCheque, $stale->cheque_number,
                "Replaced stale cheque #{$stale->cheque_number} with #{$replacement->cheque_number}.");

            return $replacement->fresh(ChequeFlowService::WITH);
        });
    }
}

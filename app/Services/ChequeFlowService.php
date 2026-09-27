<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\ChequeStatusHistory;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\Validity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every step a cheque takes, and everything that can stop it.
 *
 *   (blank) → For Checking → For Final Print → For Signature → Approved ─┬─▶ Released to Payee
 *                  ↕ For Compliance (Return / new draft)                   └─▶ Forwarded to Teller …
 *
 * The ACIC-level half of Branch B lives in `AcicService`, because a whole ACIC is forwarded,
 * claimed and deposited at once. Everything here is per cheque.
 *
 * All of it funnels through `move()`, so no step can forget a check: the row is locked, the
 * status is re-read (and compared against what the caller's page was showing), the transition
 * is one `ChequeStatus` allows, the date is sane, and the step is written to the cheque's own
 * status history.
 */
class ChequeFlowService
{
    public const WITH = ['usedBy', 'receivedBy', 'releasedBy', 'forwardedTo', 'acic', 'replaces.spoiledFromAcic', 'replacedBy', 'spoiledBy', 'spoiledFromAcic'];

    /** What a caller is told when the record moved under them. */
    public const CONFLICT = 'This record was updated by another user. Refresh to continue.';

    public function __construct(private readonly ActivityLogger $logger) {}

    // ------------------------------------------------------------------ the flow

    /**
     * Print Draft — the preparer submits the cheque to the admins in charge (every active
     * Administrator and Super Admin) for checking. From no status, or For Compliance after a Return.
     */
    public function printDraft(User $user, Cheque $cheque, ?string $expected = null): Cheque
    {
        $cheque = $this->move($user, $cheque, ChequeStatus::ForChecking, 'draft_printed', $expected,
            function (Cheque $c) {
                if (! $c->status->canPrintDraft()) {
                    throw ValidationException::withMessages([
                        'cheque' => "Cheque #{$c->cheque_number} is {$c->status->describe()} — a draft is printed only before checking, or after a Return.",
                    ]);
                }

                return [];
            });

        $this->tell(
            User::query()->activeAdmins()->whereKeyNot($user->id)->get(),
            'request',
            "Cheque #{$cheque->cheque_number} — draft for checking",
            "{$user->name} submitted the draft of cheque #{$cheque->cheque_number}"
                .($cheque->payee_name ? " ({$cheque->payee_name})" : '').'. Approve it, or return it with a comment.',
            $cheque->cheque_number,
        );

        return $cheque;
    }

    /** Approve — an admin in charge finds the draft correct. For Checking → For Final Print. */
    public function approveDraft(User $checker, Cheque $cheque, ?string $note = null, ?string $expected = null): Cheque
    {
        $this->assertInCharge($checker);
        $note = $this->text($note);

        $cheque = $this->move($checker, $cheque, ChequeStatus::ForFinalPrint, 'draft_approved', $expected,
            fn () => [], $note);

        $this->tell(
            [$this->preparer($cheque)],
            'approved',
            "Cheque #{$cheque->cheque_number} — draft approved",
            "{$checker->name} approved the draft of cheque #{$cheque->cheque_number}. It is ready for Final Print."
                .($note ? " Comment: {$note}" : ''),
            $cheque->cheque_number,
        );

        return $cheque;
    }

    /**
     * Return — the draft is not correct. The comment (required) says what to change.
     * For Checking → For Compliance.
     */
    public function returnDraft(User $checker, Cheque $cheque, string $comment, ?string $expected = null): Cheque
    {
        $this->assertInCharge($checker);
        $comment = $this->text($comment);

        if ($comment === null) {
            throw ValidationException::withMessages(['comment' => 'Say what needs to change.']);
        }

        $cheque = $this->move($checker, $cheque, ChequeStatus::ForCompliance, 'draft_returned', $expected,
            fn () => [], $comment);

        $this->tell(
            [$this->preparer($cheque)],
            'rejected',
            "Cheque #{$cheque->cheque_number} — draft returned",
            "{$checker->name} returned the draft of cheque #{$cheque->cheque_number}. Update it and print a new draft. Comment: {$comment}",
            $cheque->cheque_number,
        );

        return $cheque;
    }

    /**
     * Final Print, confirmed — the cheque printed successfully. For Final Print → For Signature,
     * from where it may be assigned to an ACIC.
     */
    public function confirmFinalPrint(User $user, Cheque $cheque, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::ForSignature, 'final_printed', $expected, fn () => []);
    }

    /**
     * Edit the cheque's details — only while it has no status yet, or is For Compliance. The
     * number never changes. Each edit is a timeline entry of its own, with what changed.
     *
     * @param  array{payee_name: string, account_no?: string|null, unit_name?: string|null, amount: mixed, cheque_date: string}  $details
     */
    public function updateDetails(User $user, Cheque $cheque, array $details, ?string $expected = null): Cheque
    {
        return DB::transaction(function () use ($user, $cheque, $details, $expected) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($expected !== null && $expected !== $cheque->status->value) {
                throw ValidationException::withMessages(['cheque' => self::CONFLICT]);
            }

            if (! $cheque->effectiveStatus()->isEditable()) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is {$cheque->effectiveStatus()->describe()} — its details can be edited only before its first draft, or while it is For Compliance.",
                ]);
            }

            $after = [
                'payee_name' => trim((string) $details['payee_name']),
                'account_no' => $this->text($details['account_no'] ?? null),
                'unit_name' => $this->text($details['unit_name'] ?? null),
                'amount' => number_format((float) $details['amount'], 2, '.', ''),
                'cheque_date' => Carbon::parse((string) $details['cheque_date'])->toDateString(),
            ];
            $before = [
                'payee_name' => $cheque->payee_name,
                'account_no' => $cheque->account_no,
                'unit_name' => $cheque->unit_name,
                'amount' => $cheque->amount === null ? null : number_format((float) $cheque->amount, 2, '.', ''),
                'cheque_date' => $cheque->cheque_date?->toDateString(),
            ];

            $changes = [];
            foreach ($after as $field => $to) {
                if ((string) $before[$field] !== (string) $to) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $to];
                }
            }

            if ($changes === []) {
                return $cheque->fresh(self::WITH);
            }

            $cheque->update($after);

            ChequeStatusHistory::create([
                'cheque_id' => $cheque->id,
                'from_status' => $cheque->status,
                'to_status' => $cheque->status,
                'action' => 'edited',
                'user_id' => $user->id,
                'acic_id' => $cheque->acic_id,
                'details' => $changes,
                'note' => null,
                'created_at' => Carbon::now(),
            ]);

            $this->logger->log($user, ChequeAction::EditedCheque, $cheque->cheque_number,
                "Edited cheque #{$cheque->cheque_number}: ".implode(', ', array_keys($changes)).'.');

            return $cheque->fresh(self::WITH);
        });
    }

    /**
     * Branch A — hand a cheque on an ACIC to the payee or their representative.
     *
     * @param  array{received_by_name: string, date_received: string, note?: string|null}  $details
     */
    public function releaseToPayee(User $user, Cheque $cheque, array $details, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::ReleasedToPayee, 'released', $expected,
            function (Cheque $c) use ($details, $user) {
                // Belt and braces: the status already implies both, but a cheque without an
                // ACIC number or a cheque number has nothing to hand over.
                if ($c->acic_id === null) {
                    throw ValidationException::withMessages([
                        'cheque' => "Cheque #{$c->cheque_number} is not on an ACIC, so it cannot be released.",
                    ]);
                }

                if ($c->cheque_number === null) {
                    throw ValidationException::withMessages([
                        'cheque' => 'This cheque has no check number, so it cannot be released.',
                    ]);
                }

                $on = $this->date($details['date_received'] ?? null);
                $this->assertDate($on, 'date_received', $c->acic?->used_at, 'the date the ACIC was assigned');

                return [
                    'received_by_name' => trim((string) $details['received_by_name']),
                    'date_received' => $on->toDateString(),
                    'released_by' => $user->id,
                    'released_at' => Carbon::now(),
                    'release_note' => $this->text($details['note'] ?? null),
                ];
            });
    }

    // ------------------------------------------------------------- the ways out

    /** Cancel — final, and only before the cheque reaches an ACIC. */
    public function cancel(User $user, Cheque $cheque, string $reason, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::Cancelled, 'cancelled', $expected,
            function (Cheque $c) use ($reason) {
                if (! $c->status->canCancel()) {
                    throw ValidationException::withMessages([
                        'cheque' => "Cheque #{$c->cheque_number} is {$c->status->describe()} — only a cheque not yet on an ACIC can be cancelled.",
                    ]);
                }

                return ['exception_reason' => $reason];
            }, $reason);
    }

    // -------------------------------------------------------------- the machinery

    /**
     * One step, with every check in one place.
     *
     * @param  callable(Cheque): array<string, mixed>  $fields  the step's own columns, and any
     *                                                          rule only that step knows about
     */
    public function move(
        ?User $user,
        Cheque $cheque,
        ChequeStatus $to,
        string $action,
        ?string $expected,
        callable $fields,
        ?string $note = null,
        ?Acic $acic = null,
    ): Cheque {
        return DB::transaction(function () use ($user, $cheque, $to, $action, $expected, $fields, $note, $acic) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();
            $from = $cheque->status;

            // The page the caller was looking at must still be current.
            if ($expected !== null && $expected !== $from->value) {
                throw ValidationException::withMessages(['cheque' => self::CONFLICT]);
            }

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is {$from->describe()} and cannot be moved to {$to->describe()}.",
                ]);
            }

            $columns = $fields($cheque);

            $cheque->update(['status' => $to] + $columns);

            ChequeStatusHistory::create([
                'cheque_id' => $cheque->id,
                'from_status' => $from,
                'to_status' => $to,
                'action' => $action,
                'user_id' => $user?->id,
                'acic_id' => $acic?->id ?? $cheque->acic_id,
                'details' => $columns === [] ? null : array_map(
                    fn ($v) => $v instanceof \DateTimeInterface ? Carbon::instance($v)->toDateTimeString() : $v,
                    $columns,
                ),
                'note' => $note,
                'created_at' => Carbon::now(),
            ]);

            $this->logger->log($user, self::AUDIT[$action] ?? ChequeAction::ReviewedCheque, $cheque->cheque_number,
                ucfirst(str_replace('_', ' ', $action))." cheque #{$cheque->cheque_number}: ".($from->label() ?: '(no status)').' → '.($to->label() ?: '(no status)').'.'
                    .($note ? " Reason: {$note}" : ''));

            return $cheque->fresh(self::WITH);
        });
    }

    /** Which audit action each step writes. */
    private const AUDIT = [
        'draft_printed' => ChequeAction::PrintedDraft,
        'draft_approved' => ChequeAction::ApprovedDraft,
        'draft_returned' => ChequeAction::ReturnedDraft,
        'final_printed' => ChequeAction::PrintedFinal,
        'released' => ChequeAction::ReleasedCheque,
        'cancelled' => ChequeAction::CancelledCheque,
        'spoiled' => ChequeAction::SpoiledCheque,
        'assigned' => ChequeAction::UsedAcic,
        'unassigned' => ChequeAction::ReassignedAcic,
        'forwarded_to_teller' => ChequeAction::ForwardedChequeToTeller,
        'accepted_by_teller' => ChequeAction::AcceptedByTeller,
        'deposited' => ChequeAction::DepositedCheque,
        'returned_to_admin' => ChequeAction::ReturnedChequeFromTeller,
        'staled' => ChequeAction::StaledCheque,
        'replaced' => ChequeAction::ReplacedCheque,
    ];

    /** @throws ValidationException */
    private function assertDate(Carbon $on, string $field, mixed $notBefore, string $label): void
    {
        if ($on->gt(Validity::today())) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future.']);
        }

        if ($notBefore !== null) {
            $floor = Validity::date($notBefore);

            if ($floor !== null && $on->lt($floor)) {
                throw ValidationException::withMessages([
                    $field => "The date cannot be earlier than {$label} ({$floor->toDateString()}).",
                ]);
            }
        }
    }

    private function date(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages(['date' => 'Enter the date for this step.']);
        }

        return Carbon::parse(Validity::date($value));
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param  iterable<User>  $users */
    public function tell(iterable $users, string $kind, string $title, string $message, ?int $chequeNumber = null, string $url = '/cheques'): void
    {
        $users = collect($users)->filter()->unique('id');

        if ($users->isNotEmpty()) {
            Notification::send($users, new ActivityNotification(
                kind: $kind, title: $title, message: $message, url: $url, chequeNumber: $chequeNumber,
            ));
        }
    }

    /** Only an admin in charge — an Administrator or Super Admin — checks drafts. */
    private function assertInCharge(User $user): void
    {
        if (! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'cheque' => 'Only an Administrator or Super Admin can approve or return a draft.',
            ]);
        }
    }

    /** Whoever printed the latest draft — the one told when it is approved or returned. */
    private function preparer(Cheque $cheque): ?User
    {
        $step = $cheque->statusHistory()
            ->where('action', 'draft_printed')->latest('id')->first();

        return $step?->user ?? $cheque->usedBy;
    }
}

<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\RequestStatus;
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
 *   Registered → Out for Signature → (Received) → For ACIC → Approved ─┬─▶ Released to Payee
 *                                                                      └─▶ Forwarded to Teller …
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
    public const WITH = ['usedBy', 'receivedBy', 'releasedBy', 'forwardedTo', 'acic', 'replaces', 'replacedBy'];

    /** What a caller is told when the record moved under them. */
    public const CONFLICT = 'This record was updated by another user. Refresh to continue.';

    public function __construct(private readonly ActivityLogger $logger) {}

    // ------------------------------------------------------------------ the flow

    /**
     * Step 2 — route a registered cheque out for signature.
     *
     * @param  array{forward_to_name: string, forward_unit_name?: string|null, date_forwarded: string, note?: string|null}  $details
     */
    public function routeForSignature(User $user, Cheque $cheque, array $details, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::OutForSignature, 'routed', $expected,
            function (Cheque $c) use ($details, $user) {
                $on = $this->date($details['date_forwarded'] ?? null);
                $this->assertDate($on, 'date_forwarded', $c->cheque_date, 'the cheque date');

                return [
                    'forward_to_name' => trim((string) $details['forward_to_name']),
                    'forward_unit_name' => $this->text($details['forward_unit_name'] ?? null),
                    'date_forwarded' => $on->toDateString(),
                    'forwarded_by' => $user->id,
                    'forward_note' => $this->text($details['note'] ?? null),
                ];
            });
    }

    /**
     * Step 3 — the signed cheque is back. Recording receipt carries the cheque **straight on to
     * For ACIC**, as the flow requires, so the history shows both moves and the cheque comes to
     * rest where it can be put on an ACIC.
     *
     * @param  array{received_by_name?: string|null, date_received: string, from_unit_name?: string|null, note?: string|null}  $details
     */
    public function markAsReceived(User $user, Cheque $cheque, array $details, ?string $expected = null): Cheque
    {
        return DB::transaction(function () use ($user, $cheque, $details, $expected) {
            $cheque = $this->move($user, $cheque, ChequeStatus::Received, 'received', $expected,
                function (Cheque $c) use ($details, $user) {
                    $on = $this->date($details['date_received'] ?? null);
                    $this->assertDate($on, 'date_received', $c->date_forwarded, 'the date forwarded');

                    return [
                        'received_by_name_in' => $this->text($details['received_by_name'] ?? null) ?? $user->name,
                        'date_received_in' => $on->toDateString(),
                        'from_unit_name' => $this->text($details['from_unit_name'] ?? null),
                        'received_by' => $user->id,
                        'received_at' => Carbon::now(),
                        'review_note' => $this->text($details['note'] ?? null),
                    ];
                });

            // The pass-through: Received never rests.
            return $this->move($user, $cheque, ChequeStatus::ForAcic, 'ready_for_acic', null, fn () => []);
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

    /** RTS — back to Registered, with a reason. Allowed while the cheque is still in the office. */
    public function rts(User $user, Cheque $cheque, string $reason, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::Registered, 'rts', $expected,
            function (Cheque $c) use ($reason) {
                if (! $c->status->canRts()) {
                    throw ValidationException::withMessages([
                        'cheque' => "Cheque #{$c->cheque_number} is {$c->status->label()} and cannot be returned to sender.",
                    ]);
                }

                return [
                    'exception_reason' => $reason,
                    'rts_at' => Carbon::now(),
                    // It is going back out again: the last routing is cleared.
                    'forward_to_name' => null,
                    'forward_unit_name' => null,
                    'date_forwarded' => null,
                    'forwarded_by' => null,
                ];
            }, $reason);
    }

    /** Cancel — final, and only before the cheque reaches an ACIC. */
    public function cancel(User $user, Cheque $cheque, string $reason, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::Cancelled, 'cancelled', $expected,
            fn () => ['exception_reason' => $reason], $reason);
    }

    /** Void — final, on an ACIC only, never once a teller has it. The number stays used. */
    public function void(User $user, Cheque $cheque, string $reason, ?string $expected = null): Cheque
    {
        return $this->move($user, $cheque, ChequeStatus::Voided, 'voided', $expected,
            function (Cheque $c) use ($reason) {
                if (! $c->status->canVoid()) {
                    throw ValidationException::withMessages([
                        'cheque' => "Cheque #{$c->cheque_number} is {$c->status->label()} — a cheque can only be voided while it is Approved.",
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

            // A cheque whose details are in dispute does not move on: nobody signs off on, or
            // hands over, figures an admin has yet to rule on. The system's own steps (going
            // stale, and the exceptions that close a cheque) are not held up by it.
            if (! in_array($action, ['staled', 'cancelled', 'voided', 'rts'], true)
                && $cheque->updateRequests()->where('status', RequestStatus::Pending)->exists()) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is on hold — a detail update request is awaiting approval and must be resolved first.",
                ]);
            }

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is {$from->label()} and cannot be moved to {$to->label()}.",
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
                ucfirst(str_replace('_', ' ', $action))." cheque #{$cheque->cheque_number}: {$from->label()} → {$to->label()}."
                    .($note ? " Reason: {$note}" : ''));

            return $cheque->fresh(self::WITH);
        });
    }

    /** Which audit action each step writes. */
    private const AUDIT = [
        'routed' => ChequeAction::RoutedForSignature,
        'received' => ChequeAction::ReceivedCheque,
        'ready_for_acic' => ChequeAction::ReadyForAcic,
        'released' => ChequeAction::ReleasedCheque,
        'rts' => ChequeAction::RtsCheque,
        'cancelled' => ChequeAction::CancelledCheque,
        'voided' => ChequeAction::VoidedCheque,
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
}

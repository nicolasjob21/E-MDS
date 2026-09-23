<?php

namespace App\Services;

use App\Enums\AcicStatus;
use App\Enums\AcicTellerStatus;
use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\LddapRoutingAction;
use App\Enums\LddapStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\AcicHistory;
use App\Models\Cheque;
use App\Models\Lddap;
use App\Models\User;
use App\Support\Validity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The teller's half of an ACIC's life, for cheque and LDDAP ACICs alike:
 *
 *   Pending → Accepted by Teller → Forwarded to Land Bank → Completed (credited)
 *                                          │
 *                                          └─▶ Returned by Bank ─┬─▶ lodged again
 *                                                                └─▶ back to the admin
 *
 * **The ACIC is the unit of work.** Every step moves the ACIC *and every record on it*, of
 * whichever kind, and writes one history row per record plus one on the ACIC itself. A
 * forward-and-return cycle is never overwritten: each pass adds its own rows.
 */
class AcicTellerService
{
    /** The only bank an ACIC is lodged with. */
    public const BANK = 'Land Bank of the Philippines';

    /** What a caller is told when the ACIC moved under them. */
    public const CONFLICT = 'This record was updated by another user. Refresh to continue.';

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ChequeFlowService $cheques,
    ) {}

    // ------------------------------------------------------------------ 1. the admin sends it

    /**
     * Forward a whole ACIC to the tellers. Every record on it must be ready.
     *
     * @param  array{note?: string|null}  $details
     *
     * @throws ValidationException
     */
    public function forwardToTeller(User $admin, Acic $acic, array $details = []): Acic
    {
        return DB::transaction(function () use ($admin, $acic, $details) {
            $acic = $this->lock($acic);

            if ($acic->teller_status !== null && ! $acic->teller_status->canReturnToAdmin()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} is {$acic->teller_status->label()} and cannot be forwarded again.",
                ]);
            }

            $records = $this->readyRecords($acic);

            $acic->update([
                'teller_status' => AcicTellerStatus::Pending,
                'forwarded_to_teller_by' => $admin->id,
                'forwarded_to_teller_at' => Carbon::now(),
                'forward_note' => $this->text($details['note'] ?? null),
                // A fresh cycle clears the last claim, the last bank trip and the last return.
                'accepted_by' => null, 'accepted_at' => null,
                'forwarded_to_land_bank_at' => null, 'transmittal_no' => null,
                'returned_by_bank_at' => null, 'bank_return_reason' => null,
                'returned_to_admin_by' => null, 'returned_to_admin_at' => null, 'return_reason' => null,
            ]);

            $this->moveRecords($admin, $acic, $records, ChequeStatus::ForwardedToTeller, LddapStatus::ForwardedToTeller, 'forwarded_to_teller');
            $this->trail($acic, null, AcicTellerStatus::Pending, 'forwarded_to_teller', $admin, $details);

            $this->notifyTellers($acic, $admin, $records->count());

            return $acic->fresh(self::WITH);
        });
    }

    // ---------------------------------------------------------------- 2. a teller claims it

    /**
     * **First one wins.** The claim is a conditional write — `accepted_by` is set only where it
     * is still null — so two tellers clicking at the same instant cannot both succeed, whatever
     * the lock timing.
     *
     * @throws ValidationException
     */
    public function accept(User $teller, Acic $acic, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $expected) {
            $acic = $this->lock($acic);

            // Answer the second teller with the name they need, before the generic check.
            if ($acic->accepted_by !== null) {
                $name = $acic->acceptedBy?->name ?? 'another teller';

                throw ValidationException::withMessages(['acic' => "Already accepted by {$name}."]);
            }

            $this->assertStatus($acic, AcicTellerStatus::Pending, $expected);

            $claimed = Acic::query()->whereKey($acic->getKey())->whereNull('accepted_by')
                ->update(['accepted_by' => $teller->id, 'accepted_at' => Carbon::now(),
                    'teller_status' => AcicTellerStatus::AcceptedByTeller->value]);

            if ($claimed === 0) {
                $name = $acic->fresh()->acceptedBy?->name ?? 'another teller';

                throw ValidationException::withMessages(['acic' => "Already accepted by {$name}."]);
            }

            $acic->refresh();
            $this->moveRecords($teller, $acic, $acic->records(), ChequeStatus::AcceptedByTeller, LddapStatus::AcceptedByTeller, 'accepted_by_teller');
            $this->trail($acic, AcicTellerStatus::Pending, AcicTellerStatus::AcceptedByTeller, 'accepted', $teller);

            $this->tell($acic->forwardedToTellerBy, 'approved',
                "ACIC #{$acic->acic_number} accepted",
                "{$teller->name} accepted ACIC #{$acic->acic_number} for deposit.");

            return $acic->fresh(self::WITH);
        });
    }

    // --------------------------------------------------------------- 3. off to the bank

    /**
     * Lodge the ACIC with Land Bank — the first time, or again after the bank sent it back.
     *
     * @param  array{forwarded_at?: string|null, transmittal_no?: string|null, note?: string|null}  $details
     *
     * @throws ValidationException
     */
    public function forwardToLandBank(User $teller, Acic $acic, array $details, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $details, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertAccepter($teller, $acic);
            $this->assertMove($acic, AcicTellerStatus::ForwardedToLandBank, $expected);

            $at = $this->moment($details['forwarded_at'] ?? null);
            // Never before it was claimed, and never in the future.
            $this->assertNotFuture($at, 'forwarded_at');
            $this->assertNotBefore($at, $acic->accepted_at, 'forwarded_at', 'the date and time the ACIC was accepted');

            $acic->update([
                'teller_status' => AcicTellerStatus::ForwardedToLandBank,
                'forwarded_to_land_bank_at' => $at,
                'transmittal_no' => $this->text($details['transmittal_no'] ?? null),
                'land_bank_note' => $this->text($details['note'] ?? null),
                // Lodging it again clears the bank's last return from the ACIC; the records
                // keep their own flags until they are resolved.
                'returned_by_bank_at' => null,
                'bank_return_reason' => null,
            ]);

            $this->moveRecords($teller, $acic, $acic->records(), ChequeStatus::ForwardedToLandBank, LddapStatus::ForwardedToLandBank, 'forwarded_to_land_bank');
            $this->trail($acic, $from, AcicTellerStatus::ForwardedToLandBank,
                $from === AcicTellerStatus::ReturnedByBank ? 're_forwarded_to_land_bank' : 'forwarded_to_land_bank',
                $teller, $details);

            $this->tell($acic->forwardedToTellerBy, 'approved',
                "ACIC #{$acic->acic_number} lodged with ".self::BANK,
                "{$teller->name} forwarded ACIC #{$acic->acic_number} to ".self::BANK." on {$at->toDayDateTimeString()}.");

            return $acic->fresh(self::WITH);
        });
    }

    // ------------------------------------------------------------- 4. the bank sends it back

    /**
     * The bank returned it. The records it actually affected are flagged; the rest are left
     * alone, so what has to be fixed is on the record that needs fixing.
     *
     * @param  array{returned_at?: string|null, reason: string, cheque_ids?: list<int>, lddap_ids?: list<int>}  $details
     *
     * @throws ValidationException
     */
    public function returnedByBank(User $teller, Acic $acic, array $details, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $details, $expected) {
            $acic = $this->lock($acic);
            $this->assertAccepter($teller, $acic);
            $this->assertMove($acic, AcicTellerStatus::ReturnedByBank, $expected);

            $reason = trim((string) ($details['reason'] ?? ''));

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Say why the bank returned this ACIC.']);
            }

            $at = $this->moment($details['returned_at'] ?? null);
            $this->assertNotFuture($at, 'returned_at');
            $this->assertNotBefore($at, $acic->forwarded_to_land_bank_at, 'returned_at', 'the date and time it was forwarded to the bank');

            // Default to every record; a subset may be named instead.
            $affected = $this->affected($acic, $details);

            $acic->update([
                'teller_status' => AcicTellerStatus::ReturnedByBank,
                'returned_by_bank_at' => $at,
                'bank_return_reason' => $reason,
            ]);

            foreach ($affected as $record) {
                $record->forceFill(['returned_by_bank' => true, 'bank_return_note' => $reason])->save();
            }

            $this->moveRecords($teller, $acic, $affected, ChequeStatus::ReturnedByBank, LddapStatus::ReturnedByBank, 'returned_by_bank', $reason);
            $this->trail($acic, AcicTellerStatus::ForwardedToLandBank, AcicTellerStatus::ReturnedByBank, 'returned_by_bank', $teller,
                ['affected' => $affected->count()] + $details, $reason);

            $this->tell($acic->forwardedToTellerBy, 'rejected',
                "ACIC #{$acic->acic_number} returned by the bank",
                "{$teller->name} recorded ".self::BANK." returning ACIC #{$acic->acic_number} ({$affected->count()} record(s)): {$reason}");

            return $acic->fresh(self::WITH);
        });
    }

    // ------------------------------------------------------------------- 5. credited

    /**
     * The bank credited it. Final — nothing may happen to the ACIC or its records after this.
     *
     * `handed_to_bank_at` is when the ACIC actually went over the counter. A teller who
     * lodged it earlier already recorded that, and it stands; one closing the ACIC in a single
     * visit gives it here, so the trip to the bank is never lost just because it was short.
     *
     * @param  array{credited_at?: string|null, handed_to_bank_at?: string|null, bank_confirmation_no: string, note?: string|null}  $details
     *
     * @throws ValidationException
     */
    public function complete(User $teller, Acic $acic, array $details, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $details, $expected) {
            $acic = $this->lock($acic);
            $this->assertAccepter($teller, $acic);
            $from = $acic->teller_status;
            $this->assertMove($acic, AcicTellerStatus::Completed, $expected);

            // A record the bank sent back has to be put right before the ACIC can be closed.
            if ($acic->hasUnresolvedBankReturns()) {
                throw ValidationException::withMessages([
                    'acic' => 'Resolve returned records before completing.',
                ]);
            }

            $reference = trim((string) ($details['bank_confirmation_no'] ?? ''));

            if ($reference === '') {
                throw ValidationException::withMessages([
                    'bank_confirmation_no' => 'Enter the bank confirmation or reference number.',
                ]);
            }

            // When it was handed over: what was already recorded, or what the teller gives now.
            $handed = $acic->forwarded_to_land_bank_at
                ?? $this->moment($details['handed_to_bank_at'] ?? null);

            if ($acic->forwarded_to_land_bank_at === null) {
                $this->assertNotFuture($handed, 'handed_to_bank_at');
                $this->assertNotBefore($handed, $acic->accepted_at, 'handed_to_bank_at', 'the date and time the ACIC was accepted');
            }

            $at = $this->moment($details['credited_at'] ?? null);
            $this->assertNotFuture($at, 'credited_at');
            $this->assertNotBefore($at, $handed, 'credited_at', 'the date and time it was handed to the bank');

            $acic->update([
                'teller_status' => AcicTellerStatus::Completed,
                'forwarded_to_land_bank_at' => $handed,
                'credited_at' => $at,
                'bank_confirmation_no' => $reference,
                'confirmed_by' => $teller->id,
                'completion_note' => $this->text($details['note'] ?? null),
                // The ACIC's own axis is finished with too.
                'status' => AcicStatus::Completed,
                'completed_by' => $teller->id,
                'completed_at' => Carbon::now(),
            ]);

            $this->moveRecords($teller, $acic, $acic->records(), ChequeStatus::Completed, LddapStatus::Completed, 'completed');
            $this->trail($acic, $from, AcicTellerStatus::Completed, 'completed', $teller,
                ['handed_to_bank_at' => $handed->toDateTimeString()] + $details);

            $this->tell($acic->forwardedToTellerBy, 'approved',
                "ACIC #{$acic->acic_number} credited",
                "{$teller->name} confirmed ".self::BANK." credited ACIC #{$acic->acic_number} on {$at->toDayDateTimeString()} (ref {$reference}).");

            return $acic->fresh(self::WITH);
        });
    }

    // --------------------------------------------------------------- 6. back to the admin

    /**
     * The teller hands it back. The teller axis clears, so the admin's next forward starts a
     * fresh Pending cycle and every teller is notified again.
     *
     * @throws ValidationException
     */
    public function returnToAdmin(User $teller, Acic $acic, string $reason, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $reason, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertAccepter($teller, $acic);

            if ($expected !== null && $expected !== $from?->value) {
                throw ValidationException::withMessages(['acic' => self::CONFLICT]);
            }

            if ($from === null || ! $from->canReturnToAdmin()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} is ".($from?->label() ?? 'not with a teller').
                        ' and cannot be returned to the admin.',
                ]);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give the reason for returning this ACIC.']);
            }

            $forwardedBy = $acic->forwardedToTellerBy;
            $records = $acic->records();

            $acic->update([
                'teller_status' => null,
                'accepted_by' => null, 'accepted_at' => null,
                'forwarded_to_teller_by' => null, 'forwarded_to_teller_at' => null,
                'returned_to_admin_by' => $teller->id,
                'returned_to_admin_at' => Carbon::now(),
                'return_reason' => $reason,
            ]);

            // The records go back to where the admin picks them up.
            $this->moveRecords($teller, $acic, $records, ChequeStatus::Approved, LddapStatus::Approved, 'returned_to_admin', $reason);
            $this->trail($acic, $from, null, 'returned_to_admin', $teller, ['reason' => $reason], $reason);

            $this->tell($forwardedBy, 'rejected',
                "ACIC #{$acic->acic_number} returned",
                "{$teller->name} returned ACIC #{$acic->acic_number} without completing it: {$reason}");

            return $acic->fresh(self::WITH);
        });
    }

    // ------------------------------------------------------------------------- the machinery

    /** Everything the teller UI reads through. */
    public const WITH = ['forwardedToTellerBy', 'acceptedBy', 'confirmedBy', 'cheques', 'lddaps'];

    private function lock(Acic $acic): Acic
    {
        return Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Every record on the ACIC, checked ready to go out.
     *
     * @return Collection<int, Cheque|Lddap>
     *
     * @throws ValidationException
     */
    private function readyRecords(Acic $acic): Collection
    {
        $records = $acic->records();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages([
                'acic' => "ACIC #{$acic->acic_number} has no records on it to forward.",
            ]);
        }

        // A cheque already handed to its payee has left the ACIC's hands altogether — worth
        // saying so, rather than lumping it in with the merely unready.
        $released = $records->filter(fn ($r) => $r instanceof Cheque && $r->status === ChequeStatus::ReleasedToPayee);

        if ($released->isNotEmpty()) {
            $numbers = $released->pluck('cheque_number')->sort()->implode(', #');

            throw ValidationException::withMessages([
                'acic' => "ACIC #{$acic->acic_number} cannot be forwarded: cheque(s) #{$numbers} have already been released to the payee.",
            ]);
        }

        $notReady = $records->filter(fn ($r) => $r instanceof Cheque
            ? $r->status !== ChequeStatus::Approved
            : $r->status !== LddapStatus::Approved);

        if ($notReady->isNotEmpty()) {
            $names = $notReady->map(fn ($r) => $r instanceof Cheque ? "#{$r->cheque_number}" : $r->lddap_no)
                ->sort()->implode(', ');

            throw ValidationException::withMessages([
                'acic' => "Every record on the ACIC must be ready before it can be forwarded. Not ready: {$names}.",
            ]);
        }

        return $records;
    }

    /**
     * Which records a bank return affected — all of them unless a subset is named.
     *
     * @param  array<string, mixed>  $details
     * @return Collection<int, Cheque|Lddap>
     */
    private function affected(Acic $acic, array $details): Collection
    {
        $chequeIds = array_map('intval', $details['cheque_ids'] ?? []);
        $lddapIds = array_map('intval', $details['lddap_ids'] ?? []);

        if ($chequeIds === [] && $lddapIds === []) {
            return $acic->records();
        }

        return $acic->records()->filter(fn ($r) => $r instanceof Cheque
            ? in_array($r->id, $chequeIds, true)
            : in_array($r->id, $lddapIds, true))->values();
    }

    /**
     * Move every record with the ACIC, each through its own flow so the step lands in its own
     * history. One kind of record, one status; the caller names both.
     *
     * @param  Collection<int, Cheque|Lddap>  $records
     */
    private function moveRecords(User $user, Acic $acic, Collection $records, ChequeStatus $chequeTo, LddapStatus $lddapTo, string $action, ?string $note = null): void
    {
        foreach ($records as $record) {
            if ($record instanceof Cheque) {
                $this->cheques->move($user, $record, $chequeTo, $action, null, fn () => [], $note, $acic);

                continue;
            }

            // LDDAPs carry the same steps; their trail is the routing history.
            $from = $record->status;
            $record->forceFill(['status' => $lddapTo])->save();
            $record->routingHistory()->create([
                'action' => LddapRoutingAction::Forwarded,
                'from_status' => $from,
                'to_status' => $lddapTo,
                'user_id' => $user->id,
                'counterparty' => self::BANK,
                'acted_on' => Validity::today()->toDateString(),
                'note' => $note ?? "ACIC #{$acic->acic_number}: {$action}",
            ]);
        }
    }

    /** One row on the ACIC's own history, for every step. */
    private function trail(Acic $acic, ?AcicTellerStatus $from, ?AcicTellerStatus $to, string $action, User $user, array $details = [], ?string $note = null): void
    {
        AcicHistory::create([
            'acic_id' => $acic->id,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'user_id' => $user->id,
            'details' => $details === [] ? null : $details,
            'note' => $note,
            'created_at' => Carbon::now(),
        ]);

        $this->logger->log($user, ChequeAction::ForwardedAcic, null,
            "ACIC number {$acic->acic_number}: ".str_replace('_', ' ', $action).
                ($to !== null ? " ({$to->label()})" : '').($note ? " — {$note}" : '').'.');
    }

    /** @throws ValidationException */
    private function assertMove(Acic $acic, AcicTellerStatus $to, ?string $expected): void
    {
        $from = $acic->teller_status;

        if ($expected !== null && $expected !== $from?->value) {
            throw ValidationException::withMessages(['acic' => self::CONFLICT]);
        }

        if ($from === null || ! $from->canMoveTo($to)) {
            throw ValidationException::withMessages([
                'acic' => "ACIC #{$acic->acic_number} is ".($from?->label() ?? 'not with a teller').
                    " and cannot be moved to {$to->label()}.",
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertStatus(Acic $acic, AcicTellerStatus $expectedStatus, ?string $expected): void
    {
        if ($expected !== null && $expected !== $acic->teller_status?->value) {
            throw ValidationException::withMessages(['acic' => self::CONFLICT]);
        }

        if ($acic->teller_status !== $expectedStatus) {
            throw ValidationException::withMessages([
                'acic' => "ACIC #{$acic->acic_number} is ".($acic->teller_status?->label() ?? 'not with a teller').
                    " — only a {$expectedStatus->label()} ACIC can be accepted.",
            ]);
        }
    }

    /** Only the teller who claimed it, or an admin looking in. @throws ValidationException */
    private function assertAccepter(User $user, Acic $acic): void
    {
        if ($user->isAdmin() || $acic->accepted_by === $user->id) {
            return;
        }

        $name = $acic->acceptedBy?->name ?? 'another teller';

        throw ValidationException::withMessages([
            'acic' => "ACIC #{$acic->acic_number} was accepted by {$name}, so only they can act on it.",
        ]);
    }

    /** Dates and times are read and written in Manila, whatever the app's own timezone. */
    private function moment(mixed $value): Carbon
    {
        return $value === null || $value === ''
            ? Carbon::now()
            : Carbon::parse((string) $value, Validity::TZ)->utc();
    }

    /** @throws ValidationException */
    private function assertNotFuture(Carbon $at, string $field): void
    {
        if ($at->gt(Carbon::now())) {
            throw ValidationException::withMessages([$field => 'The date and time cannot be in the future.']);
        }
    }

    /** @throws ValidationException */
    private function assertNotBefore(Carbon $at, mixed $floor, string $field, string $label): void
    {
        if ($floor !== null && $at->lt(Carbon::parse($floor))) {
            throw ValidationException::withMessages([
                $field => "The date and time cannot be earlier than {$label}.",
            ]);
        }
    }

    private function notifyTellers(Acic $acic, User $admin, int $count): void
    {
        $total = $acic->records()->sum(fn ($r) => (float) ($r->amount ?? 0));

        $this->tell(
            User::query()->where('role', UserRole::Teller)->where('is_active', true)->get(),
            'request',
            "ACIC #{$acic->acic_number} forwarded for deposit",
            sprintf(
                'ACIC #%d (%s) · %d record(s) · ₱%s · forwarded by %s. First to accept takes it.',
                $acic->acic_number,
                $acic->type?->label() ?? '—',
                $count,
                number_format($total, 2),
                $admin->name,
            ),
        );
    }

    /** @param  User|iterable<User>|null  $to */
    private function tell(mixed $to, string $kind, string $title, string $message): void
    {
        $this->cheques->tell(
            $to === null ? [] : (is_iterable($to) ? $to : [$to]),
            $kind, $title, $message, null, '/deposit-queue',
        );
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}

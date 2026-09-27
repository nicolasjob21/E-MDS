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

    // ------------------------------------------- 3. forwarded to Land Bank or to the payee

    /** Where the accepting teller may forward an ACIC. */
    public const FORWARD_TO = [
        'land_bank' => AcicTellerStatus::ForwardedToLandBank,
        'payee' => AcicTellerStatus::ForwardedToPayee,
    ];

    /**
     * **Forward** — the accepting teller takes the ACIC to Land Bank or to the payee. From
     * Accepted, or again after an RTS. Records who and when; every record still in play moves
     * with it (after an RTS, only those it Returned).
     *
     * @throws ValidationException
     */
    public function forward(User $teller, Acic $acic, string $to, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $to, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertOnlyAccepter($teller, $acic);

            $status = self::FORWARD_TO[$to] ?? throw ValidationException::withMessages([
                'to' => 'Choose Forward to LBP or Forward to Payee.',
            ]);

            // Never twice: a second click, or a second tab, is refused under the lock.
            if ($from?->isForwarded() === true) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has already been forwarded ".($from === AcicTellerStatus::ForwardedToLandBank ? 'to LBP' : 'to the payee').'.',
                ]);
            }

            $this->assertMove($acic, $status, $expected);

            // An LDDAP ACIC goes to LBP only; the payee route is for cheques.
            if ($to === 'payee' && ! $acic->canGoToPayee()) {
                throw ValidationException::withMessages([
                    'to' => "ACIC #{$acic->acic_number} carries LDDAP records, which are forwarded to LBP only.",
                ]);
            }

            // Cheques go to their payees one or several at a time, with who received them.
            if ($to === 'payee') {
                throw ValidationException::withMessages([
                    'to' => 'Use Forward to Payee to hand cheques over — one, several or all, with who received them.',
                ]);
            }

            // One ACIC is never split between LBP and payees.
            if ($acic->hasChequesWithPayee()) {
                throw ValidationException::withMessages([
                    'to' => "Some cheques on ACIC #{$acic->acic_number} have already been forwarded to their payees, so it cannot go to LBP.",
                ]);
            }

            $records = $this->activeRecords($acic);

            if ($records->isEmpty()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has no checks left to forward.",
                ]);
            }

            $now = Carbon::now();
            $acic->update([
                'teller_status' => $status,
                'teller_forwarded_to' => $to,
                'teller_forwarded_by' => $teller->id,
                'teller_forwarded_at' => $now,
                // Kept for the "To Land Bank" column the tables already show.
                'forwarded_to_land_bank_at' => $to === 'land_bank' ? $now : $acic->forwarded_to_land_bank_at,
                // A fresh trip: the last action is settled.
                'teller_action_by' => null, 'teller_action_at' => null,
            ]);

            $chequeTo = $to === 'land_bank' ? ChequeStatus::ForwardedToLandBank : ChequeStatus::ForwardedToPayee;
            $lddapTo = $to === 'land_bank' ? LddapStatus::ForwardedToLandBank : LddapStatus::ForwardedToPayee;

            foreach ($records as $record) {
                $this->moveRecord($teller, $acic, $record, $chequeTo, $lddapTo, $status->value, null, ['rts_status' => null]);
            }

            $this->trail($acic, $from, $status, $status->value, $teller, ['to' => $to, 'records' => $records->count()]);

            $this->tell($acic->forwardedToTellerBy, 'approved',
                "ACIC #{$acic->acic_number} {$status->label()}",
                "{$teller->name} forwarded ACIC #{$acic->acic_number} ".($to === 'land_bank' ? 'to '.self::BANK : 'to the payee').'.');

            return $acic->fresh(self::WITH);
        });
    }

    // ------------------------------------------------ 3b. cheques forwarded to their payees

    /**
     * **Forward to Payee** — the accepting teller hands one, several or all of a cheque ACIC's
     * cheques to their payees, recording who received them, when, and their unit (the same for
     * the whole batch). Each becomes Forwarded to Payee; stale ones are never offered. When every
     * cheque still in play has gone out, the ACIC itself becomes Forwarded to Payee and gets
     * the Action.
     *
     * @param  list<int>  $chequeIds
     *
     * @throws ValidationException
     */
    public function forwardChequesToPayee(User $teller, Acic $acic, array $chequeIds, string $receivedBy, string $dateReceived, string $unit, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $chequeIds, $receivedBy, $dateReceived, $unit, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertOnlyAccepter($teller, $acic);

            if ($expected !== null && $expected !== $from?->value) {
                throw ValidationException::withMessages(['acic' => self::CONFLICT]);
            }

            if (! $acic->canGoToPayee()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} carries LDDAP records, which are forwarded to LBP only.",
                ]);
            }

            if ($from === null || ! $from->canForward()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} is ".($from?->label() ?? 'not with a teller').' — its cheques cannot be forwarded to their payees now.',
                ]);
            }

            $today = Validity::today()->toDateString();
            $accepted = $acic->accepted_at?->setTimezone(Validity::TZ)->toDateString();

            if ($dateReceived > $today) {
                throw ValidationException::withMessages(['date_received' => 'The date received cannot be in the future.']);
            }

            if ($accepted !== null && $dateReceived < $accepted) {
                throw ValidationException::withMessages(['date_received' => "The date received cannot be before the ACIC was accepted ({$accepted})."]);
            }

            $chequeIds = array_values(array_unique(array_map('intval', $chequeIds)));
            $cheques = $acic->cheques()->whereIn('id', $chequeIds)->lockForUpdate()->get();

            if ($cheques->count() !== count($chequeIds)) {
                throw ValidationException::withMessages(['cheque_ids' => "Only cheques on ACIC #{$acic->acic_number} can be forwarded."]);
            }

            $stale = $cheques->filter(fn (Cheque $c) => $c->effectiveStatus() === ChequeStatus::Stale);
            if ($stale->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'cheque_ids' => 'Stale cheques cannot be forwarded: #'.$stale->pluck('cheque_number')->sort()->implode(', #').'.',
                ]);
            }

            $notReady = $cheques->reject(fn (Cheque $c) => in_array($c->status, self::IN_PLAY_CHEQUE, true));
            if ($notReady->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'cheque_ids' => 'Already forwarded or settled: #'.$notReady->pluck('cheque_number')->sort()->implode(', #').'.',
                ]);
            }

            $now = Carbon::now();
            $note = "Received by {$receivedBy} ({$unit}) on {$dateReceived}.";

            foreach ($cheques as $cheque) {
                $this->moveRecord($teller, $acic, $cheque, ChequeStatus::ForwardedToPayee, LddapStatus::ForwardedToPayee,
                    'forwarded_to_payee', $note, [
                        'received_by_name' => $receivedBy,
                        'date_received' => $dateReceived,
                        'payee_unit_name' => $unit,
                        'released_by' => $teller->id,
                        'released_at' => $now,
                        'rts_status' => null,
                    ]);
            }

            $numbers = $cheques->pluck('cheque_number')->sort()->values()->all();
            $this->trail($acic, $from, $from, 'cheques_forwarded_to_payee', $teller, [
                'cheques' => $numbers, 'received_by' => $receivedBy, 'date_received' => $dateReceived, 'unit' => $unit,
            ], 'Cheque(s) #'.implode(', #', $numbers)." — {$note}");

            // Every cheque still in play is out: the ACIC itself is now Forwarded to Payee.
            if ($acic->chequesLeftForPayee()->isEmpty()) {
                $acic->update([
                    'teller_status' => AcicTellerStatus::ForwardedToPayee,
                    'teller_forwarded_to' => 'payee',
                    'teller_forwarded_by' => $teller->id,
                    'teller_forwarded_at' => $now,
                    'teller_action_by' => null, 'teller_action_at' => null,
                ]);
                $this->trail($acic, $from, AcicTellerStatus::ForwardedToPayee, 'forwarded_to_payee', $teller, ['records' => $acic->cheques()->count()]);

                $this->tell($acic->forwardedToTellerBy, 'approved',
                    "ACIC #{$acic->acic_number} forwarded to payees",
                    "{$teller->name} forwarded every cheque on ACIC #{$acic->acic_number} to its payee.");
            }

            return $acic->fresh(self::WITH);
        });
    }

    /** A cheque still with the teller: accepted, or Returned by an RTS (older: returned by the bank). */
    public const IN_PLAY_CHEQUE = [ChequeStatus::AcceptedByTeller, ChequeStatus::Returned, ChequeStatus::ReturnedByBank];

    // --------------------------------------------------------- 4. the Action: Completed

    /**
     * **Action → Completed.** Closes the ACIC and every check still out. Forwarded to the payee,
     * each check needs who received it and when (`receipts`, keyed "cheque:ID" / "lddap:ID").
     *
     * @param  array<string, array{received_by: string, received_on: string}>  $receipts
     *
     * @throws ValidationException
     */
    public function complete(User $teller, Acic $acic, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertOnlyAccepter($teller, $acic);
            $this->assertMove($acic, AcicTellerStatus::Completed, $expected);

            $records = $this->forwardedRecords($acic);
            $details = ['records' => $records->count()];

            $acic->update([
                'teller_status' => AcicTellerStatus::Completed,
                'status' => AcicStatus::Completed,
                'teller_action_by' => $teller->id,
                'teller_action_at' => Carbon::now(),
                'completed_by' => $teller->id,
                'completed_at' => Carbon::now(),
            ]);

            foreach ($records as $record) {
                $this->moveRecord($teller, $acic, $record, ChequeStatus::Completed, LddapStatus::Completed, 'completed');
            }

            $this->trail($acic, $from, AcicTellerStatus::Completed, 'completed', $teller, $details);

            $this->tell($acic->forwardedToTellerBy, 'approved',
                "ACIC #{$acic->acic_number} completed",
                "{$teller->name} completed ACIC #{$acic->acic_number}.");

            return $acic->fresh(self::WITH);
        });
    }

    // -------------------------------------------------------------- 5. the Action: RTS

    /** What the teller may say became of each check on an RTS. Stale is for cheques only. */
    public const RTS_OUTCOMES = ['completed', 'returned', 'cancelled', 'stale'];

    /**
     * **Action → RTS.** Returned to sender, with a required reason and a status for every check
     * that was out (`outcomes`, keyed "cheque:ID" / "lddap:ID"): Completed, Returned, Cancelled,
     * or — cheques only — Stale. Returned checks go out again with the next Forward.
     *
     * @param  array<string, string>  $outcomes
     *
     * @throws ValidationException
     */
    public function rts(User $teller, Acic $acic, string $reason, array $outcomes, ?string $expected = null): Acic
    {
        return DB::transaction(function () use ($teller, $acic, $reason, $outcomes, $expected) {
            $acic = $this->lock($acic);
            $from = $acic->teller_status;
            $this->assertOnlyAccepter($teller, $acic);
            $this->assertMove($acic, AcicTellerStatus::Rts, $expected);

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give the reason for the RTS.']);
            }

            $records = $this->forwardedRecords($acic);
            $chosen = [];

            foreach ($records as $record) {
                $key = $this->key($record);
                $outcome = $outcomes[$key] ?? null;
                $label = $this->label($record);

                if (! in_array($outcome, self::RTS_OUTCOMES, true)) {
                    throw ValidationException::withMessages(["outcomes.{$key}" => "Choose a status for {$label}."]);
                }

                if ($outcome === 'stale' && ! $record instanceof Cheque) {
                    throw ValidationException::withMessages(["outcomes.{$key}" => "{$label} is an LDDAP and cannot be marked Stale."]);
                }

                $chosen[$key] = $outcome;
            }

            $acic->update([
                'teller_status' => AcicTellerStatus::Rts,
                'rts_reason' => $reason,
                'teller_action_by' => $teller->id,
                'teller_action_at' => Carbon::now(),
            ]);

            foreach ($records as $record) {
                $outcome = $chosen[$this->key($record)];
                [$chequeTo, $lddapTo] = match ($outcome) {
                    'completed' => [ChequeStatus::Completed, LddapStatus::Completed],
                    'returned' => [ChequeStatus::Returned, LddapStatus::Returned],
                    'cancelled' => [ChequeStatus::Cancelled, LddapStatus::Canceled],
                    'stale' => [ChequeStatus::Stale, LddapStatus::Returned],   // cheques only, checked above
                };

                $fields = ['rts_status' => $outcome];

                if ($outcome === 'cancelled') {
                    $fields['exception_reason'] = $reason;
                }

                if ($outcome === 'stale') {
                    $fields['stale_at'] = Carbon::now();
                }

                $this->moveRecord($teller, $acic, $record, $chequeTo, $lddapTo,
                    $outcome === 'cancelled' && $record instanceof Lddap ? 'canceled' : ($outcome === 'completed' ? 'completed' : ($outcome === 'stale' ? 'staled' : 'returned')),
                    "RTS — {$reason}", $fields);
            }

            $this->trail($acic, $from, AcicTellerStatus::Rts, 'rts', $teller, ['reason' => $reason, 'outcomes' => $chosen], $reason);

            $this->tell($acic->forwardedToTellerBy, 'rejected',
                "ACIC #{$acic->acic_number} RTS",
                "{$teller->name} recorded an RTS on ACIC #{$acic->acic_number}: {$reason}");

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

            if ($acic->hasChequesWithPayee()) {
                throw ValidationException::withMessages([
                    'acic' => "Some cheques on ACIC #{$acic->acic_number} have already been forwarded to their payees, so it cannot be returned to the admin.",
                ]);
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
            // Only the checks still in play go back; any an RTS completed or cancelled stay put.
            $records = $this->activeRecords($acic);

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
    public const WITH = ['forwardedToTellerBy', 'acceptedBy', 'confirmedBy', 'tellerForwardedBy', 'tellerActionBy', 'cheques', 'lddaps.lddapCheck'];

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
     * The checks still in play with the teller: accepted, or Returned by an RTS (older ACICs:
     * returned by the bank). Those an RTS completed, cancelled or staled are done.
     *
     * @return Collection<int, Cheque|Lddap>
     */
    private function activeRecords(Acic $acic): Collection
    {
        return $acic->records()->filter(fn ($r) => $r instanceof Cheque
            ? in_array($r->status, [ChequeStatus::AcceptedByTeller, ChequeStatus::Returned, ChequeStatus::ReturnedByBank], true)
            : in_array($r->status, [LddapStatus::AcceptedByTeller, LddapStatus::Returned, LddapStatus::ReturnedByBank], true))->values();
    }

    /**
     * The checks out with Land Bank or the payee — what the Action settles.
     *
     * @return Collection<int, Cheque|Lddap>
     */
    private function forwardedRecords(Acic $acic): Collection
    {
        return $acic->records()->filter(fn ($r) => $r instanceof Cheque
            ? in_array($r->status, [ChequeStatus::ForwardedToLandBank, ChequeStatus::ForwardedToPayee], true)
            : in_array($r->status, [LddapStatus::ForwardedToLandBank, LddapStatus::ForwardedToPayee], true))->values();
    }

    /** "cheque:12" / "lddap:7" — how the Action's per-check fields are keyed. */
    private function key(Cheque|Lddap $record): string
    {
        return ($record instanceof Cheque ? 'cheque:' : 'lddap:').$record->id;
    }

    /** "cheque #10001" / "LDDAP 26-09-00001 (check #2001)" — for messages. */
    private function label(Cheque|Lddap $record): string
    {
        return $record instanceof Cheque
            ? "cheque #{$record->cheque_number}"
            : "LDDAP {$record->lddap_no}".($record->lddapCheck?->check_no ? " (check #{$record->lddapCheck->check_no})" : '');
    }

    /** Only the teller who accepted it — no admin override. @throws ValidationException */
    private function assertOnlyAccepter(User $user, Acic $acic): void
    {
        if ($acic->accepted_by === $user->id) {
            return;
        }

        $name = $acic->acceptedBy?->name ?? 'the teller who accepted it';

        throw ValidationException::withMessages([
            'acic' => "ACIC #{$acic->acic_number} was accepted by {$name}, so only they can forward it or take action on it.",
        ]);
    }

    /**
     * Move one record with its ACIC through its own flow, with any fields of its own.
     *
     * @param  array<string, mixed>  $fields
     */
    private function moveRecord(User $user, Acic $acic, Cheque|Lddap $record, ChequeStatus $chequeTo, LddapStatus $lddapTo, string $action, ?string $note = null, array $fields = []): void
    {
        if ($record instanceof Cheque) {
            $this->cheques->move($user, $record, $chequeTo, $action, null, fn () => $fields, $note, $acic);

            return;
        }

        $from = $record->status;
        $record->forceFill(['status' => $lddapTo] + array_diff_key($fields, ['exception_reason' => true, 'stale_at' => true]))->save();
        $record->routingHistory()->create([
            'action' => LddapRoutingAction::from($action),
            'from_status' => $from,
            'to_status' => $lddapTo,
            'user_id' => $user->id,
            'acted_on' => Validity::today()->toDateString(),
            'note' => "ACIC #{$acic->acic_number}".($note !== null ? " — {$note}" : '.'),
        ]);
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
                'action' => LddapRoutingAction::from($action),
                'from_status' => $from,
                'to_status' => $lddapTo,
                'user_id' => $user->id,
                'acted_on' => Validity::today()->toDateString(),
                'note' => "ACIC #{$acic->acic_number}".($note !== null ? " — {$note}" : '.'),
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

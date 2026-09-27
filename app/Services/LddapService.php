<?php

namespace App\Services;

use App\Enums\AcicType;
use App\Enums\ChequeAction;
use App\Enums\LddapCheckStatus;
use App\Enums\LddapRoutingAction;
use App\Enums\LddapStatus;
use App\Enums\NatureOfPayment;
use App\Enums\RequestStatus;
use App\Models\Acic;
use App\Models\Creditor;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\LddapEditHistory;
use App\Models\LddapRoutingHistory;
use App\Models\PcgPersonnel;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The LDDAP register and its own check-number series.
 *
 * The check series is **independent**: it shares nothing with `cheques.cheque_number` or with
 * `acics.acic_number`. Numbers are registered in ranges and handed out strictly lowest-unused
 * first, so a number is never skipped and never assigned out of order.
 */
class LddapService
{
    /**
     * Highest check number the series accepts. Real LDDAP-ADA numbers run to ten digits, so the
     * column is a big integer; this bound keeps a typo well inside it and fails in validation
     * rather than as a database error.
     */
    public const MAX_CHECK_NO = 999999999999999999;

    /** Everything a returned record is read through. */
    private const WITH = [
        'lddapCheck', 'usedBy', 'receivedBy', 'reviewedBy', 'acic',
        'forwardedBy', 'returnedBy', 'canceledBy',
    ];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly AcicService $acics,
        private readonly LddapCheckAllocator $checks,
    ) {}

    // ------------------------------------------------------------------ the series

    /**
     * Register a block of the LDDAP check series.
     *
     * Ranges need not adjoin — a later block may start well above the last number on file, and
     * the numbers in between simply never existed. What is guaranteed is that **no number is
     * ever registered twice**: the whole range is checked against existing rows inside a locked
     * transaction, so two admins registering overlapping blocks concurrently cannot both win.
     *
     * Registering a block *below* numbers already in use is allowed and is the case the spec
     * calls out: those lower numbers become available, and `nextNumbers()` hands them out
     * before anything higher.
     *
     * @return array{from:int, to:int, count:int}
     *
     * @throws ValidationException
     */
    public function addRange(User $user, int $startAt, int $endAt): array
    {
        if ($endAt < $startAt) {
            throw ValidationException::withMessages([
                'end_at' => 'The last check number must be the same as or higher than the first.',
            ]);
        }

        if ($endAt - $startAt + 1 > 100000) {
            throw ValidationException::withMessages([
                'end_at' => 'That range is too large to register in one go.',
            ]);
        }

        return DB::transaction(function () use ($user, $startAt, $endAt) {
            // Lock the series' tail so a concurrent registration can't slip an overlapping
            // range in between this check and the insert.
            LddapCheck::query()->orderByDesc('check_no')->lockForUpdate()->first();

            $clash = LddapCheck::query()
                ->whereBetween('check_no', [$startAt, $endAt])
                ->orderBy('check_no')
                ->pluck('check_no');

            if ($clash->isNotEmpty()) {
                $first = $clash->first();
                $last = $clash->last();
                $range = $first === $last ? "#{$first}" : "#{$first}–#{$last}";

                throw ValidationException::withMessages([
                    'start_at' => $clash->count() === 1
                        ? "Check number {$range} is already registered. Enter a range that has not been added yet."
                        : "{$clash->count()} numbers in that range are already registered ({$range}). Enter a range that has not been added yet.",
                ]);
            }

            $count = $endAt - $startAt + 1;
            $now = Carbon::now();
            $rows = [];
            for ($number = $startAt; $number <= $endAt; $number++) {
                $rows[] = [
                    'check_no' => $number,
                    'status' => LddapCheckStatus::Available->value,
                    'created_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunk to keep the insert well under Postgres' bind-parameter limit.
            foreach (array_chunk($rows, 1000) as $chunk) {
                LddapCheck::insert($chunk);
            }

            $this->logger->log(
                $user,
                ChequeAction::AddedLddapCheckRange,
                null,
                "Registered LDDAP check series {$startAt}–{$endAt} ({$count} numbers).",
            );

            return ['from' => $startAt, 'to' => $endAt, 'count' => $count];
        });
    }

    /**
     * The block of `$count` **consecutive** unused check numbers a batch of that size would take
     * — the first such run up from the lowest unused number — or an empty list when the series
     * holds no run that long.
     *
     * Read-only preview for the UI — `LddapCheckAllocator::claim()` re-derives the same block
     * under a lock when the records go on an ACIC, so this is never the source of truth.
     *
     * @return list<int>
     */
    public function nextNumbers(int $count): array
    {
        return $this->checks->nextBlock(min(max(1, $count), 500));
    }

    /** How many check numbers are still unused. */
    public function availableCount(): int
    {
        return LddapCheck::query()->where('status', LddapCheckStatus::Available)->count();
    }

    /**
     * Counts for the LDDAP dashboard cards / series panel.
     *
     * @return array{registered:int, available:int, used:int}
     */
    public function seriesCounts(): array
    {
        $rows = LddapCheck::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $available = (int) ($rows[LddapCheckStatus::Available->value] ?? 0);
        $used = (int) ($rows[LddapCheckStatus::Used->value] ?? 0);

        return ['registered' => $available + $used, 'available' => $available, 'used' => $used];
    }

    // ------------------------------------------------------------ the record's life

    /**
     * Register one LDDAP-ADA document.
     *
     * The record is created **without a check number**: it is routed first and takes
     * its number only when it is put on an ACIC — after it has been forwarded, received back
     * and approved ({@see forward()}, {@see receive()}, {@see approve()}, {@see assignToAcic()}).
     * One record per call — there is no batch.
     *
     * The LDDAP number is unique across the register, enforced by `lddaps_lddap_no_unique` and
     * checked here first so the caller gets a plain message rather than a constraint error.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function register(User $user, array $details): Lddap
    {
        $lddapNo = trim((string) ($details['lddap_no'] ?? ''));

        if ($lddapNo === '') {
            throw ValidationException::withMessages(['lddap_no' => 'Enter the LDDAP number.']);
        }

        return DB::transaction(function () use ($user, $details, $lddapNo) {
            $this->assertNumberFree($lddapNo);

            $now = Carbon::now();
            $attributes = $this->attributes($details, $now);
            $this->assertDvFree($attributes['dv_no']);

            $lddap = Lddap::create($attributes + [
                'lddap_check_id' => null,
                // Added straight to For Signature — no status comes before it.
                'status' => LddapStatus::ForSignature,
                'created_by' => $user->id,
                // The staff member who registered it is the one it is returned to, and the one
                // who will add its check number.
                'used_by' => $user->id,
            ]);

            $this->trail($lddap, LddapRoutingAction::Registered, null, LddapStatus::ForSignature, $user, [
                'acted_on' => $now->toDateString(),
            ]);

            $payee = $attributes['payee_name'] ?? null;
            $this->logger->log(
                $user,
                ChequeAction::RegisteredLddap,
                null,
                "Registered LDDAP {$lddapNo}".($payee ? " ({$payee})" : '').'.',
            );

            return $lddap->load(['usedBy', 'payeeAccount']);
        });
    }

    /**
     * Edit a record through the same form it was registered with — "Edit LDDAP Record".
     *
     * Only a **Registered** or **RTS** record may be edited: once it is out for routing,
     * awaiting the admin's action, approved or canceled, its details are fixed (an RTS is how
     * they come back for correction). A pending correction request holds it. The LDDAP number
     * stays unique across the register, but the record's own number is not a duplicate of
     * itself. The check number, status and routing fields are never touched — the check number
     * is only ever set by "Assign LDDAP to ACIC". Every edit that changes something is kept in
     * `lddap_edit_history` with who, when and each field's before and after.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function update(User $user, Lddap $lddap, array $details): Lddap
    {
        $lddapNo = trim((string) ($details['lddap_no'] ?? ''));

        if ($lddapNo === '') {
            throw ValidationException::withMessages(['lddap_no' => 'Enter the LDDAP number.']);
        }

        return DB::transaction(function () use ($user, $lddap, $details, $lddapNo) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            if (! $lddap->status->canEdit()) {
                throw ValidationException::withMessages([
                    'lddap' => "LDDAP {$lddap->lddap_no} is {$lddap->status->label()} and cannot be edited. Only a Registered or RTS record can be.",
                ]);
            }

            $this->assertNotOnHold($lddap, 'edited');
            $this->assertNumberFree($lddapNo, except: $lddap);

            $attributes = $this->attributes($details, Carbon::now());
            $this->assertDvFree($attributes['dv_no'], except: $lddap);
            $before = $lddap->only(array_keys($attributes));

            $lddap->fill($attributes)->save();

            $changes = $this->diff($before, $lddap->only(array_keys($attributes)));

            if ($changes === []) {
                return $lddap->load(self::WITH);
            }

            LddapEditHistory::create([
                'lddap_id' => $lddap->id,
                'user_id' => $user->id,
                'changes' => $changes,
                'created_at' => Carbon::now(),
            ]);

            $this->logger->log(
                $user,
                ChequeAction::UpdatedLddap,
                null,
                "Edited LDDAP {$lddap->lddap_no}: ".implode(', ', array_keys($changes)).'.',
            );

            return $lddap->load(self::WITH);
        });
    }

    /**
     * The LDDAP number must not be on any other record. Checked under a lock on any row of
     * that number, so two saves of the same number cannot both pass and race to the index.
     *
     * @throws ValidationException
     */
    private function assertNumberFree(string $lddapNo, ?Lddap $except = null): void
    {
        $taken = Lddap::query()
            ->where('lddap_no', $lddapNo)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'lddap_no' => "LDDAP number {$lddapNo} has already been registered. Each LDDAP number can be used only once.",
            ]);
        }
    }

    /**
     * The record's details as columns — the one place the form's fields are read, for
     * registering and editing alike: the references, the payee and account (name, number and
     * bank copied onto the record), the money breakdown with the net derived, the dates and
     * the notes. Never the check number, the status or the routing.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(array $details, Carbon $now): array
    {
        // The payee is copied, not linked: its name, which list it came from, and its account
        // number are written onto the record, so later edits to the Creditor or PCG Personnel
        // entry never change a past LDDAP. No payee chosen (an edit that leaves it alone) keeps
        // the one already saved.
        // (A bare `payee_name`, as internal callers and the seeder send, is taken as typed.)
        $payee = match (true) {
            isset($details['payee_type']) => $this->payeeCopy((string) $details['payee_type'], (int) ($details['payee_ref'] ?? 0)),
            isset($details['payee_name']) => ['payee_name' => $this->text($details['payee_name'])],
            default => [],
        };

        // Gross is the claim; the net payable is what is left once every withholding and
        // deduction comes off — and that net is `amount`, what the ACIC prints and totals.
        // Callers that still send a bare `amount` and no gross are taken at their word.
        $money = $this->breakdown($details);

        return [
            'lddap_no' => trim((string) $details['lddap_no']),
            'nca_no' => $this->text($details['nca_no'] ?? null),
            'obr_no' => $this->text($details['obr_no'] ?? null),
            'dv_no' => $this->text($details['dv_no'] ?? null),
            'nature_of_payment' => isset($details['nature_of_payment'])
                ? NatureOfPayment::from((string) $details['nature_of_payment'])
                : null,
            'unit_name' => $this->text($details['unit_name'] ?? null),
            'obj_no' => $this->text($details['obj_no'] ?? null),
            ...$payee,
            'acic_ref' => $this->text($details['acic_ref'] ?? null),
            ...$money,
            // The date of issue, entered with the record; today when a caller omits it.
            'check_date' => isset($details['check_date'])
                ? Carbon::parse((string) $details['check_date'])->toDateString()
                : $now->toDateString(),
            'fwd_to_lbp_at' => isset($details['fwd_to_lbp_at'])
                ? Carbon::parse((string) $details['fwd_to_lbp_at'])->toDateString()
                : null,
            'date_loaded' => isset($details['date_loaded'])
                ? Carbon::parse((string) $details['date_loaded'])->toDateString()
                : null,
            'note' => $this->text($details['note'] ?? null),
            'remarks' => $this->text($details['remarks'] ?? null),
        ];
    }

    /**
     * Which fields an edit changed, each with its before and after — compared as the model
     * presents them (dates as Y-m-d, money to two decimals, enums by value), so a value saved
     * unchanged is not reported as a change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $plain = fn (mixed $v): mixed => match (true) {
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \DateTimeInterface => Carbon::instance($v)->toDateString(),
            default => $v,
        };

        $changes = [];
        foreach ($after as $field => $value) {
            $from = $plain($before[$field] ?? null);
            $to = $plain($value);
            if ((string) $from !== (string) $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    // ---------------------------------------------------------------- routing

    /**
     * Resubmit a corrected RTS record: RTS → For Signature, ready to be assigned to an ACIC.
     * The comment (required) says what was corrected; the notes are optional. Both are kept on
     * the trail.
     *
     * @throws ValidationException
     */
    public function resubmit(User $user, Lddap $lddap, string $comment, ?string $notes = null): Lddap
    {
        $comment = $this->text($comment);

        if ($comment === null) {
            throw ValidationException::withMessages(['comment' => 'Say what was corrected.']);
        }

        return DB::transaction(function () use ($user, $lddap, $comment, $notes) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStep($lddap, $lddap->status->canResubmit(), 'resubmitted', 'Only an RTS record can be resubmitted.');
            $this->assertNotOnHold($lddap, 'resubmitted');

            $lddap->update(['status' => LddapStatus::ForSignature]);

            $this->trail($lddap, LddapRoutingAction::Resubmitted, LddapStatus::Rts, LddapStatus::ForSignature, $user, [
                'acted_on' => Carbon::now()->toDateString(),
                'note' => $comment,
                'notes' => $this->text($notes),
            ]);

            $this->logger->log(
                $user,
                ChequeAction::ResubmittedLddap,
                null,
                "Resubmitted LDDAP {$lddap->lddap_no} — back to For Signature. Comment: {$comment}",
            );

            return $lddap->fresh(self::WITH);
        });
    }

    /**
     * Return to sender: For Signature → RTS. The record can then be corrected and resubmitted. Taken with its own fields — who received it and when, the RTS unit, the RTS date
     * and a required comment — and written as its own history row every time, so a record
     * returned more than once keeps every return.
     *
     * @param  array{received_on: string, received_by: string, unit_name: string, rts_date: string, note: string}  $details
     *
     * @throws ValidationException
     */
    public function rts(User $admin, Lddap $lddap, array $details): Lddap
    {
        $note = $this->text($details['note'] ?? null);

        if ($note === null) {
            throw ValidationException::withMessages([
                'note' => 'Say why the record is being returned to sender.',
            ]);
        }

        return DB::transaction(function () use ($admin, $lddap, $details, $note) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStep(
                $lddap,
                $lddap->status->awaitsAction(),
                'returned to sender',
                'RTS and Cancel are taken on a record that is For Signature.',
            );
            $this->assertNotOnHold($lddap, 'returned to sender');

            $unit = (string) $details['unit_name'];
            $receivedOn = Carbon::parse((string) $details['received_on'])->toDateString();
            $rtsDate = Carbon::parse((string) $details['rts_date'])->toDateString();
            $receivedBy = trim((string) $details['received_by']);

            // Not a verdict: nothing is stamped as reviewed.
            $lddap->update(['status' => LddapStatus::Rts]);

            $this->trail($lddap, LddapRoutingAction::Rts, LddapStatus::ForSignature, LddapStatus::Rts, $admin, [
                'unit_name' => $unit,
                'received_by_name' => $receivedBy,
                'received_on' => $receivedOn,
                'acted_on' => $rtsDate,
                'note' => $note,
            ]);

            $this->logger->log(
                $admin,
                ChequeAction::ReviewedLddap,
                null,
                "LDDAP {$lddap->lddap_no}: returned to sender ({$unit}, {$rtsDate}) by {$admin->name}. Comment: {$note}",
            );

            $lddap->usedBy?->notify(new ActivityNotification(
                kind: 'request',
                title: "LDDAP {$lddap->lddap_no} — Returned to sender",
                message: "{$admin->name} returned LDDAP {$lddap->lddap_no} to you. Correct it and forward it again. Comment: {$note}",
                url: '/lddaps',
            ));

            return $lddap->fresh(self::WITH);
        });
    }

    /**
     * Close a returned LDDAP: Returned for ACIC → Canceled. Read-only from here on — no edit,
     * forward, RTS, approve or ACIC assignment — and its LDDAP number stays used. Who, when and
     * why are recorded on the record and in the trail; the reason is required.
     *
     * @param  array{date_canceled?: string|null, note: string}  $details
     *
     * @throws ValidationException
     */
    public function cancel(User $admin, Lddap $lddap, array $details): Lddap
    {
        $reason = $this->text($details['note'] ?? null);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'note' => 'Give the reason for canceling this record.',
            ]);
        }

        return DB::transaction(function () use ($admin, $lddap, $details, $reason) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStep(
                $lddap,
                $lddap->status->awaitsAction(),
                'canceled',
                'RTS and Cancel are taken on a record that is For Signature.',
            );
            $this->assertNotOnHold($lddap, 'canceled');

            $now = Carbon::now();
            $on = isset($details['date_canceled'])
                ? Carbon::parse((string) $details['date_canceled'])->toDateString()
                : $now->toDateString();

            $lddap->update([
                'status' => LddapStatus::Canceled,
                'canceled_by' => $admin->id,
                'date_canceled' => $on,
                'cancel_reason' => $reason,
                // A verdict, stamped as one.
                'reviewed_by' => $admin->id,
                'reviewed_at' => $now,
                'review_note' => $reason,
            ]);

            $this->trail($lddap, LddapRoutingAction::Canceled, LddapStatus::ForSignature, LddapStatus::Canceled, $admin, [
                'acted_on' => $on,
                'note' => $reason,
            ]);

            $this->logger->log(
                $admin,
                ChequeAction::ReviewedLddap,
                null,
                "LDDAP {$lddap->lddap_no}: canceled by {$admin->name} on {$on}. Reason: {$reason}",
            );

            $lddap->usedBy?->notify(new ActivityNotification(
                kind: 'rejected',
                title: "LDDAP {$lddap->lddap_no} — Canceled",
                message: "{$admin->name} canceled LDDAP {$lddap->lddap_no}. Reason: {$reason}",
                url: '/lddaps',
            ));

            return $lddap->fresh(self::WITH);
        });
    }

    /**
     * Refuse a step the record's status does not allow, naming where it actually is.
     *
     * @throws ValidationException
     */
    private function assertStep(Lddap $lddap, bool $allowed, string $verb, string $rule): void
    {
        if ($allowed) {
            return;
        }

        throw ValidationException::withMessages([
            'lddap' => "LDDAP {$lddap->lddap_no} is {$lddap->status->label()} and cannot be {$verb}. {$rule}",
        ]);
    }

    /**
     * Append one step to the record's routing trail.
     *
     * @param  array{unit_name?: string|null, counterparty?: string|null, received_by_name?: string|null, received_on?: string|null, acted_on?: string|null, note?: string|null, notes?: string|null}  $extra
     */
    private function trail(Lddap $lddap, LddapRoutingAction $action, ?LddapStatus $from, LddapStatus $to, User $user, array $extra = []): void
    {
        LddapRoutingHistory::create([
            'lddap_id' => $lddap->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user->id,
            'unit_name' => $extra['unit_name'] ?? null,
            'counterparty' => $extra['counterparty'] ?? null,
            'received_by_name' => $extra['received_by_name'] ?? null,
            'received_on' => $extra['received_on'] ?? null,
            'acted_on' => $extra['acted_on'] ?? null,
            'note' => $extra['note'] ?? null,
            'notes' => $extra['notes'] ?? null,
        ]);
    }

    // ---------------------------------------------------------- teller receipt

    /**
     * A teller confirms an LDDAP has been received, moving it used -> received.
     *
     * @throws ValidationException
     */
    public function confirmReceipt(User $teller, Lddap $lddap): Lddap
    {
        if ($lddap->received_at !== null) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} has already been confirmed as received.",
            ]);
        }

        // Nothing to receive until the record carries a check number — which it gets when it is
        // put on an ACIC.
        if ($lddap->lddap_check_id === null) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} has no check number yet — there is nothing to receive until it is put on an ACIC.",
            ]);
        }

        $this->assertNotOnHold($lddap, 'received');

        $now = Carbon::now();

        $lddap->update([
            'received_by' => $teller->id,
            'received_at' => $now,
        ]);

        $this->logger->log(
            $teller,
            ChequeAction::ReceivedLddap,
            null,
            "Confirmed receipt of LDDAP {$lddap->lddap_no} (check number {$lddap->lddapCheck?->check_no}).",
        );

        return $lddap->fresh(['lddapCheck', 'usedBy', 'receivedBy', 'acic']);
    }

    // ---------------------------------------------------------------- ACIC linking

    /**
     * LDDAPs eligible to go on an ACIC: approved and not already on one.
     *
     * @return Collection<int, Lddap>
     */
    public function linkable(): Collection
    {
        return Lddap::query()
            ->where('status', LddapStatus::ForSignature)
            ->whereNull('acic_id')
            ->with(['lddapCheck', 'usedBy'])
            // Linkable records have no check number yet, so the order the dialog shows — and
            // hands numbers out in — is registration order.
            ->orderBy('id')
            ->get();
    }

    /**
     * Put For Signature LDDAPs on an ACIC, which makes them Approved. Many may share one ACIC.
     *
     * Everything is validated under a lock: the records must exist, be approved, and not
     * already belong to another ACIC. A partial match fails the whole request — nothing is
     * half-assigned. The LDDAP rows stay in the LDDAP table; only their `acic_id` is filled in.
     *
     * @param  list<int>  $lddapIds
     *
     * @throws ValidationException
     */
    /**
     * Put records on the ACIC with a given **number**, as typed on the LDDAP page.
     *
     * The number is resolved, never invented: an ACIC that exists and still accepts records is
     * used as is; the next unused number in the ACIC series is opened here and used; any other
     * number — one already forwarded, or one that is not the next in line — is refused, so the
     * ACIC series stays lowest-unused-first and a closed ACIC stays closed.
     *
     * @param  list<int>  $lddapIds
     * @param  list<int>|null  $expectedCheckNos
     *
     * @throws ValidationException
     */
    public function assignToAcicNumber(User $user, int $acicNo, array $lddapIds, ?array $expectedCheckNos = null): Acic
    {
        return DB::transaction(function () use ($user, $acicNo, $lddapIds, $expectedCheckNos) {
            $acic = $this->acics->resolveForAssignment($user, $acicNo);

            return $this->assignToAcic($user, $acic, $lddapIds, $expectedCheckNos);
        });
    }

    public function assignToAcic(User $user, Acic $acic, array $lddapIds, ?array $expectedCheckNos = null): Acic
    {
        $lddapIds = array_values(array_unique(array_map('intval', $lddapIds)));

        if ($lddapIds === []) {
            throw ValidationException::withMessages([
                'lddap_ids' => 'Select at least one LDDAP record to put on this ACIC.',
            ]);
        }

        return DB::transaction(function () use ($user, $acic, $lddapIds, $expectedCheckNos) {
            $acic = Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();

            // What the ACIC carries is the clearer refusal, so it goes first: an ACIC holding
            // cheques is not merely "closed", it is the wrong ACIC entirely.
            $this->acics->stampType($acic, AcicType::Lddap);

            if (! $acic->status->acceptsRecords()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has already been forwarded and can no longer be changed.",
                ]);
            }

            // Fetched in the order the caller listed them: that is the order the check numbers
            // are handed out in, and the order the preview showed.
            $position = array_flip($lddapIds);
            $lddaps = Lddap::query()->whereIn('id', $lddapIds)->lockForUpdate()->get()
                ->sortBy(fn (Lddap $l) => $position[$l->id])
                ->values();

            if ($lddaps->count() !== count($lddapIds)) {
                throw ValidationException::withMessages([
                    'lddap_ids' => 'One or more of the selected LDDAP records no longer exists.',
                ]);
            }

            // Only For Signature LDDAPs may go on an ACIC (one already on this ACIC is harmless).
            $notReady = $lddaps->filter(fn (Lddap $l) => ! $l->status->isAcicEligible() && $l->acic_id !== $acic->id);

            if ($notReady->isNotEmpty()) {
                $names = $notReady->pluck('lddap_no')->sort()->implode(', ');

                throw ValidationException::withMessages([
                    'lddap_ids' => "Only For Signature LDDAP records can be linked to an ACIC. Not eligible: {$names}.",
                ]);
            }

            // ...and never twice.
            $taken = $lddaps->filter(
                fn (Lddap $l) => $l->acic_id !== null && $l->acic_id !== $acic->id,
            );

            if ($taken->isNotEmpty()) {
                $names = $taken->pluck('lddap_no')->sort()->implode(', ');

                throw ValidationException::withMessages([
                    'lddap_ids' => "These LDDAP records are already on another ACIC: {$names}.",
                ]);
            }

            // Going on an ACIC is when a record takes its check number — one each, consecutive,
            // in the order shown. Records that already hold one (re-assigned back, or legacy)
            // keep it.
            $this->checks->claim($user, $lddaps, $expectedCheckNos);

            Lddap::query()->whereIn('id', $lddapIds)->update(['acic_id' => $acic->id]);

            // On the ACIC: For Signature → Approved, each a step on its own trail.
            foreach ($lddaps->filter(fn (Lddap $l) => $l->status === LddapStatus::ForSignature) as $lddap) {
                $lddap->forceFill(['status' => LddapStatus::Approved, 'acic_id' => $acic->id])->save();
                $this->trail($lddap, LddapRoutingAction::Assigned, LddapStatus::ForSignature, LddapStatus::Approved, $user, [
                    'acted_on' => Carbon::now()->toDateString(),
                    'note' => "ACIC #{$acic->acic_number}",
                ]);
            }

            // Who put what on the ACIC, and when — with the numbers they were given.
            $summary = $lddaps->map(fn (Lddap $l) => $l->lddap_no.' (check #'.$l->fresh('lddapCheck')->lddapCheck?->check_no.')')
                ->implode(', ');
            $this->acics->markUsed($user, $acic, $lddaps->count(), "LDDAP record(s): {$summary}");

            return $acic->fresh(['usedBy', 'receivedBy', 'createdBy', 'cheques', 'lddaps.lddapCheck']);
        });
    }

    // ------------------------------------------------------------------- internals

    /**
     * An LDDAP with a pending detail-correction request is on hold: nobody signs off on details
     * that are still in dispute. The same rule the cheque register applies.
     *
     * @throws ValidationException
     */
    private function assertNotOnHold(Lddap $lddap, string $action): void
    {
        $onHold = $lddap->updateRequests()
            ->where('status', RequestStatus::Pending)
            ->exists();

        if ($onHold) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} is on hold — a detail update request is awaiting admin approval and must be resolved first, before it can be {$action}.",
            ]);
        }
    }

    /**
     * The payee as the record keeps it — name, type and account number, copied from the
     * Creditors or PCG Personnel entry chosen. The legacy `payees` link is cleared.
     *
     * @return array{payee_name: string, payee_type: string, payee_account_no: string, payee_id: null, payee_account_id: null, payee_bank: null}
     *
     * @throws ValidationException
     */
    private function payeeCopy(string $type, int $ref): array
    {
        $holder = match ($type) {
            'creditor' => Creditor::query()->find($ref),
            'pcg_personnel' => PcgPersonnel::query()->find($ref),
            default => null,
        };

        if ($holder === null) {
            throw ValidationException::withMessages(['payee_ref' => 'Choose a payee from the list.']);
        }

        return [
            'payee_name' => $holder->name,
            'payee_type' => $type,
            'payee_account_no' => $holder->account_no,
            'payee_id' => null,
            'payee_account_id' => null,
            'payee_bank' => null,
        ];
    }

    /**
     * The payee search: every Creditor and PCG Personnel entry in one list, matched on name
     * or account number, name order.
     *
     * @return list<array{type: string, type_label: string, id: int, name: string, account_no: string, unit: string|null}>
     */
    public function payeeOptions(?string $term, int $limit = 100): array
    {
        $rows = collect();
        foreach (['creditor' => Creditor::class, 'pcg_personnel' => PcgPersonnel::class] as $type => $model) {
            $query = $model::query()->orderBy('name')->limit($limit);
            if ($term !== null && trim($term) !== '') {
                $like = '%'.strtolower(trim($term)).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(account_no) like ?', [$like]));
            }
            $rows = $rows->concat($query->get()->map(fn ($h) => [
                'type' => $type,
                'type_label' => $type === 'creditor' ? 'Creditor' : 'PCG Personnel',
                'id' => $h->id,
                'name' => $h->name,
                'account_no' => $h->account_no,
                'unit' => $h->unit,
            ]));
        }

        return $rows->sortBy(fn ($r) => strtolower($r['name']))->take($limit)->values()->all();
    }

    /**
     * The DV Number must not be on any other record — checked under a lock, alongside the
     * form rule and the database's unique index.
     *
     * @throws ValidationException
     */
    private function assertDvFree(?string $dvNo, ?Lddap $except = null): void
    {
        if ($dvNo === null || $dvNo === '') {
            return;
        }

        $taken = Lddap::query()
            ->where('dv_no', $dvNo)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['dv_no' => 'DV Number already exists.']);
        }
    }

    /**
     * The money columns, all as two-decimal strings, with the net payable derived.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function breakdown(array $details): array
    {
        $keys = [
            'wtax_1', 'wtax_2', 'wtax_3', 'wtax_5',
            'vat_1', 'vat_2', 'vat_3', 'vat_5', 'vat_10', 'vat_12', 'vat_30',
            'retention', 'liquidated_damages', 'advance_payment',
        ];

        $money = [];
        foreach ($keys as $key) {
            $money[$key] = Money::of($details[$key] ?? 0);
        }

        // Everything that comes off the gross — summed before gross itself joins the array.
        $withheld = Money::sum($money);

        // A caller with a gross gets the net derived; one with only `amount` (the older shape)
        // is taken as having nothing withheld.
        if (isset($details['gross_amount'])) {
            $money['gross_amount'] = Money::of($details['gross_amount']);
            $money['amount'] = Money::sub($money['gross_amount'], $withheld);

            if (! Money::positive($money['amount'])) {
                throw ValidationException::withMessages([
                    'gross_amount' => 'The taxes and deductions come to more than the gross amount — nothing would be payable.',
                ]);
            }
        } else {
            $money['amount'] = Money::of($details['amount'] ?? 0);
            $money['gross_amount'] = $money['amount'];
        }

        return $money;
    }

    /** Trim a free-text field, turning blank into null. */
    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\LddapCheckStatus;
use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Models\Acic;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
    /** Most LDDAP rows one "Use Check Number" batch may carry. */
    public const MAX_BATCH = 20;

    /**
     * Highest check number the series accepts. Real LDDAP-ADA numbers run to ten digits, so the
     * column is a big integer; this bound keeps a typo well inside it and fails in validation
     * rather than as a database error.
     */
    public const MAX_CHECK_NO = 999999999999999999;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly AcicService $acics,
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
     * The next `$count` check numbers, lowest unused first.
     *
     * Read-only preview for the modal — `useCheckNumbers()` re-derives the same list under a
     * lock, so this is never the source of truth. Fewer numbers than asked for come back when
     * the pool is nearly exhausted; an empty list means there is nothing left to use.
     *
     * @return list<int>
     */
    public function nextNumbers(int $count): array
    {
        return LddapCheck::query()
            ->where('status', LddapCheckStatus::Available)
            ->orderBy('check_no')
            ->limit(max(1, min($count, self::MAX_BATCH)))
            ->pluck('check_no')
            ->map(fn ($n) => (int) $n)
            ->all();
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

    // ------------------------------------------------------------- using the series

    /**
     * Register LDDAP-ADA documents, each taking the next check number in the series.
     *
     * One LDDAP consumes exactly one check number. The rows are matched, in the order given,
     * against the lowest-unused numbers: the numbers are read `FOR UPDATE` so two concurrent
     * batches can never claim the same ones, and the caller must name the number the batch is
     * expected to start at, so a client working from a stale preview is rejected rather than
     * silently skipping ahead.
     *
     * The check date is not asked for: an LDDAP is registered on the day its number is used,
     * so the date is stamped from the clock alongside `used_at`.
     *
     * The batch is all-or-nothing: one bad row fails the whole request.
     *
     * @param  list<array{lddap_no: string, obj_no?: string|null, payee_name?: string|null, amount: mixed}>  $rows
     * @return Collection<int, Lddap>
     *
     * @throws ValidationException
     */
    public function useCheckNumbers(User $user, int $startAt, array $rows): Collection
    {
        $rows = array_values($rows);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => 'Add at least one LDDAP record.',
            ]);
        }

        if (count($rows) > self::MAX_BATCH) {
            throw ValidationException::withMessages([
                'rows' => 'A single batch can carry at most '.self::MAX_BATCH.' LDDAP records.',
            ]);
        }

        $this->rejectDuplicatesWithinBatch($rows);

        return DB::transaction(function () use ($user, $startAt, $rows) {
            $wanted = count($rows);

            // The lowest unused numbers, locked so a concurrent batch cannot take them.
            $checks = LddapCheck::query()
                ->where('status', LddapCheckStatus::Available)
                ->orderBy('check_no')
                ->limit($wanted)
                ->lockForUpdate()
                ->get();

            if ($checks->count() < $wanted) {
                throw ValidationException::withMessages([
                    'rows' => $checks->isEmpty()
                        ? 'There are no unused check numbers left in the LDDAP series. Ask an admin to register a new range.'
                        : "Only {$checks->count()} check number(s) are still unused, but {$wanted} LDDAP record(s) were submitted.",
                ]);
            }

            $first = $checks->first();

            if ($first->check_no !== $startAt) {
                throw ValidationException::withMessages([
                    'start_at' => "Check number {$startAt} is not the next one in line. The next unused number is {$first->check_no}.",
                ]);
            }

            $this->rejectAlreadyRegistered($rows);

            $now = Carbon::now();
            $created = new Collection;

            foreach ($rows as $index => $row) {
                $check = $checks[$index];
                $lddapNo = trim((string) $row['lddap_no']);
                $payee = isset($row['payee_name']) ? trim((string) $row['payee_name']) : null;

                $check->update(['status' => LddapCheckStatus::Used]);

                $created->push(Lddap::create([
                    'lddap_check_id' => $check->id,
                    'lddap_no' => $lddapNo,
                    'obj_no' => isset($row['obj_no']) && trim((string) $row['obj_no']) !== ''
                        ? trim((string) $row['obj_no'])
                        : null,
                    'amount' => $row['amount'],
                    'payee_name' => $payee !== '' ? $payee : null,
                    'check_date' => $now->toDateString(),
                    'status' => LddapStatus::Used,
                    'used_by' => $user->id,
                    'used_at' => $now,
                    'created_by' => $user->id,
                ]));

                $this->logger->log(
                    $user,
                    ChequeAction::UsedLddapCheck,
                    null,
                    "Used LDDAP check number {$check->check_no} for LDDAP {$lddapNo}"
                        .($payee ? " ({$payee})" : '').'.',
                );
            }

            $numbers = $checks->pluck('check_no')->implode(', #');

            // One summary notification for the batch rather than one per number.
            Notification::send(
                User::query()->activeAdmins()->whereKeyNot($user->id)->get(),
                new ActivityNotification(
                    kind: 'used',
                    title: $wanted === 1
                        ? "LDDAP check #{$first->check_no} used"
                        : "{$wanted} LDDAP check numbers used",
                    message: "{$user->name} used LDDAP check number(s) #{$numbers} for {$wanted} LDDAP record(s).",
                    url: '/lddaps',
                ),
            );

            return $created->load(['lddapCheck', 'usedBy', 'acic']);
        });
    }

    // ------------------------------------------------------------------- lifecycle

    /**
     * A teller confirms an LDDAP has been received, moving it used -> received.
     *
     * @throws ValidationException
     */
    public function confirmReceipt(User $teller, Lddap $lddap): Lddap
    {
        if ($lddap->status === LddapStatus::Received) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} has already been confirmed as received.",
            ]);
        }

        if ($lddap->status !== LddapStatus::Used) {
            throw ValidationException::withMessages([
                'lddap' => 'Only a used LDDAP can be confirmed as received.',
            ]);
        }

        $this->assertNotOnHold($lddap, 'confirmed as received');

        $lddap->update([
            'status' => LddapStatus::Received,
            'received_by' => $teller->id,
            'received_at' => Carbon::now(),
        ]);

        $this->logger->log(
            $teller,
            ChequeAction::ReceivedLddap,
            null,
            "LDDAP {$lddap->lddap_no} confirmed as received by teller {$teller->name}.",
        );

        return $lddap->fresh(['lddapCheck', 'usedBy', 'receivedBy', 'acic']);
    }

    /**
     * An admin records the review outcome, moving the LDDAP to its final status.
     *
     * Approved and Cancelled are final. "Returned" is not: it hands the record back to
     * the staff member to fix what the note describes, and it can be reviewed again once they
     * have. Only an **Approved** LDDAP may go on an ACIC.
     *
     * @throws ValidationException
     */
    public function review(User $admin, Lddap $lddap, LddapStatus $outcome, ?string $note = null): Lddap
    {
        if (! in_array($outcome, LddapStatus::reviewOutcomes(), true)) {
            throw ValidationException::withMessages([
                'status' => 'That is not a valid review outcome.',
            ]);
        }

        return DB::transaction(function () use ($admin, $lddap, $outcome, $note) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            if ($lddap->status->isFinal()) {
                throw ValidationException::withMessages([
                    'lddap' => "LDDAP {$lddap->lddap_no} is already {$lddap->status->label()} and cannot be reviewed again.",
                ]);
            }

            $this->assertNotOnHold($lddap, 'reviewed');

            $lddap->update([
                'status' => $outcome,
                'reviewed_by' => $admin->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $note,
            ]);

            $this->logger->log(
                $admin,
                ChequeAction::ReviewedLddap,
                null,
                ($outcome->awaitsCompliance()
                    ? "LDDAP {$lddap->lddap_no} returned to the staff member by {$admin->name}."
                    : "LDDAP {$lddap->lddap_no} reviewed as {$outcome->label()} by {$admin->name}.")
                    .($note ? " Note: {$note}" : ''),
            );

            // Tell the staff member who used the number how their record was decided.
            $kind = match ($outcome) {
                LddapStatus::Approved => 'approved',
                LddapStatus::Cancelled => 'rejected',
                default => 'request',
            };

            $lddap->usedBy?->notify(new ActivityNotification(
                kind: $kind,
                title: $outcome->awaitsCompliance()
                    ? "LDDAP {$lddap->lddap_no} returned to you"
                    : "LDDAP {$lddap->lddap_no} {$outcome->label()}",
                message: ($outcome->awaitsCompliance()
                    ? "{$admin->name} returned LDDAP {$lddap->lddap_no} to you — it needs your"
                        .' attention before it can be signed off.'
                        .($note ? " What to fix: {$note}" : '')
                    : "{$admin->name} reviewed LDDAP {$lddap->lddap_no} as {$outcome->label()}."
                        .($note ? " Note: {$note}" : '')),
                url: '/lddaps',
            ));

            return $lddap->fresh(['lddapCheck', 'usedBy', 'receivedBy', 'reviewedBy', 'acic']);
        });
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
            ->where('status', LddapStatus::Approved)
            ->whereNull('acic_id')
            ->with(['lddapCheck', 'usedBy'])
            ->get()
            ->sortBy(fn (Lddap $l) => $l->lddapCheck->check_no)
            ->values();
    }

    /**
     * Put approved LDDAPs on an ACIC. Many approved LDDAPs may share one ACIC number.
     *
     * Everything is validated under a lock: the records must exist, be approved, and not
     * already belong to another ACIC. A partial match fails the whole request — nothing is
     * half-assigned. The LDDAP rows stay in the LDDAP table; only their `acic_id` is filled in.
     *
     * @param  list<int>  $lddapIds
     *
     * @throws ValidationException
     */
    public function assignToAcic(User $user, Acic $acic, array $lddapIds): Acic
    {
        $lddapIds = array_values(array_unique(array_map('intval', $lddapIds)));

        if ($lddapIds === []) {
            throw ValidationException::withMessages([
                'lddap_ids' => 'Select at least one LDDAP record to put on this ACIC.',
            ]);
        }

        return DB::transaction(function () use ($user, $acic, $lddapIds) {
            $acic = Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();

            if (! $acic->status->acceptsRecords()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has already been forwarded and can no longer be changed.",
                ]);
            }

            $lddaps = Lddap::query()->whereIn('id', $lddapIds)->lockForUpdate()->get();

            if ($lddaps->count() !== count($lddapIds)) {
                throw ValidationException::withMessages([
                    'lddap_ids' => 'One or more of the selected LDDAP records no longer exists.',
                ]);
            }

            // Only signed-off LDDAPs may be transmitted.
            $notApproved = $lddaps->filter(fn (Lddap $l) => ! $l->status->isApproved());

            if ($notApproved->isNotEmpty()) {
                $names = $notApproved->pluck('lddap_no')->sort()->implode(', ');

                throw ValidationException::withMessages([
                    'lddap_ids' => "Only approved LDDAP records can be linked to an ACIC. Not approved: {$names}.",
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

            Lddap::query()->whereIn('id', $lddapIds)->update(['acic_id' => $acic->id]);

            $this->acics->markUsed($user, $acic, $lddaps->count(), 'LDDAP record(s): '
                .$lddaps->pluck('lddap_no')->sort()->implode(', '));

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
     * Two rows in the same batch naming the same LDDAP would both pass the "not registered yet"
     * check and only fail on the unique index, so they are caught up front with a clear message.
     *
     * @param  list<array{lddap_no: string, ...}>  $rows
     *
     * @throws ValidationException
     */
    private function rejectDuplicatesWithinBatch(array $rows): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $key = mb_strtolower(trim((string) $row['lddap_no']));

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "rows.{$index}.lddap_no" => 'This LDDAP number appears more than once in this batch.',
                ]);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  list<array{lddap_no: string, ...}>  $rows
     *
     * @throws ValidationException
     */
    private function rejectAlreadyRegistered(array $rows): void
    {
        $numbers = array_map(fn (array $row) => trim((string) $row['lddap_no']), $rows);

        $clash = Lddap::query()->whereIn('lddap_no', $numbers)->pluck('lddap_no');

        if ($clash->isNotEmpty()) {
            $names = $clash->sort()->implode(', ');

            throw ValidationException::withMessages([
                'rows' => $clash->count() === 1
                    ? "LDDAP {$names} has already been registered against a check number."
                    : "These LDDAP numbers have already been registered against check numbers: {$names}.",
            ]);
        }
    }
}

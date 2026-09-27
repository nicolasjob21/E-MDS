<?php

namespace App\Services;

use App\Enums\AcicNumberStatus;
use App\Enums\AcicStatus;
use App\Enums\AcicType;
use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\LddapRoutingAction;
use App\Enums\LddapStatus;
use App\Models\Acic;
use App\Models\AcicNumber;
use App\Models\Cheque;
use App\Models\Lddap;
use App\Models\LddapRoutingHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcicService
{
    /** Everything a returned ACIC is read through. */
    private const WITH = ['usedBy', 'receivedBy', 'completedBy', 'createdBy', 'cheques', 'lddaps'];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly LddapCheckAllocator $checks,
        private readonly ChequeFlowService $flow,
    ) {}

    /** The bank an ACIC is lodged with. Kept here for the callers that already read it. */
    public const BANK = AcicTellerService::BANK;

    /**
     * Fix what the ACIC carries, the first time something goes on it. Called by the LDDAP
     * service too, which links its own records.
     * An ACIC never becomes a
     * mixture: the teller handles one kind of paper at a time.
     *
     * @throws ValidationException
     */
    public function stampType(Acic $acic, AcicType $type): void
    {
        if ($acic->type === null) {
            $acic->update(['type' => $type]);

            return;
        }

        if ($acic->type !== $type) {
            throw ValidationException::withMessages([
                'acic' => "ACIC #{$acic->acic_number} is a {$acic->type->label()} ACIC and cannot also carry {$type->label()} records.",
            ]);
        }
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Register a block of ACIC numbers.
     *
     * ACIC numbers are issued to the office in blocks, not invented by the system, so they are
     * registered the way cheque books and LDDAP check series are. **No number is ever registered
     * twice**: any overlap with what is already on file is rejected outright, checked under a
     * lock so a concurrent registration cannot slip one in behind this one.
     *
     * Blocks need not adjoin, and a block registered *below* numbers already in use is
     * perfectly valid — {@see nextNumber()} will draw on it first.
     *
     * @return array{from:int, to:int, count:int}
     *
     * @throws ValidationException
     */
    public function addRange(User $user, int $startAt, int $endAt): array
    {
        if ($endAt < $startAt) {
            throw ValidationException::withMessages([
                'end_at' => 'The last ACIC number must be the same as or higher than the first.',
            ]);
        }

        if ($endAt - $startAt + 1 > 100000) {
            throw ValidationException::withMessages([
                'end_at' => 'That range is too large to register in one go.',
            ]);
        }

        return DB::transaction(function () use ($user, $startAt, $endAt) {
            AcicNumber::query()->orderByDesc('acic_number')->lockForUpdate()->first();

            $clash = AcicNumber::query()
                ->whereBetween('acic_number', [$startAt, $endAt])
                ->orderBy('acic_number')
                ->pluck('acic_number');

            if ($clash->isNotEmpty()) {
                $first = $clash->first();
                $last = $clash->last();
                $range = $first === $last ? "#{$first}" : "#{$first}–#{$last}";

                throw ValidationException::withMessages([
                    'start_at' => $clash->count() === 1
                        ? "ACIC number {$range} is already registered. Enter a range that has not been added yet."
                        : "{$clash->count()} numbers in that range are already registered ({$range}). Enter a range that has not been added yet.",
                ]);
            }

            $count = $endAt - $startAt + 1;
            $now = Carbon::now();
            $rows = [];
            for ($number = $startAt; $number <= $endAt; $number++) {
                $rows[] = [
                    'acic_number' => $number,
                    'status' => AcicNumberStatus::Available->value,
                    'created_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunked to stay well under Postgres' bind-parameter limit.
            foreach (array_chunk($rows, 1000) as $chunk) {
                AcicNumber::insert($chunk);
            }

            $this->logger->log(
                $user,
                ChequeAction::AddedAcicRange,
                null,
                "Registered ACIC number series {$startAt}–{$endAt} ({$count} numbers).",
            );

            return ['from' => $startAt, 'to' => $endAt, 'count' => $count];
        });
    }

    /**
     * How much of the ACIC series is registered, and how much of it is still free.
     *
     * @return array{registered:int, available:int, used:int}
     */
    public function series(): array
    {
        $registered = AcicNumber::query()->count();
        $used = AcicNumber::query()->where('status', AcicNumberStatus::Used)->count();

        return [
            'registered' => $registered,
            'available' => $registered - $used,
            'used' => $used,
        ];
    }

    /**
     * The number the next ACIC will take: the **lowest unused** one on file.
     *
     * Null when the series is exhausted — an admin has to register more. Read-only preview for
     * the UI; `create()` re-derives it under a lock, so this is never the source of truth.
     */
    public function nextNumber(): ?int
    {
        return AcicNumber::query()
            ->where('status', AcicNumberStatus::Available)
            ->min('acic_number');
    }

    /**
     * Open a new ACIC, taking the lowest unused number in the series.
     *
     * The row is claimed `FOR UPDATE` inside the transaction, so two concurrent creates can
     * never take the same number, and a number is never handed out twice.
     *
     * @throws ValidationException
     */
    public function create(User $user): Acic
    {
        return DB::transaction(function () use ($user) {
            $next = AcicNumber::query()
                ->where('status', AcicNumberStatus::Available)
                ->orderBy('acic_number')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                throw ValidationException::withMessages([
                    'acic' => 'No ACIC numbers are available. An administrator has to register a range first.',
                ]);
            }

            $next->update(['status' => AcicNumberStatus::Used]);

            $acic = Acic::create([
                'acic_number' => $next->acic_number,
                'status' => AcicStatus::Open,
                'created_by' => $user->id,
            ]);

            $this->logger->log(
                $user,
                ChequeAction::CreatedAcic,
                null,
                "Opened ACIC number {$next->acic_number}.",
            );

            return $acic->fresh(['createdBy']);
        });
    }

    /**
     * The ACIC a typed number means, for "Assign … to ACIC": an existing ACIC that still accepts
     * records (locked), or — when the number is the next one in the series — a new ACIC opened
     * on it. Any other number is refused; a number is never invented. Many records share one
     * ACIC number this way. Call inside a transaction.
     *
     * `$approvedAccepts`: cheques may keep joining an ACIC their assignment already approved
     * (forwarding is what closes it to them); LDDAPs may not.
     *
     * @throws ValidationException
     */
    public function resolveForAssignment(User $user, int $acicNo, bool $approvedAccepts = false, string $field = 'acic_no'): Acic
    {
        $acic = Acic::query()->where('acic_number', $acicNo)->lockForUpdate()->first();

        if ($acic !== null) {
            if (! $acic->status->acceptsRecords() && ! ($approvedAccepts && $acic->status === AcicStatus::Approved)) {
                throw ValidationException::withMessages([
                    $field => "ACIC #{$acicNo} is {$acic->status->label()} and no longer accepts records.",
                ]);
            }

            return $acic;
        }

        $next = $this->nextNumber();

        if ($next === null) {
            throw ValidationException::withMessages([
                $field => 'No ACIC numbers are available. An administrator has to register a range first.',
            ]);
        }

        if ($acicNo !== $next) {
            throw ValidationException::withMessages([
                $field => "ACIC #{$acicNo} is not open. Enter an existing ACIC number, or the next one in the series (#{$next}).",
            ]);
        }

        return $this->create($user);
    }

    /**
     * "Assign Cheque to ACIC" by number: put the ticked For Signature cheques on the ACIC the
     * number means (see resolveForAssignment()). Many cheques may share one ACIC number.
     *
     * @param  list<int>  $chequeIds
     *
     * @throws ValidationException
     */
    public function assignChequesToNumber(User $user, int $acicNo, array $chequeIds): Acic
    {
        return DB::transaction(fn () => $this->assignCheques($user, $this->resolveForAssignment($user, $acicNo, approvedAccepts: true), $chequeIds));
    }

    /**
     * Cheques eligible to be put on an ACIC: For Signature, and not already on one.
     *
     * @return Collection<int, Cheque>
     */
    public function linkableCheques(): Collection
    {
        return Cheque::query()
            ->where('status', ChequeStatus::ForSignature)
            ->whereNull('acic_id')
            ->orderBy('cheque_number')
            ->with('usedBy')
            ->get();
    }

    /**
     * Assign approved cheques to an ACIC ("Use ACIC").
     *
     * Everything is validated under a lock: the cheques must exist, be approved, and not already
     * belong to another ACIC. A partial match fails the whole request — nothing is half-assigned.
     *
     * @param  list<int>  $chequeIds
     *
     * @throws ValidationException
     */
    public function assignCheques(User $user, Acic $acic, array $chequeIds): Acic
    {
        $chequeIds = array_values(array_unique(array_map('intval', $chequeIds)));

        if ($chequeIds === []) {
            throw ValidationException::withMessages([
                'cheque_ids' => 'Select at least one cheque to put on this ACIC.',
            ]);
        }

        return DB::transaction(function () use ($user, $acic, $chequeIds) {
            $acic = Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();

            // Cheques may keep joining an ACIC that assignment has already approved — many
            // share one number. It is forwarding, not approval, that closes membership here.
            if (! $acic->status->acceptsRecords() && $acic->status !== AcicStatus::Approved) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has already been forwarded and can no longer be changed.",
                ]);
            }

            // An ACIC the teller holds keeps its Approved status, so check where it is too.
            if (($why = $acic->notWithAdminBecause()) !== null) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} {$why} and can no longer take cheques.",
                ]);
            }

            $cheques = Cheque::query()
                ->whereIn('id', $chequeIds)
                ->lockForUpdate()
                ->get();

            if ($cheques->count() !== count($chequeIds)) {
                throw ValidationException::withMessages([
                    'cheque_ids' => 'One or more of the selected cheques no longer exists.',
                ]);
            }

            // An ACIC only carries cheques that have come back signed and are waiting for one.
            // A cheque already on *this* ACIC is a no-op, not an error — assigning the same
            // selection twice must not fail.
            $notEligible = $cheques->filter(
                fn (Cheque $c) => ! $c->status->isAcicEligible() && $c->acic_id !== $acic->id,
            );

            if ($notEligible->isNotEmpty()) {
                $numbers = $notEligible->pluck('cheque_number')->sort()->implode(', #');

                throw ValidationException::withMessages([
                    'cheque_ids' => "Only cheques that are For Signature can be assigned. Not eligible: #{$numbers}.",
                ]);
            }

            // ...and must not already sit on another ACIC.
            $taken = $cheques->filter(
                fn (Cheque $c) => $c->acic_id !== null && $c->acic_id !== $acic->id,
            );

            if ($taken->isNotEmpty()) {
                $numbers = $taken->pluck('cheque_number')->sort()->implode(', #');

                throw ValidationException::withMessages([
                    'cheque_ids' => "These cheques are already on another ACIC: #{$numbers}.",
                ]);
            }

            $this->stampType($acic, AcicType::Cheque);
            Cheque::query()->whereIn('id', $chequeIds)->update(['acic_id' => $acic->id]);

            // Each cheque moves For Signature → Approved in its own right, so the step lands in
            // every cheque's status history rather than only on the ACIC.
            foreach ($cheques as $cheque) {
                if ($cheque->status === ChequeStatus::Approved) {
                    continue;   // already on this ACIC
                }

                $this->flow->move($user, $cheque, ChequeStatus::Approved, 'assigned', null,
                    fn () => ['acic_id' => $acic->id], $this->replacementNote($cheque, $acic), $acic);
            }

            $numbers = $cheques->pluck('cheque_number')->sort()->implode(', #');
            $this->markUsed($user, $acic, $cheques->count(), "cheque(s): #{$numbers}");

            // Putting cheques on an ACIC signs it off in the same breath: there is no separate
            // approval step for a cheque ACIC, so it is ready to forward or print at once.
            // (Assigning LDDAPs leaves the ACIC Used, to be approved on its own as before.)
            if ($acic->fresh()->status !== AcicStatus::Approved) {
                $acic->update(['status' => AcicStatus::Approved]);

                $this->logger->log(
                    $user,
                    ChequeAction::ApprovedAcic,
                    null,
                    "ACIC number {$acic->acic_number} approved on assignment of cheque(s): #{$numbers}.",
                );
            }

            return $acic->fresh(['usedBy', 'receivedBy', 'createdBy', 'cheques']);
        });
    }

    /**
     * The timeline note for a replacement of a spoiled cheque that was on an ACIC: whether it
     * took the spoiled cheque's place on that ACIC, or went to a new one (and why, when the old
     * one could no longer take it). Null for any other cheque.
     */
    private function replacementNote(Cheque $cheque, Acic $acic): ?string
    {
        $spoiled = $cheque->replaces()->with('spoiledFromAcic')->first();

        if ($spoiled === null || $spoiled->status !== ChequeStatus::Spoiled || $spoiled->spoiledFromAcic === null) {
            return null;
        }

        $previous = $spoiled->spoiledFromAcic;

        if ($previous->id === $acic->id) {
            return "Used previous ACIC #{$acic->acic_number} — in place of spoiled cheque #{$spoiled->cheque_number}.";
        }

        $why = $previous->notWithAdminBecause();

        return "Assigned to a new ACIC #{$acic->acic_number} — spoiled cheque #{$spoiled->cheque_number} was on ACIC #{$previous->acic_number}"
            .($why !== null ? ", which {$why}." : '.');
    }

    /**
     * Record that records have been put on an ACIC: the first assignment moves it Open -> Used
     * and stamps Used By. Shared by cheque linking and by LDDAP linking, which draws on its own
     * check series but lands on the same ACIC.
     *
     * @param  string  $what  human-readable description of what was assigned, for the audit log
     */
    public function markUsed(User $user, Acic $acic, int $count, string $what): void
    {
        $acic->update([
            'status' => AcicStatus::Used,
            'used_by' => $acic->used_by ?? $user->id,
            'used_at' => $acic->used_at ?? Carbon::now(),
        ]);

        $this->logger->log(
            $user,
            ChequeAction::UsedAcic,
            null,
            "Assigned {$count} {$what} to ACIC number {$acic->acic_number}.",
        );
    }

    /**
     * Sign an ACIC off, so it can be forwarded and printed.
     *
     * Only an ACIC that actually carries something may be approved, and only from **Used** —
     * approving an empty number would sign off nothing, and re-approving one already forwarded
     * would rewrite history the teller is working from.
     *
     * @throws ValidationException
     */
    public function approve(User $user, Acic $acic): Acic
    {
        return DB::transaction(function () use ($user, $acic) {
            $acic = Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();

            if ($acic->status !== AcicStatus::Used) {
                throw ValidationException::withMessages([
                    'acic' => $acic->status === AcicStatus::Approved
                        ? "ACIC #{$acic->acic_number} has already been approved."
                        : "ACIC #{$acic->acic_number} is {$acic->status->label()} — only a Used ACIC can be approved.",
                ]);
            }

            $cheques = $acic->cheques()->count();
            $lddaps = $acic->lddaps()->count();

            if ($cheques === 0 && $lddaps === 0) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} has nothing on it to approve.",
                ]);
            }

            $acic->update(['status' => AcicStatus::Approved]);

            $this->logger->log(
                $user,
                ChequeAction::ApprovedAcic,
                null,
                "Approved ACIC number {$acic->acic_number} ({$cheques} cheque(s), {$lddaps} LDDAP(s)).",
            );

            return $acic->fresh(self::WITH);
        });
    }

    /**
     * Swap one record on an ACIC for another: the record coming off is **released back to the
     * pool** and can go on a future ACIC, and the one coming on takes its place.
     *
     * Both halves happen in one locked transaction, so the ACIC is never briefly short of the
     * record it is meant to carry, and the released number can never be claimed twice.
     *
     * @param  'cheque'|'lddap'  $type
     *
     * @throws ValidationException
     */
    public function reassign(User $user, Acic $acic, string $type, int $releaseId, int $assignId): Acic
    {
        return DB::transaction(function () use ($user, $acic, $type, $releaseId, $assignId) {
            $acic = Acic::query()->whereKey($acic->getKey())->lockForUpdate()->firstOrFail();

            if (! $acic->status->acceptsReassignment()) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} is {$acic->status->label()} — its records can no longer be changed.",
                ]);
            }

            // It keeps its Approved status while the tellers have it, so check that too.
            if (($why = $acic->notWithAdminBecause()) !== null) {
                throw ValidationException::withMessages([
                    'acic' => "ACIC #{$acic->acic_number} {$why} — its records can no longer be changed.",
                ]);
            }

            $isCheque = $type === 'cheque';
            $label = $isCheque ? 'Cheque' : 'LDDAP';

            /** @var class-string<Cheque|Lddap> $model */
            $model = $isCheque ? Cheque::class : Lddap::class;

            $release = $model::query()->whereKey($releaseId)->lockForUpdate()->first();
            $assign = $model::query()->whereKey($assignId)->lockForUpdate()->first();

            if ($release === null || $release->acic_id !== $acic->id) {
                throw ValidationException::withMessages([
                    'release_id' => "That {$label} is not on ACIC #{$acic->acic_number}.",
                ]);
            }

            if ($assign === null) {
                throw ValidationException::withMessages([
                    'assign_id' => "That {$label} no longer exists.",
                ]);
            }

            if ($assign->getKey() === $release->getKey()) {
                throw ValidationException::withMessages([
                    'assign_id' => 'Choose a different record to put on in its place.',
                ]);
            }

            $eligible = $assign->status->isAcicEligible();

            if (! $eligible) {
                $needs = 'For Signature';

                throw ValidationException::withMessages([
                    'assign_id' => "Only a {$needs} {$label} can go on an ACIC.",
                ]);
            }

            if ($assign->acic_id !== null) {
                throw ValidationException::withMessages([
                    'assign_id' => "That {$label} is already on ACIC #{$assign->acic?->acic_number}.",
                ]);
            }

            // Released first, so the ACIC's membership never counts both at once. A released
            // LDDAP keeps the check number it was issued — numbers are never reused — and the
            // one coming on takes the next one if it has none yet.
            $release->update(['acic_id' => null]);

            if (! $isCheque) {
                $this->checks->claim($user, new Collection([$assign]));
            }

            $assign->update(['acic_id' => $acic->id]);

            // A cheque coming off goes back to For Signature, waiting for an ACIC;
            // the one taking its place moves on to Approved. Both are recorded steps.
            if ($isCheque) {
                $this->flow->move($user, $release, ChequeStatus::ForSignature, 'unassigned', null,
                    fn () => ['acic_id' => null], 'Re-assigned off the ACIC.', $acic);
                $this->flow->move($user, $assign, ChequeStatus::Approved, 'assigned', null,
                    fn () => ['acic_id' => $acic->id], null, $acic);
            } else {
                // Likewise an LDDAP: off → For Signature, on → Approved, each on its own trail.
                $release->update(['status' => LddapStatus::ForSignature]);
                $assign->update(['status' => LddapStatus::Approved]);
                foreach ([[$release, LddapRoutingAction::Unassigned, LddapStatus::Approved, LddapStatus::ForSignature],
                    [$assign, LddapRoutingAction::Assigned, LddapStatus::ForSignature, LddapStatus::Approved]] as [$record, $action, $from, $to]) {
                    LddapRoutingHistory::create([
                        'lddap_id' => $record->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
                        'user_id' => $user->id, 'acted_on' => now()->toDateString(), 'note' => "ACIC #{$acic->acic_number}",
                    ]);
                }
            }

            $from = $isCheque ? "#{$release->cheque_number}" : $release->lddap_no;
            $to = $isCheque ? "#{$assign->cheque_number}" : $assign->lddap_no;

            $this->logger->log(
                $user,
                ChequeAction::ReassignedAcic,
                $isCheque ? $release->cheque_number : null,
                "Re-assigned ACIC number {$acic->acic_number}: {$label} {$from} released, {$label} {$to} put on in its place.",
            );

            return $acic->fresh(self::WITH);
        });
    }
}

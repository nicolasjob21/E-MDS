<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\RequestStatus;
use App\Models\Cheque;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ChequeService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * The next usable cheque: the lowest-numbered available one, or null if none remain.
     */
    public function nextAvailable(): ?Cheque
    {
        return Cheque::query()
            ->where('status', ChequeStatus::Available)
            ->orderBy('cheque_number')
            ->first();
    }

    /**
     * Aggregate counts for dashboard cards.
     *
     * @return array{total:int, available:int, used:int, received:int, approved:int, complies:int, disapproved:int}
     */
    public function counts(): array
    {
        $rows = Cheque::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (ChequeStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return ['total' => array_sum($counts)] + $counts;
    }

    /**
     * Consume the next available cheque.
     *
     * Enforced entirely server-side: we lock the lowest available row FOR UPDATE so two
     * concurrent requests can never claim the same number, and we reject the request unless
     * the number the client asked for is genuinely the next one in line (no skipping).
     *
     * @param  array{payee_name?: string|null, amount?: mixed, cheque_date?: mixed}  $details
     *
     * @throws ValidationException
     */
    public function useNext(User $user, int $requestedNumber, array $details = []): Cheque
    {
        return DB::transaction(function () use ($user, $requestedNumber, $details) {
            $next = Cheque::query()
                ->where('status', ChequeStatus::Available)
                ->orderBy('cheque_number')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                throw ValidationException::withMessages([
                    'cheque_number' => 'There are no available cheque numbers left. Ask an admin to add a new range.',
                ]);
            }

            if ($next->cheque_number !== $requestedNumber) {
                throw ValidationException::withMessages([
                    'cheque_number' => "Cheque number {$requestedNumber} is not the next one in line. The next available number is {$next->cheque_number}.",
                ]);
            }

            $next->update([
                'status' => ChequeStatus::Used,
                'used_by' => $user->id,
                'used_at' => Carbon::now(),
                'payee_name' => $details['payee_name'] ?? null,
                'amount' => $details['amount'] ?? null,
                'cheque_date' => $details['cheque_date'] ?? null,
            ]);

            $payee = $details['payee_name'] ?? null;
            $description = "Used cheque number {$next->cheque_number}"
                .($payee ? " for {$payee}" : '').'.';

            $this->logger->log(
                $user,
                ChequeAction::UsedCheque,
                $next->cheque_number,
                $description,
            );

            // Let admins know a number was consumed (skip the actor if they are an admin).
            Notification::send(
                User::query()->activeAdmins()->whereKeyNot($user->id)->get(),
                new ActivityNotification(
                    kind: 'used',
                    title: "Cheque #{$next->cheque_number} used",
                    message: "{$user->name} used cheque #{$next->cheque_number}".($payee ? " for {$payee}" : '').'.',
                    url: '/admin/logs',
                    chequeNumber: $next->cheque_number,
                ),
            );

            return $next->fresh(['usedBy']);
        });
    }

    /**
     * A teller confirms that a used cheque has been received, moving it used -> received.
     * This is a separate lifecycle event from using/issuing the cheque, and records which
     * teller confirmed it and when.
     *
     * @throws ValidationException
     */
    public function confirmReceipt(User $teller, Cheque $cheque): Cheque
    {
        if ($cheque->status === ChequeStatus::Received) {
            throw ValidationException::withMessages([
                'cheque' => "Cheque #{$cheque->cheque_number} has already been confirmed as received.",
            ]);
        }

        if ($cheque->status !== ChequeStatus::Used) {
            throw ValidationException::withMessages([
                'cheque' => 'Only a used cheque can be confirmed as received.',
            ]);
        }

        // A cheque with a pending detail-update request is on hold: the teller cannot confirm
        // receipt until an admin has approved or rejected the requested change.
        $onHold = $cheque->updateRequests()
            ->where('status', RequestStatus::Pending)
            ->exists();

        if ($onHold) {
            throw ValidationException::withMessages([
                'cheque' => "Cheque #{$cheque->cheque_number} is on hold — a detail update request is awaiting admin approval and must be resolved first.",
            ]);
        }

        $cheque->update([
            'status' => ChequeStatus::Received,
            'received_by' => $teller->id,
            'received_at' => Carbon::now(),
        ]);

        $this->logger->log(
            $teller,
            ChequeAction::ReceivedCheque,
            $cheque->cheque_number,
            "Cheque number {$cheque->cheque_number} confirmed as received by teller {$teller->name}.",
        );

        return $cheque->fresh(['usedBy', 'receivedBy']);
    }

    /**
     * An admin records the review outcome for an issued cheque, moving it to its final
     * status: approved, complies, or disapproved.
     *
     * Approved and Disapproved are final and cannot be revisited. "Returned" is not:
     * it hands the cheque back to the staff member to fix what the note describes, and the
     * cheque can be reviewed again once they have. A cheque with a pending detail-update
     * request is on hold, exactly as it is for teller receipt, so the details are settled
     * before anyone signs off on them.
     *
     * @throws ValidationException
     */
    public function review(User $admin, Cheque $cheque, ChequeStatus $outcome, ?string $note = null): Cheque
    {
        if (! in_array($outcome, ChequeStatus::reviewOutcomes(), true)) {
            throw ValidationException::withMessages([
                'status' => 'That is not a valid review outcome.',
            ]);
        }

        return DB::transaction(function () use ($admin, $cheque, $outcome, $note) {
            $cheque = Cheque::query()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if (! $cheque->status->isIssued()) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} has not been used yet, so there is nothing to review.",
                ]);
            }

            if ($cheque->status->isFinal()) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is already {$cheque->status->label()} and cannot be reviewed again.",
                ]);
            }

            $onHold = $cheque->updateRequests()
                ->where('status', RequestStatus::Pending)
                ->exists();

            if ($onHold) {
                throw ValidationException::withMessages([
                    'cheque' => "Cheque #{$cheque->cheque_number} is on hold — a detail update request is awaiting approval and must be resolved first.",
                ]);
            }

            $cheque->update([
                'status' => $outcome,
                'reviewed_by' => $admin->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $note,
            ]);

            $this->logger->log(
                $admin,
                ChequeAction::ReviewedCheque,
                $cheque->cheque_number,
                ($outcome->awaitsCompliance()
                    ? "Cheque number {$cheque->cheque_number} returned to the staff member by {$admin->name}."
                    : "Cheque number {$cheque->cheque_number} reviewed as {$outcome->label()} by {$admin->name}.")
                    .($note ? " Note: {$note}" : ''),
            );

            // Tell the staff member who issued it how their cheque was decided. "Returned"
            // is an action item for them, not a verdict, so it lands as a request-kind notification.
            $kind = match ($outcome) {
                ChequeStatus::Approved => 'approved',
                ChequeStatus::Disapproved => 'rejected',
                default => 'request',
            };

            $message = $outcome->awaitsCompliance()
                ? "{$admin->name} returned cheque #{$cheque->cheque_number} to you — it needs your"
                    .' attention before it can be signed off.'
                    .($note ? " What to fix: {$note}" : '')
                : "{$admin->name} reviewed cheque #{$cheque->cheque_number} as {$outcome->label()}."
                    .($note ? " Note: {$note}" : '');

            $cheque->usedBy?->notify(new ActivityNotification(
                kind: $kind,
                title: $outcome->awaitsCompliance()
                    ? "Cheque #{$cheque->cheque_number} returned to you"
                    : "Cheque #{$cheque->cheque_number} {$outcome->label()}",
                message: $message,
                url: '/cheques',
                chequeNumber: $cheque->cheque_number,
            ));

            return $cheque->fresh(['usedBy', 'receivedBy', 'reviewedBy']);
        });
    }

    /**
     * Register a newly issued physical cheque book by its printed serial range.
     *
     * The admin enters the first and last serial exactly as printed on the book, so a new book
     * may legitimately start well above the last number already on file (bank-assigned serials
     * are not contiguous between books). Numbers in between simply never existed.
     *
     * What is guaranteed: **no number is ever registered twice**. The whole range is checked
     * against existing rows inside a locked transaction, so two admins registering overlapping
     * books concurrently cannot both win. Sequential *usage* is unaffected — `useNext()` still
     * hands out the lowest available number across every book.
     *
     * @return array{from:int, to:int, count:int}
     *
     * @throws ValidationException
     */
    public function addRange(User $user, int $startAt, int $endAt): array
    {
        if ($endAt < $startAt) {
            throw ValidationException::withMessages([
                'end_at' => 'The last serial number must be the same as or higher than the first.',
            ]);
        }

        return DB::transaction(function () use ($user, $startAt, $endAt) {
            // Lock the table's tail so a concurrent registration can't slip an overlapping
            // range in between this check and the insert.
            Cheque::query()->orderByDesc('cheque_number')->lockForUpdate()->first();

            $clash = Cheque::query()
                ->whereBetween('cheque_number', [$startAt, $endAt])
                ->orderBy('cheque_number')
                ->pluck('cheque_number');

            if ($clash->isNotEmpty()) {
                $first = $clash->first();
                $last = $clash->last();
                $range = $first === $last ? "#{$first}" : "#{$first}–#{$last}";

                throw ValidationException::withMessages([
                    'start_at' => $clash->count() === 1
                        ? "Cheque number {$range} is already registered. Enter a serial range that has not been added yet."
                        : "{$clash->count()} numbers in that range are already registered ({$range}). Enter a serial range that has not been added yet.",
                ]);
            }

            $count = $endAt - $startAt + 1;
            $now = Carbon::now();
            $rows = [];
            for ($number = $startAt; $number <= $endAt; $number++) {
                $rows[] = [
                    'cheque_number' => $number,
                    'status' => ChequeStatus::Available->value,
                    'created_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunk to keep the insert well under Postgres' bind-parameter limit.
            foreach (array_chunk($rows, 1000) as $chunk) {
                Cheque::insert($chunk);
            }

            $this->logger->log(
                $user,
                ChequeAction::AddedChequeRange,
                null,
                "Registered cheque book serials {$startAt}–{$endAt} ({$count} cheques).",
            );

            return ['from' => $startAt, 'to' => $endAt, 'count' => $count];
        });
    }
}

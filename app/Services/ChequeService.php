<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\RequestStatus;
use App\Models\Cheque;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * @return array{total:int, available:int, used:int, received:int}
     */
    public function counts(): array
    {
        $rows = Cheque::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $available = (int) ($rows[ChequeStatus::Available->value] ?? 0);
        $used = (int) ($rows[ChequeStatus::Used->value] ?? 0);
        $received = (int) ($rows[ChequeStatus::Received->value] ?? 0);

        return [
            'total' => $available + $used + $received,
            'available' => $available,
            'used' => $used,
            'received' => $received,
        ];
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
     * Extend the sequence by `count` cheques, always continuing from the last existing number.
     *
     * `startAt` is honoured only for the very first range (empty table); afterwards the start is
     * forced to last+1 so the sequence can never have gaps or duplicates. The whole thing runs
     * inside a locked transaction so two admins can't extend concurrently.
     *
     * @return array{from:int, to:int, count:int}
     *
     * @throws ValidationException
     */
    public function addRange(User $user, int $count, ?int $startAt = null): array
    {
        return DB::transaction(function () use ($user, $count, $startAt) {
            $last = Cheque::query()
                ->orderByDesc('cheque_number')
                ->lockForUpdate()
                ->first();

            if ($last !== null) {
                $from = $last->cheque_number + 1;
            } else {
                $from = $startAt ?? 1;
            }

            $to = $from + $count - 1;

            $now = Carbon::now();
            $rows = [];
            for ($number = $from; $number <= $to; $number++) {
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
                "Added cheque numbers {$from}–{$to} ({$count} cheques).",
            );

            return ['from' => $from, 'to' => $to, 'count' => $count];
        });
    }
}

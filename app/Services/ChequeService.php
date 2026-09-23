<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
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
                // The number is claimed and the flow starts: Registered, with its 90-day
                // clock running from the cheque date (`validity_until` follows it, on the model).
                'status' => ChequeStatus::Registered,
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

<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\LddapCheckStatus;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Hands out LDDAP check numbers in **consecutive blocks**.
 *
 * A batch of N records takes N numbers that run k, k+1, …, k+N−1 with no gap — the first such
 * run in the registered series, searching up from the lowest unused number. A run broken by a
 * used (or never-registered) number is skipped whole: with 1 and 2 free and 3 used, three
 * records take 4–6, and 1 and 2 stay free for a later batch of one or two. Numbers go out in
 * the order the records are listed.
 *
 * Two users assigning at once cannot overlap: inside the caller's transaction the allocator
 * first locks the lowest unused row `FOR UPDATE` — every allocator contends for that same row,
 * so they run one at a time — then locks the block it chose and confirms every number is still
 * free. A number can never be reused: `lddap_checks.check_no` and `lddaps.lddap_check_id` are
 * both unique at the database.
 *
 * The caller may pass the block it previewed. If any of those numbers has since been taken the
 * save is refused naming it, so the screen can recompute and show a fresh block.
 *
 * Split out from `LddapService` so `AcicService` (which `LddapService` depends on) can allocate
 * a number when a record is swapped onto an ACIC, without a dependency cycle.
 */
class LddapCheckAllocator
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * The first run of `$count` consecutive unused numbers, lowest first — or an empty list when
     * the series holds no such run. Read-only preview; `claim()` re-derives it under a lock.
     *
     * @return list<int>
     */
    public function nextBlock(int $count): array
    {
        return $this->findBlock(max(1, $count), $this->available());
    }

    /**
     * Give every record in `$lddaps` that has no check number the next consecutive block, in
     * list order. Must be called inside a transaction — the rows are locked, not the table.
     *
     * @param  Collection<int, Lddap>  $lddaps
     * @param  list<int>|null  $expected  the block the caller previewed, in the same order
     *
     * @throws ValidationException
     */
    public function claim(User $user, Collection $lddaps, ?array $expected = null): void
    {
        $needing = $lddaps->filter(fn (Lddap $l) => $l->lddap_check_id === null)->values();

        if ($needing->isEmpty()) {
            return;
        }

        $wanted = $needing->count();

        // Serialise allocators: whoever holds the lowest unused row goes first. Under READ
        // COMMITTED the lock is re-evaluated once acquired, so a waiter whose target was just
        // taken moves on to the new lowest row rather than proceeding on a stale one.
        LddapCheck::query()
            ->where('status', LddapCheckStatus::Available)
            ->orderBy('check_no')
            ->lockForUpdate()
            ->first();

        $available = $this->available();
        $block = $this->findBlock($wanted, $available);

        if ($expected !== null) {
            $this->assertPreviewStillHolds($expected, $block, $available);
        }

        if ($block === []) {
            throw ValidationException::withMessages([
                'check_nos' => $available === []
                    ? 'There are no unused check numbers left in the LDDAP series. Ask an admin to register a new range.'
                    : "There is no run of {$wanted} consecutive unused check numbers in the series. Assign fewer records, or ask an admin to register a new range.",
            ]);
        }

        // Lock exactly the block and confirm it is still whole. With the serialising lock above
        // this cannot fail on Postgres; it is the safety net on any engine.
        $checks = LddapCheck::query()
            ->whereIn('check_no', $block)
            ->where('status', LddapCheckStatus::Available)
            ->orderBy('check_no')
            ->lockForUpdate()
            ->get();

        if ($checks->count() !== $wanted) {
            $held = $checks->pluck('check_no')->map(fn ($n) => (int) $n)->all();
            $gone = array_values(array_diff($block, $held))[0];

            throw ValidationException::withMessages([
                'check_nos' => "Check number {$gone} is already used. Please refresh and try again.",
            ]);
        }

        $now = Carbon::now();

        foreach ($needing as $i => $lddap) {
            /** @var LddapCheck $check */
            $check = $checks[$i];

            $check->update(['status' => LddapCheckStatus::Used]);
            $lddap->update(['lddap_check_id' => $check->id, 'used_at' => $now]);

            $this->logger->log(
                $user,
                ChequeAction::UsedLddapCheck,
                null,
                "Used LDDAP check number {$check->check_no} for LDDAP {$lddap->lddap_no}"
                    .($lddap->payee_name ? " ({$lddap->payee_name})" : '').'.',
            );
        }
    }

    /**
     * Every unused number in the series, ascending.
     *
     * @return list<int>
     */
    private function available(): array
    {
        return LddapCheck::query()
            ->where('status', LddapCheckStatus::Available)
            ->orderBy('check_no')
            ->pluck('check_no')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The first run of `$count` consecutive values in an ascending list, or `[]` if there is none.
     *
     * @param  list<int>  $available
     * @return list<int>
     */
    private function findBlock(int $count, array $available): array
    {
        $run = [];

        foreach ($available as $number) {
            // Extend the run if this number follows the last one; otherwise start over here.
            $run = ($run !== [] && $number === end($run) + 1) ? [...$run, $number] : [$number];

            if (count($run) === $count) {
                return $run;
            }
        }

        return [];
    }

    /**
     * The block the caller showed has to be the block about to be issued. If any previewed
     * number has since been taken, that number is named; anything else out of step is a stale
     * preview.
     *
     * @param  list<int>  $expected
     * @param  list<int>  $block
     * @param  list<int>  $available
     *
     * @throws ValidationException
     */
    private function assertPreviewStillHolds(array $expected, array $block, array $available): void
    {
        $expected = array_values(array_map('intval', $expected));
        $free = array_flip($available);

        foreach ($expected as $number) {
            if (! isset($free[$number])) {
                throw ValidationException::withMessages([
                    'check_nos' => "Check number {$number} is already used. Please refresh and try again.",
                ]);
            }
        }

        if ($expected !== $block) {
            throw ValidationException::withMessages([
                'check_nos' => 'The check-number preview is out of date. Please refresh and try again.',
            ]);
        }
    }
}

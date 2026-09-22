<?php

namespace Tests\Feature;

use App\Enums\AcicStatus;
use App\Enums\LddapCheckStatus;
use App\Enums\LddapStatus;
use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\ChequeLog;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\Payee;
use App\Models\Unit;
use App\Models\User;
use App\Services\AcicService;
use App\Services\ChequeService;
use App\Services\LddapService;
use App\Services\LddapUpdateRequestService;
use App\Support\Money;
use App\Support\Tax;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LddapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ACIC numbers are a registered series; give the suite a block to draw on.
        $this->seedAcicSeries(1, 50);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff]);
    }

    private function teller(): User
    {
        return User::factory()->teller()->create();
    }

    /** Register a block of the LDDAP check series. */
    private function seedSeries(int $from = 1, int $to = 10): void
    {
        app(LddapService::class)->addRange($this->admin(), $from, $to);
    }

    /** The reference lists a registration draws on — one unit, one payee — created on demand. */
    private function unit(): Unit
    {
        return Unit::firstOrCreate(['name' => 'ACCOUNTING']);
    }

    /** A payee with a single account, so a registration can leave the account implicit. */
    private function payee(): Payee
    {
        $payee = Payee::firstOrCreate(['name' => 'ACME SUPPLIES INC.']);
        $payee->accounts()->firstOrCreate(['account_no' => '2028-9010-11'], ['bank' => 'LBP']);

        return $payee->load('accounts');
    }

    /** A payee holding two accounts, for the choose-which-account cases. */
    private function multiAccountPayee(): Payee
    {
        $payee = Payee::firstOrCreate(['name' => 'BLUEOCEAN LOGISTICS']);
        $payee->accounts()->firstOrCreate(['account_no' => '1701-0426-18'], ['bank' => 'LBP']);
        $payee->accounts()->firstOrCreate(['account_no' => '0451-2210-07'], ['bank' => 'DBP']);

        return $payee->load('accounts');
    }

    /**
     * Complete registration details, as the dialog submits them — one record each.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(int $count, int $offset = 0): array
    {
        $unit = $this->unit();
        $payee = $this->payee();

        return array_map(fn (int $i) => [
            'lddap_no' => 'LDDAP-'.str_pad((string) ($i + $offset), 4, '0', STR_PAD_LEFT),
            'nca_no' => 'NCA-'.($i + $offset),
            'orb_no' => 'ORB-'.($i + $offset),
            'dv_no' => 'DV-'.($i + $offset),
            'nature_of_payment' => NatureOfPayment::CommercialClaims->value,
            'obj_no' => 'OBJ-'.($i + $offset),
            'unit_id' => $unit->id,
            'check_date' => '2026-09-21',
            'payee_id' => $payee->id,
            'gross_amount' => 100 * ($i + $offset),
        ], range(1, $count));
    }

    /** One record's details. */
    private function details(int $n = 1): array
    {
        return $this->rows(1, $n - 1)[0];
    }

    /** The routing steps, as the dialogs submit them. */
    private function forwardDetails(): array
    {
        return [
            'forward_to' => 'Accounting Division',
            'unit_id' => $this->unit()->id,
            'date_forwarded' => '2026-09-22',
            'note' => 'For signature.',
        ];
    }

    private function receiveDetails(): array
    {
        return [
            'unit_id' => $this->unit()->id,
            'date_received' => '2026-09-23',
            'note' => 'Signed.',
        ];
    }

    /** The RTS step, as the dialog submits it. */
    private function rtsDetails(array $overrides = []): array
    {
        return array_merge([
            'received_on' => '2026-09-23',
            'received_by' => 'M. Santos',
            'unit_id' => $this->unit()->id,
            'rts_date' => '2026-09-24',
            'note' => 'Wrong OBJ code — please correct and resend.',
        ], $overrides);
    }

    /** Register one LDDAP, forward it and receive it back — Returned for ACIC, no check number. */
    private function returnedLddap(?User $user = null, int $n = 1): Lddap
    {
        $service = app(LddapService::class);
        $user ??= $this->staff();

        $lddap = $service->register($user, $this->details($n));
        $lddap = $service->forward($user, $lddap, $this->forwardDetails());

        return $service->receive($user, $lddap, $this->receiveDetails());
    }

    /** Register, forward, receive and approve `$count` LDDAPs — ready for an ACIC, no numbers yet. */
    private function approvedLddaps(int $count, int $offset = 0, ?User $staff = null): Collection
    {
        $service = app(LddapService::class);
        $staff ??= $this->staff();
        $admin = $this->admin();
        $out = new Collection;

        foreach ($this->rows($count, $offset) as $details) {
            $lddap = $service->register($staff, $details);
            $lddap = $service->forward($staff, $lddap, $this->forwardDetails());
            $lddap = $service->receive($staff, $lddap, $this->receiveDetails());
            $out->push($service->approve($admin, $lddap));
        }

        return $out;
    }

    /**
     * The whole path: `$count` approved LDDAPs put on a fresh ACIC, which is when each takes
     * its check number. Returns them with their numbers loaded, in assignment order.
     *
     * @return Collection<int, Lddap>
     */
    private function useChecks(int $count, ?User $user = null, int $offset = 0): Collection
    {
        $user ??= $this->staff();
        $lddaps = $this->approvedLddaps($count, $offset, $user);
        $acic = app(AcicService::class)->create($this->admin());

        app(LddapService::class)->assignToAcic($user, $acic, $lddaps->pluck('id')->all());

        return Lddap::query()->whereIn('id', $lddaps->pluck('id'))->with('lddapCheck')->get()
            ->sortBy(fn (Lddap $l) => array_search($l->id, $lddaps->pluck('id')->all(), true))
            ->values();
    }

    /** @return list<int> the check numbers held by the given LDDAPs, in order */
    private function numbersOf(Collection $lddaps): array
    {
        return $lddaps->map(fn (Lddap $l) => $l->lddapCheck->check_no)->values()->all();
    }

    // ------------------------------------------------ an independent check series

    public function test_the_check_series_is_independent_of_cheque_numbers(): void
    {
        // The cheque register runs 5001-5010 and has cheques used on it...
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 5001, 5010);
        $cheques->useNext($this->staff(), 5001, ['payee_name' => 'X', 'amount' => 1, 'cheque_date' => '2026-09-01']);

        // ...while the LDDAP series starts at 1 and is entirely unaffected by it.
        $this->seedSeries(1, 5);

        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3)));
        $this->assertSame(9, Cheque::where('status', 'available')->count());
    }

    public function test_the_check_series_is_independent_of_acic_numbers(): void
    {
        $this->seedSeries(200, 205);
        app(AcicService::class)->create($this->admin());
        app(AcicService::class)->create($this->admin());

        // ACIC numbering is at 2; the LDDAP series still hands out 200. (`useChecks` opens one
        // more ACIC to put the record on, so the ACIC series moves to 4 — the LDDAP series
        // does not care.)
        $this->assertSame([200], $this->numbersOf($this->useChecks(1)));
        $this->assertSame(4, app(AcicService::class)->nextNumber());
    }

    public function test_using_a_check_number_consumes_no_cheque(): void
    {
        app(ChequeService::class)->addRange($this->admin(), 1, 10);
        $this->seedSeries(1, 10);

        $this->useChecks(3);

        $this->assertSame(10, Cheque::where('status', 'available')->count());
        $this->assertSame(0, Cheque::whereNotNull('used_at')->count());
    }

    // ------------------------------------------- check numbers, issued at assignment

    /** Assignment issues one number per record, lowest unused first, in the order listed. */
    public function test_numbers_are_handed_out_lowest_first_in_strict_order(): void
    {
        $this->seedSeries(1, 10);

        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3)));
        $this->assertSame([4, 5], $this->numbersOf($this->useChecks(2, offset: 10)));
    }

    /** "In the order shown": the numbers follow the order the ids were listed, not id order. */
    public function test_numbers_follow_the_order_the_records_were_listed_in(): void
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->approvedLddaps(3);
        [$a, $b, $c] = $lddaps->all();
        $acic = app(AcicService::class)->create($this->admin());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertSame(1, $c->fresh('lddapCheck')->lddapCheck->check_no);
        $this->assertSame(2, $a->fresh('lddapCheck')->lddapCheck->check_no);
        $this->assertSame(3, $b->fresh('lddapCheck')->lddapCheck->check_no);
    }

    /** The screen previews the numbers; the save names them; they must still be the next ones. */
    public function test_a_stale_preview_is_refused_naming_the_number_that_was_taken(): void
    {
        $this->seedSeries(1, 10);
        $mine = $this->approvedLddaps(2);
        $theirs = $this->approvedLddaps(1, offset: 10, staff: $other = $this->staff());

        // I preview 1 and 2 …
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/lddaps/next-numbers?count=2')->assertJsonPath('data.numbers', [1, 2]);

        // … someone else assigns first and takes 1.
        $acicB = app(AcicService::class)->create($this->admin());
        app(LddapService::class)->assignToAcic($other, $acicB, [$theirs->first()->id], [1]);

        // My save, still naming 1 and 2, is blocked with the message the screen shows.
        $acicA = app(AcicService::class)->create($this->admin());
        $this->postJson("/api/v1/acics/{$acicA->id}/lddaps", [
            'lddap_ids' => $mine->pluck('id')->all(),
            'expected_check_nos' => [1, 2],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.check_nos.0', 'Check number 1 is already used. Please refresh and try again.');

        // Nothing of mine was numbered or assigned.
        $this->assertNull($mine->first()->fresh()->lddap_check_id);
        $this->assertNull($mine->first()->fresh()->acic_id);

        // A refreshed preview shows the next available numbers, and saving with them works.
        $this->getJson('/api/v1/lddaps/next-numbers?count=2')->assertJsonPath('data.numbers', [2, 3]);
        $this->postJson("/api/v1/acics/{$acicA->id}/lddaps", [
            'lddap_ids' => $mine->pluck('id')->all(),
            'expected_check_nos' => [2, 3],
        ])->assertOk();

        $this->assertSame([2, 3], $this->numbersOf(
            Lddap::whereIn('id', $mine->pluck('id'))->orderBy('id')->with('lddapCheck')->get(),
        ));
    }

    /**
     * Two users assigning at the same moment: the second to reach the lock finds the first's
     * numbers gone. SQLite cannot run the two transactions concurrently, so this drives the same
     * interleaving deterministically — both preview the same numbers, the first claims them, the
     * second's identical claim is refused and its refreshed claim succeeds with the next ones.
     */
    public function test_two_users_cannot_be_issued_the_same_numbers(): void
    {
        $this->seedSeries(1, 10);
        $alice = $this->staff();
        $bob = $this->staff();
        $aliceRecords = $this->approvedLddaps(2, staff: $alice);
        $bobRecords = $this->approvedLddaps(2, offset: 10, staff: $bob);
        $service = app(LddapService::class);

        // Both see [1, 2].
        $preview = $service->nextNumbers(2);
        $this->assertSame([1, 2], $preview);

        // Alice saves first.
        $acicA = app(AcicService::class)->create($this->admin());
        $service->assignToAcic($alice, $acicA, $aliceRecords->pluck('id')->all(), $preview);

        // Bob's save with the same preview is refused — the numbers are no longer free.
        $acicB = app(AcicService::class)->create($this->admin());
        try {
            $service->assignToAcic($bob, $acicB, $bobRecords->pluck('id')->all(), $preview);
            $this->fail('Bob was issued numbers Alice already holds.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Check number 1 is already used. Please refresh and try again.',
                $e->errors()['check_nos'][0],
            );
        }

        // Bob refreshes and gets the next consecutive pair — no overlap with Alice's.
        $fresh = $service->nextNumbers(2);
        $this->assertSame([3, 4], $fresh);
        $service->assignToAcic($bob, $acicB, $bobRecords->pluck('id')->all(), $fresh);

        $all = Lddap::with('lddapCheck')->get()->pluck('lddapCheck.check_no')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4], $all, 'Every number issued exactly once.');
    }

    public function test_a_lower_range_registered_later_is_used_before_higher_numbers(): void
    {
        // The series starts at 500 and 500-501 get used...
        $this->seedSeries(500, 505);
        $this->assertSame([500, 501], $this->numbersOf($this->useChecks(2)));

        // ...then a lower block is registered. A batch that fits in it takes it first, not 502.
        $this->seedSeries(1, 3);

        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3, offset: 10)));
        $this->assertSame([502, 503], $this->numbersOf($this->useChecks(2, offset: 20)));
    }

    /** A batch too long for the lower block runs on from the higher one — never straddling. */
    public function test_a_batch_that_does_not_fit_the_lower_block_takes_the_next_run_that_does(): void
    {
        $this->seedSeries(500, 505);
        $this->seedSeries(1, 3);

        // Four consecutive: 1–3 is only three long, so 500–503 it is. 1–3 stay free.
        $this->assertSame([500, 501, 502, 503], $this->numbersOf($this->useChecks(4)));
        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3, offset: 10)));
    }

    public function test_real_ten_digit_check_numbers_can_be_registered_and_used(): void
    {
        // Real LDDAP-ADA numbers run to ten digits, past the 2,147,483,647 ceiling of a 4-byte
        // integer. They must register and hand out exactly like small ones.
        $this->seedSeries(9910031148, 9910031151);
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps/next-numbers?count=2')
            ->assertOk()
            ->assertJsonPath('data.numbers', [9910031148, 9910031149]);

        $this->assertSame([9910031148, 9910031149], $this->numbersOf($this->useChecks(2)));
        $this->assertSame(2, LddapCheck::where('status', LddapCheckStatus::Available)->count());
    }

    public function test_a_check_number_beyond_the_series_ceiling_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/lddaps/add-range', [
            'start_at' => '99999999999999999999',
            'end_at' => '99999999999999999999',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        $this->assertSame(0, LddapCheck::count());
    }

    /** Numbers between two registered blocks were never issued, so no run crosses the gap. */
    public function test_a_batch_never_straddles_the_gap_between_two_registered_blocks(): void
    {
        $this->seedSeries(1, 3);
        $this->seedSeries(900, 902);

        // Five consecutive do not exist anywhere in the series.
        $this->assertSame([], app(LddapService::class)->nextNumbers(5));

        // Three do, twice over — each block whole.
        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3)));
        $this->assertSame([900, 901, 902], $this->numbersOf($this->useChecks(3, offset: 10)));
    }

    public function test_next_numbers_previews_the_block_a_batch_of_that_size_would_take(): void
    {
        $this->seedSeries(40, 42);
        Sanctum::actingAs($this->staff());

        // Three consecutive exist; five do not.
        $this->getJson('/api/v1/lddaps/next-numbers?count=3')
            ->assertOk()
            ->assertJsonPath('data.numbers', [40, 41, 42])
            ->assertJsonPath('data.available', 3);

        $this->getJson('/api/v1/lddaps/next-numbers?count=5')
            ->assertOk()
            ->assertJsonPath('data.numbers', [])
            ->assertJsonPath('data.available', 3);
    }

    // ------------------------------------------------------- consecutive blocks

    /** All free: a batch of N takes N consecutive numbers from the lowest. */
    public function test_a_consecutive_block_is_assigned_when_all_numbers_are_free(): void
    {
        $this->seedSeries(1, 10);
        $this->assertSame([1, 2, 3, 4, 5], $this->numbersOf($this->useChecks(5)));
    }

    /**
     * The example from the rule: 1 and 2 free, 3 used, three records selected → the run 1–2 is
     * too short, so the batch takes 4–6 and leaves 1 and 2 free.
     */
    public function test_a_used_number_breaking_the_run_skips_the_batch_to_the_next_full_block(): void
    {
        $this->seedSeries(1, 10);
        LddapCheck::where('check_no', 3)->update(['status' => LddapCheckStatus::Used]);

        $this->assertSame([4, 5, 6], app(LddapService::class)->nextNumbers(3));
        $this->assertSame([4, 5, 6], $this->numbersOf($this->useChecks(3)));

        // 1 and 2 were skipped, not consumed.
        $this->assertSame(
            [1, 2, 7, 8, 9, 10],
            LddapCheck::where('status', LddapCheckStatus::Available)->orderBy('check_no')->pluck('check_no')->map(fn ($n) => (int) $n)->all(),
        );
    }

    /** The numbers a bigger batch skipped are exactly what a smaller later batch gets. */
    public function test_skipped_free_numbers_are_reused_by_a_smaller_later_batch(): void
    {
        $this->seedSeries(1, 10);
        LddapCheck::where('check_no', 3)->update(['status' => LddapCheckStatus::Used]);
        $this->useChecks(3);                                   // took 4–6, skipping 1–2

        $this->assertSame([1, 2], $this->numbersOf($this->useChecks(2, offset: 10)));
        $this->assertSame([7], $this->numbersOf($this->useChecks(1, offset: 20)));
    }

    /** Numbers go out in the order the records were ticked, not by id. */
    public function test_the_block_is_handed_out_in_the_order_the_records_were_listed(): void
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->approvedLddaps(3);
        [$a, $b, $c] = $lddaps->all();
        $acic = app(AcicService::class)->create($this->admin());

        app(LddapService::class)->assignToAcic($this->staff(), $acic, [$c->id, $a->id, $b->id], [1, 2, 3]);

        $this->assertSame(1, $c->fresh('lddapCheck')->lddapCheck->check_no);
        $this->assertSame(2, $a->fresh('lddapCheck')->lddapCheck->check_no);
        $this->assertSame(3, $b->fresh('lddapCheck')->lddapCheck->check_no);
    }

    /**
     * The previewed block was 1–3; someone took 3 in between. The save is refused naming 3 —
     * the number that is actually gone, not the first of the preview — and a fresh preview is
     * the next whole block.
     */
    public function test_a_number_taken_out_of_the_previewed_block_is_named_on_refusal(): void
    {
        $this->seedSeries(1, 10);
        $mine = $this->approvedLddaps(3);
        $theirs = $this->approvedLddaps(1, offset: 10, staff: $other = $this->staff());
        $service = app(LddapService::class);

        $preview = $service->nextNumbers(3);
        $this->assertSame([1, 2, 3], $preview);

        // Someone else takes 3 (a one-record batch would take 1; force the middle of the run).
        LddapCheck::where('check_no', 3)->update(['status' => LddapCheckStatus::Used]);
        unset($theirs, $other);

        $acic = app(AcicService::class)->create($this->admin());
        try {
            $service->assignToAcic($this->staff(), $acic, $mine->pluck('id')->all(), $preview);
            $this->fail('A stale preview was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Check number 3 is already used. Please refresh and try again.',
                $e->errors()['check_nos'][0],
            );
        }

        // Nothing issued; the recomputed block is the next whole run.
        $this->assertSame(0, Lddap::whereNotNull('lddap_check_id')->count());
        $this->assertSame([4, 5, 6], $service->nextNumbers(3));
        $service->assignToAcic($this->staff(), $acic, $mine->pluck('id')->all(), [4, 5, 6]);
        $this->assertSame([4, 5, 6], $this->numbersOf(
            Lddap::whereIn('id', $mine->pluck('id'))->orderBy('id')->with('lddapCheck')->get(),
        ));
    }

    public function test_assignment_is_refused_whole_when_the_series_runs_short(): void
    {
        $this->seedSeries(1, 1);
        $lddaps = $this->approvedLddaps(2);
        $acic = app(AcicService::class)->create($this->admin());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => $lddaps->pluck('id')->all()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_nos');

        // Neither record was numbered or assigned; the one number is still free.
        $this->assertSame(0, Lddap::whereNotNull('lddap_check_id')->count());
        $this->assertSame(0, Lddap::whereNotNull('acic_id')->count());
        $this->assertSame(1, LddapCheck::where('status', LddapCheckStatus::Available)->count());
    }

    // -------------------------------------------------------------- no duplicates

    public function test_a_check_number_cannot_be_registered_twice(): void
    {
        $this->seedSeries(1, 10);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/lddaps/add-range', ['start_at' => 5, 'end_at' => 15])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        $this->assertSame(10, LddapCheck::count());
    }

    public function test_a_check_number_can_only_be_held_by_one_lddap(): void
    {
        $this->seedSeries(1, 10);
        $this->useChecks(3);

        $held = Lddap::pluck('lddap_check_id');

        $this->assertSame($held->unique()->count(), $held->count());
        $this->assertSame(3, LddapCheck::where('status', LddapCheckStatus::Used)->count());
        $this->assertSame(7, LddapCheck::where('status', LddapCheckStatus::Available)->count());
    }

    /** The database itself refuses a second record on a number — `lddaps.lddap_check_id` is unique. */
    public function test_the_database_refuses_a_second_holder_of_a_check_number(): void
    {
        $this->seedSeries(1, 10);
        $numbered = $this->useChecks(1)->first();
        $another = $this->approvedLddaps(1, offset: 10)->first();

        $this->expectException(QueryException::class);

        $another->update(['lddap_check_id' => $numbered->lddap_check_id]);
    }

    /** Once an LDDAP number is used it can never be registered again — with a plain message. */
    public function test_the_same_lddap_number_cannot_be_registered_twice(): void
    {
        app(LddapService::class)->register($this->staff(), $this->details());
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', $this->details())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no')
            ->assertJsonPath(
                'errors.lddap_no.0',
                'LDDAP number LDDAP-0001 has already been registered. Each LDDAP number can be used only once.',
            );

        $this->assertSame(1, Lddap::count());
    }

    /** The database enforces it too — a duplicate that somehow skipped validation cannot land. */
    public function test_the_database_refuses_a_duplicate_lddap_number(): void
    {
        app(LddapService::class)->register($this->staff(), $this->details());

        $this->expectException(QueryException::class);

        Lddap::create([
            'lddap_no' => 'LDDAP-0001',
            'amount' => 1,
            'status' => LddapStatus::Registered,
        ]);
    }

    /** One record per submission: the batch shape is gone, and its old endpoint with it. */
    public function test_only_one_record_is_registered_per_submission(): void
    {
        Sanctum::actingAs($this->staff());

        // A batch-shaped body carries no record of its own and is refused.
        $this->postJson('/api/v1/lddaps', ['rows' => $this->rows(2)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no');
        $this->assertSame(0, Lddap::count());

        // The batch route no longer exists (the path now only resolves as GET /lddaps/{lddap}).
        $this->postJson('/api/v1/lddaps/use-cheque', ['rows' => $this->rows(2)])
            ->assertStatus(405);

        // A single record does register.
        $this->postJson('/api/v1/lddaps', $this->details())->assertCreated();
        $this->assertSame(1, Lddap::count());
    }

    // ---------------------------------------------------------------- routing

    /** Registration gives the record no check number: it is routed first. */
    public function test_a_registered_lddap_has_no_check_number_yet(): void
    {
        $this->seedSeries(1, 10);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', $this->details())
            ->assertCreated()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.status_label', 'Registered')
            ->assertJsonPath('data.can_forward', true)
            ->assertJsonPath('data.check_no', null);

        // Nothing was taken from the series, and the trail has its first entry.
        $this->assertSame(10, LddapCheck::where('status', LddapCheckStatus::Available)->count());
        $this->assertDatabaseHas('lddap_routing_history', ['action' => 'registered', 'to_status' => 'registered']);
    }

    /** Forward: Registered → For Out, with who, where, when and why on the record and the trail. */
    public function test_forward_moves_a_registered_record_to_for_out(): void
    {
        $lddap = app(LddapService::class)->register($this->staff(), $this->details());
        $unit = $this->unit();
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/forward", [
            'forward_to' => 'Budget Office',
            'unit_id' => $unit->id,
            'date_forwarded' => '2026-09-22',
            'note' => 'For obligation.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_out')
            ->assertJsonPath('data.status_label', 'For Out')
            ->assertJsonPath('data.forward_to', 'Budget Office')
            ->assertJsonPath('data.forward_unit_name', 'ACCOUNTING')
            ->assertJsonPath('data.forwarded_by.id', $staff->id)
            ->assertJsonPath('data.date_forwarded', '2026-09-22')
            ->assertJsonPath('data.can_forward', false)
            ->assertJsonPath('data.can_receive', true);

        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id,
            'action' => 'forwarded',
            'from_status' => 'registered',
            'to_status' => 'for_out',
            'user_id' => $staff->id,
            'unit_id' => $unit->id,
            'counterparty' => 'Budget Office',
            'note' => 'For obligation.',
        ]);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'forwarded_lddap']);
    }

    public function test_forward_requires_its_fields(): void
    {
        $lddap = app(LddapService::class)->register($this->staff(), $this->details());
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/forward", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['forward_to', 'unit_id', 'date_forwarded']);

        $this->assertSame(LddapStatus::Registered, $lddap->fresh()->status);
    }

    /** Receive: For Out → Returned for ACIC. */
    public function test_receive_moves_a_for_out_record_to_returned_for_acic(): void
    {
        $service = app(LddapService::class);
        $staff = $this->staff();
        $lddap = $service->forward($staff, $service->register($staff, $this->details()), $this->forwardDetails());
        $unit = $this->unit();
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive-back", [
            'unit_id' => $unit->id,
            'date_received' => '2026-09-23',
            'note' => 'Signed and returned.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned_for_acic')
            ->assertJsonPath('data.status_label', 'Returned for ACIC')
            ->assertJsonPath('data.return_unit_name', 'ACCOUNTING')
            ->assertJsonPath('data.returned_by.id', $staff->id)
            ->assertJsonPath('data.date_returned', '2026-09-23')
            ->assertJsonPath('data.can_receive', false)
            ->assertJsonPath('data.awaits_action', true);

        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id,
            'action' => 'received',
            'from_status' => 'for_out',
            'to_status' => 'returned_for_acic',
            'note' => 'Signed and returned.',
        ]);
    }

    /** Approve: Returned for ACIC → Approved, and now offered to "Assign LDDAP to ACIC". */
    public function test_approve_signs_a_returned_record_off(): void
    {
        $lddap = $this->returnedLddap();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/approve", ['note' => 'All in order.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $admin->id)
            ->assertJsonPath('data.check_no', null);

        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id, 'action' => 'approved', 'to_status' => 'approved', 'note' => 'All in order.',
        ]);

        $offered = $this->getJson('/api/v1/lddaps/linkable')->assertOk()->json('data');
        $this->assertSame([$lddap->id], array_column($offered, 'id'));
    }

    /** RTS: Returned for ACIC → RTS, with every field saved on its own history entry. */
    public function test_rts_saves_all_of_its_fields_and_sets_the_status_to_rts(): void
    {
        $lddap = $this->returnedLddap();
        $unit = $this->unit();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/rts", $this->rtsDetails())
            ->assertOk()
            ->assertJsonPath('data.status', 'rts')
            ->assertJsonPath('data.status_label', 'RTS')
            ->assertJsonPath('data.can_forward', true)
            ->assertJsonPath('data.awaits_action', false);

        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id,
            'action' => 'rts',
            'from_status' => 'returned_for_acic',
            'to_status' => 'rts',
            'user_id' => $admin->id,
            'received_by_name' => 'M. Santos',
            'unit_id' => $unit->id,
            'note' => 'Wrong OBJ code — please correct and resend.',
        ]);
        $entry = $lddap->routingHistory()->where('action', 'rts')->first();
        $this->assertSame('2026-09-23', $entry->received_on->toDateString());
        $this->assertSame('2026-09-24', $entry->acted_on->toDateString());

        // Not a verdict: nothing is stamped as reviewed, and it is not offered for an ACIC.
        $lddap->refresh();
        $this->assertNull($lddap->reviewed_at);
        $this->assertSame([], $this->getJson('/api/v1/lddaps/linkable')->json('data'));
    }

    public function test_rts_requires_every_field_and_a_comment(): void
    {
        $lddap = $this->returnedLddap();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/rts", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['received_on', 'received_by', 'unit_id', 'rts_date', 'note']);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/rts", $this->rtsDetails(['note' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertSame(LddapStatus::ReturnedForAcic, $lddap->fresh()->status);
    }

    /** An RTS record can have its details corrected — by staff request or admin edit. */
    public function test_an_rts_record_is_editable(): void
    {
        $lddap = $this->returnedLddap();
        app(LddapService::class)->rts($this->admin(), $lddap, $this->rtsDetails());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", [
            'lddap_no' => 'LDDAP-0001', 'obj_no' => 'OBJ-FIXED', 'payee_name' => 'ACME SUPPLIES INC.',
            'amount' => 100, 'reason' => 'Corrected the OBJ code as asked.',
        ])->assertCreated();

        Sanctum::actingAs($this->admin());
        $id = $lddap->updateRequests()->first()->id;
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        $lddap->refresh();
        $this->assertSame('OBJ-FIXED', $lddap->obj_no);
        // Still RTS — a correction changes details, not the routing.
        $this->assertSame(LddapStatus::Rts, $lddap->status);
    }

    /** RTS → Forward → For Out, with the same fields as the first forward. */
    public function test_an_rts_record_is_forwarded_again_to_for_out(): void
    {
        $lddap = $this->returnedLddap();
        app(LddapService::class)->rts($this->admin(), $lddap, $this->rtsDetails());
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/forward", [
            'forward_to' => 'Budget Office',
            'unit_id' => $this->unit()->id,
            'date_forwarded' => '2026-09-25',
            'note' => 'Corrected and resent.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_out')
            ->assertJsonPath('data.forward_to', 'Budget Office')
            ->assertJsonPath('data.forwarded_by.id', $staff->id)
            ->assertJsonPath('data.date_forwarded', '2026-09-25');

        // The trail says where it came from.
        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id, 'action' => 'forwarded', 'from_status' => 'rts', 'to_status' => 'for_out',
        ]);
    }

    /** Every return is kept: a record RTS'd twice has two entries, each with its own details. */
    public function test_multiple_rts_entries_are_kept(): void
    {
        $lddap = $this->returnedLddap();
        $service = app(LddapService::class);
        $staff = $this->staff();
        $admin = $this->admin();

        // Round one.
        $service->rts($admin, $lddap, $this->rtsDetails(['note' => 'First return: wrong OBJ.', 'rts_date' => '2026-09-24']));
        $lddap = $service->forward($staff, $lddap->fresh(), $this->forwardDetails());
        $lddap = $service->receive($staff, $lddap, $this->receiveDetails());

        // Round two.
        $service->rts($admin, $lddap, $this->rtsDetails(['note' => 'Second return: wrong amount.', 'rts_date' => '2026-09-28', 'received_by' => 'J. Cruz']));

        $rts = $lddap->routingHistory()->where('action', 'rts')->orderBy('id')->get();
        $this->assertCount(2, $rts);
        $this->assertSame('First return: wrong OBJ.', $rts[0]->note);
        $this->assertSame('2026-09-24', $rts[0]->acted_on->toDateString());
        $this->assertSame('M. Santos', $rts[0]->received_by_name);
        $this->assertSame('Second return: wrong amount.', $rts[1]->note);
        $this->assertSame('2026-09-28', $rts[1]->acted_on->toDateString());
        $this->assertSame('J. Cruz', $rts[1]->received_by_name);

        // The list carries the count for the badge.
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/lddaps')->assertOk()->assertJsonPath('data.0.rts_count', 2);

        // And round two ends in approval, with the whole trail intact.
        $lddap = $service->forward($staff, $lddap->fresh(), $this->forwardDetails());
        $lddap = $service->receive($staff, $lddap, $this->receiveDetails());
        $this->assertSame(LddapStatus::Approved, $service->approve($admin, $lddap)->status);
        $this->assertSame(
            ['registered', 'forwarded', 'received', 'rts', 'forwarded', 'received', 'rts', 'forwarded', 'received', 'approved'],
            $lddap->routingHistory()->pluck('action')->map(fn ($a) => $a->value)->all(),
        );
    }

    /** The view's RTS history: every entry with all five fields, newest first. */
    public function test_the_routing_trail_carries_every_rts_with_its_fields(): void
    {
        $lddap = $this->returnedLddap();
        $service = app(LddapService::class);
        $staff = $this->staff();
        $admin = $this->admin();

        $service->rts($admin, $lddap, $this->rtsDetails(['note' => 'First.', 'rts_date' => '2026-09-24']));
        $lddap = $service->receive($staff, $service->forward($staff, $lddap->fresh(), $this->forwardDetails()), $this->receiveDetails());
        $service->rts($admin, $lddap, $this->rtsDetails(['note' => 'Second.', 'rts_date' => '2026-09-28', 'received_on' => '2026-09-27', 'received_by' => 'J. Cruz']));

        Sanctum::actingAs($staff);
        $trail = $this->getJson("/api/v1/lddaps/{$lddap->id}/routing-history")->assertOk()->json('data');
        $rts = array_values(array_filter($trail, fn ($step) => $step['action'] === 'rts'));

        $this->assertCount(2, $rts);
        // Oldest first on the wire; the view reverses it.
        $this->assertSame('First.', $rts[0]['note']);
        $this->assertSame('2026-09-23', $rts[0]['received_on']);
        $this->assertSame('M. Santos', $rts[0]['received_by_name']);
        $this->assertSame('ACCOUNTING', $rts[0]['unit_name']);
        $this->assertSame('2026-09-24', $rts[0]['acted_on']);
        $this->assertSame('Second.', $rts[1]['note']);
        $this->assertSame('2026-09-27', $rts[1]['received_on']);
        $this->assertSame('J. Cruz', $rts[1]['received_by_name']);
        $this->assertSame('2026-09-28', $rts[1]['acted_on']);
    }

    /** Cancel: Returned for ACIC → Canceled, with who, when and why on the record. */
    public function test_cancel_sets_the_status_to_canceled_with_its_details(): void
    {
        $lddap = $this->returnedLddap();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/cancel", [
            'date_canceled' => '2026-09-25',
            'note' => 'Duplicate of LDDAP-0009.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.status_label', 'Canceled')
            ->assertJsonPath('data.is_final', true)
            ->assertJsonPath('data.canceled_by.id', $admin->id)
            ->assertJsonPath('data.date_canceled', '2026-09-25')
            ->assertJsonPath('data.cancel_reason', 'Duplicate of LDDAP-0009.')
            // Nothing further is offered.
            ->assertJsonPath('data.can_forward', false)
            ->assertJsonPath('data.can_receive', false)
            ->assertJsonPath('data.awaits_action', false);

        $this->assertDatabaseHas('lddap_routing_history', [
            'lddap_id' => $lddap->id, 'action' => 'canceled', 'from_status' => 'returned_for_acic',
            'to_status' => 'canceled', 'user_id' => $admin->id, 'note' => 'Duplicate of LDDAP-0009.',
        ]);
        $this->assertSame('2026-09-25', $lddap->routingHistory()->where('action', 'canceled')->first()->acted_on->toDateString());

        // The date defaults to today when the dialog leaves it out.
        $other = $this->returnedLddap(n: 2);
        $this->postJson("/api/v1/lddaps/{$other->id}/cancel", ['note' => 'Withdrawn by the requesting unit.'])
            ->assertOk()
            ->assertJsonPath('data.date_canceled', now()->toDateString());
    }

    public function test_cancel_requires_a_reason(): void
    {
        $lddap = $this->returnedLddap();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/lddaps/{$lddap->id}/cancel", ['note' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertSame(LddapStatus::ReturnedForAcic, $lddap->fresh()->status);
        $this->assertNull($lddap->fresh()->canceled_by);
    }

    /** Once canceled, the LDDAP number is still taken: it can never be registered again. */
    public function test_a_canceled_records_lddap_number_stays_used(): void
    {
        $lddap = $this->returnedLddap();
        app(LddapService::class)->cancel($this->admin(), $lddap, ['note' => 'Withdrawn.']);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', $this->details())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no')
            ->assertJsonPath(
                'errors.lddap_no.0',
                'LDDAP number LDDAP-0001 has already been registered. Each LDDAP number can be used only once.',
            );

        $this->assertSame(1, Lddap::where('lddap_no', 'LDDAP-0001')->count());
        $this->assertSame(LddapStatus::Canceled, Lddap::where('lddap_no', 'LDDAP-0001')->first()->status);
    }

    public function test_a_canceled_record_is_read_only(): void
    {
        $lddap = $this->returnedLddap();
        $service = app(LddapService::class);
        $service->cancel($this->admin(), $lddap, ['note' => 'Withdrawn.']);
        $lddap->refresh();

        // Not forwarded, not received, not acted on again.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/forward", $this->forwardDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive-back", $this->receiveDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$lddap->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$lddap->id}/rts", $this->rtsDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$lddap->id}/cancel", ['note' => 'Again.'])->assertStatus(422);

        // Not edited — a correction is refused, by staff or directly by an admin.
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", [
            'lddap_no' => 'LDDAP-0001', 'obj_no' => 'X', 'payee_name' => 'Y', 'amount' => 5, 'reason' => 'Trying anyway.',
        ])->assertStatus(422);
        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001', 'obj_no' => 'X', 'payee_name' => 'Y', 'amount' => 5, 'reason' => 'Trying anyway.',
        ])->assertStatus(422);

        // Not assigned — never offered by "Assign LDDAP to ACIC", and forcing it is refused on
        // both the ACIC route and the by-number route.
        $this->assertSame([], $this->getJson('/api/v1/lddaps/linkable')->json('data'));
        $acic = app(AcicService::class)->create($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => [$lddap->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_ids');
        $this->postJson('/api/v1/lddaps/assign-acic', ['acic_no' => $acic->acic_number, 'lddap_ids' => [$lddap->id]])
            ->assertStatus(422);
        $this->assertNull($lddap->fresh()->acic_id);
        $this->assertNull($lddap->fresh()->lddap_check_id);

        $this->assertSame(LddapStatus::Canceled, $lddap->fresh()->status);
    }

    /** A canceled record sits beside approved ones and is still left out of the assign dialog. */
    public function test_canceled_records_are_excluded_from_acic_assignment(): void
    {
        $this->seedSeries(1, 10);
        $approved = $this->approvedLddaps(2);
        $canceled = $this->returnedLddap(n: 5);
        app(LddapService::class)->cancel($this->admin(), $canceled, ['note' => 'Withdrawn.']);

        Sanctum::actingAs($this->staff());
        $offered = $this->getJson('/api/v1/lddaps/linkable')->assertOk()->json('data');

        $this->assertSame($approved->pluck('id')->all(), array_column($offered, 'id'));
        $this->assertNotContains($canceled->id, array_column($offered, 'id'));
    }

    /** Every status allows exactly one next step; every other step is refused. */
    public function test_only_the_next_valid_step_is_allowed_for_each_status(): void
    {
        $service = app(LddapService::class);
        $staff = $this->staff();
        $admin = $this->admin();

        $registered = $service->register($staff, $this->details(1));
        $forOut = $service->forward($staff, $service->register($staff, $this->details(2)), $this->forwardDetails());
        $returned = $this->returnedLddap(n: 3);
        $approved = $service->approve($admin, $this->returnedLddap(n: 4));

        Sanctum::actingAs($admin);

        // Registered: only Forward.
        $this->postJson("/api/v1/lddaps/{$registered->id}/receive-back", $this->receiveDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$registered->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$registered->id}/rts", $this->rtsDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$registered->id}/cancel", ['note' => 'xyz'])->assertStatus(422);

        // For Out: only Receive.
        $this->postJson("/api/v1/lddaps/{$forOut->id}/forward", $this->forwardDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$forOut->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$forOut->id}/rts", $this->rtsDetails())->assertStatus(422);

        // Returned for ACIC: only Approve / RTS / Cancel.
        $this->postJson("/api/v1/lddaps/{$returned->id}/forward", $this->forwardDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$returned->id}/receive-back", $this->receiveDetails())->assertStatus(422);

        // RTS: only Forward.
        $rts = app(LddapService::class)->rts($admin, $this->returnedLddap(n: 5), $this->rtsDetails());
        $this->postJson("/api/v1/lddaps/{$rts->id}/receive-back", $this->receiveDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$rts->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$rts->id}/rts", $this->rtsDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$rts->id}/cancel", ['note' => 'xyz'])->assertStatus(422);
        $this->assertSame(LddapStatus::Rts, $rts->fresh()->status);

        // Approved: nothing further in the routing.
        foreach (['forward', 'receive-back', 'approve'] as $step) {
            $body = $step === 'forward' ? $this->forwardDetails() : ($step === 'receive-back' ? $this->receiveDetails() : []);
            $this->postJson("/api/v1/lddaps/{$approved->id}/{$step}", $body)->assertStatus(422);
        }
        $this->postJson("/api/v1/lddaps/{$approved->id}/rts", $this->rtsDetails())->assertStatus(422);
        $this->postJson("/api/v1/lddaps/{$approved->id}/cancel", ['note' => 'xyz'])->assertStatus(422);

        $this->assertSame(LddapStatus::Registered, $registered->fresh()->status);
        $this->assertSame(LddapStatus::ForOut, $forOut->fresh()->status);
        $this->assertSame(LddapStatus::ReturnedForAcic, $returned->fresh()->status);
        $this->assertSame(LddapStatus::Approved, $approved->fresh()->status);
    }

    public function test_the_admin_actions_are_admin_only_and_the_routing_steps_are_not_for_tellers(): void
    {
        $lddap = $this->returnedLddap();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/lddaps/{$lddap->id}/rts", $this->rtsDetails())->assertForbidden();
        $this->postJson("/api/v1/lddaps/{$lddap->id}/cancel", ['note' => 'xyz'])->assertForbidden();

        $fresh = app(LddapService::class)->register($this->staff(), $this->details(2));
        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$fresh->id}/forward", $this->forwardDetails())->assertForbidden();
        $this->postJson('/api/v1/lddaps', $this->details(3))->assertForbidden();
    }

    /** The trail reads back with user, date and note on every step. */
    public function test_the_routing_trail_is_readable_on_the_record(): void
    {
        $lddap = $this->returnedLddap();
        app(LddapService::class)->approve($this->admin(), $lddap, 'OK.');
        Sanctum::actingAs($this->teller());

        $trail = $this->getJson("/api/v1/lddaps/{$lddap->id}/routing-history")->assertOk()->json('data');

        $this->assertSame(['registered', 'forwarded', 'received', 'approved'], array_column($trail, 'action'));
        $this->assertSame('Accounting Division', $trail[1]['counterparty']);
        $this->assertSame('ACCOUNTING', $trail[1]['unit_name']);
        $this->assertSame('2026-09-22', $trail[1]['acted_on']);
        $this->assertSame('For signature.', $trail[1]['note']);
        $this->assertSame('Signed.', $trail[2]['note']);
        $this->assertSame('OK.', $trail[3]['note']);
        foreach ($trail as $step) {
            $this->assertNotNull($step['user']['name']);
        }
    }

    /** The check number arrives with the ACIC assignment, and only then. */
    public function test_a_record_takes_its_check_number_when_it_goes_on_an_acic(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->approvedLddaps(1)->first();
        $this->assertNull($lddap->lddap_check_id);
        $acic = app(AcicService::class)->create($this->admin());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => [$lddap->id], 'expected_check_nos' => [1]])
            ->assertOk()
            ->assertJsonPath('data.lddaps.0.check_no', 1);

        $lddap->refresh();
        $this->assertSame($acic->id, $lddap->acic_id);
        $this->assertSame(LddapStatus::Approved, $lddap->status);
        $this->assertNotNull($lddap->used_at);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'used_lddap_check']);
    }

    /** Receipt presupposes a check number, i.e. an ACIC. */
    public function test_a_record_without_a_check_number_cannot_be_received(): void
    {
        $lddap = $this->approvedLddaps(1)->first();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")->assertStatus(422);
    }

    // ---------------------------------------------- the "Returned" data migration

    /** Records left on the retired statuses land on Returned for ACIC, numbers intact. */
    public function test_records_on_the_old_returned_status_are_migrated_to_returned_for_acic(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        $ids = [];
        foreach (['compliance', 'returned_from_routing', 'used', 'received'] as $i => $old) {
            $lddap = $service->register($staff, $this->details($i + 1));
            // The status column is a plain string, so the retired values can still be written.
            DB::table('lddaps')->where('id', $lddap->id)->update(['status' => $old, 'lddap_check_id' => $i + 1]);
            $ids[$old] = $lddap->id;
        }
        $untouched = $service->register($staff, $this->details(9));

        $migration = require database_path('migrations/2026_09_22_000000_route_lddaps_through_for_out_and_returned_for_acic.php');
        $migration->remap();

        foreach ($ids as $old => $id) {
            $row = Lddap::find($id);
            $this->assertSame(LddapStatus::ReturnedForAcic, $row->status, "{$old} → returned_for_acic");
            $this->assertNotNull($row->lddap_check_id, "{$old} keeps its check number");
        }
        $this->assertSame(LddapStatus::Registered, $untouched->fresh()->status);

        // And the migrated records take the admin's action like any other returned record.
        $this->assertSame(
            LddapStatus::Approved,
            $service->approve($this->admin(), Lddap::find($ids['compliance']))->status,
        );
    }

    /** Records canceled under the old spelling come across with their details backfilled. */
    public function test_records_canceled_under_the_old_spelling_are_remapped(): void
    {
        $lddap = $this->returnedLddap();
        $admin = $this->admin();
        // As the old Cancel left them: status "cancelled", stamped as a review, no details.
        DB::table('lddaps')->where('id', $lddap->id)->update([
            'status' => 'cancelled',
            'reviewed_by' => $admin->id,
            'reviewed_at' => '2026-09-20 10:00:00',
            'review_note' => 'Old-style cancellation.',
        ]);
        DB::table('lddap_routing_history')->insert([
            'lddap_id' => $lddap->id, 'action' => 'cancelled', 'from_status' => 'returned_for_acic',
            'to_status' => 'cancelled', 'user_id' => $admin->id, 'note' => 'Old-style cancellation.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_22_000200_add_cancellation_details_to_lddaps.php');
        $migration->remap();

        $row = Lddap::find($lddap->id);
        $this->assertSame(LddapStatus::Canceled, $row->status);
        $this->assertSame($admin->id, $row->canceled_by);
        $this->assertSame('2026-09-20', $row->date_canceled->toDateString());
        $this->assertSame('Old-style cancellation.', $row->cancel_reason);
        $this->assertSame(0, DB::table('lddap_routing_history')->where('to_status', 'cancelled')->orWhere('action', 'cancelled')->count());
        $this->assertSame(1, DB::table('lddap_routing_history')->where('action', 'canceled')->where('lddap_id', $lddap->id)->count());
    }

    // ------------------------------------------------------- registration details

    /** Every detail the dialog collects lands on the record and reads back. */
    public function test_a_registration_stores_all_of_its_details(): void
    {
        $this->seedSeries();
        $unit = $this->unit();
        $payee = $this->payee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', [
            'lddap_no' => 'LDDAP-0001',
            'nca_no' => 'NCA-2026-09-001',
            'orb_no' => 'ORB-2026-09-044',
            'dv_no' => 'DV-2026-09-0187',
            'nature_of_payment' => 'local_travel',
            'obj_no' => '5021304001',
            'unit_id' => $unit->id,
            'check_date' => '2026-09-15',
            'payee_id' => $payee->id,
            'gross_amount' => 1591049.00,
        ])->assertCreated();

        $row = $this->getJson('/api/v1/lddaps')->assertOk()->json('data.0');

        $this->assertSame('NCA-2026-09-001', $row['nca_no']);
        $this->assertSame('ORB-2026-09-044', $row['orb_no']);
        $this->assertSame('DV-2026-09-0187', $row['dv_no']);
        $this->assertSame('local_travel', $row['nature_of_payment']);
        $this->assertSame('LOCAL TRAVEL', $row['nature_of_payment_label']);
        $this->assertSame('5021304001', $row['obj_no']);
        $this->assertSame($unit->id, $row['unit_id']);
        $this->assertSame('ACCOUNTING', $row['unit_name']);
        // The date of issue is the one entered, not the day of registration.
        $this->assertSame('2026-09-15', $row['check_date']);
        // Registered, out for routing, no check number yet.
        $this->assertSame('registered', $row['status']);
        $this->assertNull($row['check_no']);
    }

    /**
     * The payee is chosen from the register, and its name and account are copied onto the
     * record — so the LDDAP reads the same even if the payee is edited later.
     */
    public function test_a_registration_copies_the_payee_name_and_account(): void
    {
        $this->seedSeries();
        $payee = $this->payee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', $this->details())->assertCreated();

        $lddap = Lddap::first();
        $this->assertSame($payee->id, $lddap->payee_id);
        $this->assertSame('ACME SUPPLIES INC.', $lddap->payee_name);
        // The lone account was picked without being named.
        $this->assertSame($payee->accounts->first()->id, $lddap->payee_account_id);
        $this->assertSame('2028-9010-11', $lddap->payee_account_no);
        $this->assertSame('LBP', $lddap->payee_bank);

        // Editing the payee or the account afterwards does not rewrite history.
        $payee->update(['name' => 'ACME SUPPLIES CORP.']);
        $payee->accounts->first()->update(['account_no' => '9999-0000-00', 'bank' => 'DBP']);
        $lddap->refresh();
        $this->assertSame('ACME SUPPLIES INC.', $lddap->payee_name);
        $this->assertSame('2028-9010-11', $lddap->payee_account_no);
        $this->assertSame('LBP', $lddap->payee_bank);
    }

    public function test_a_registration_requires_each_new_detail(): void
    {
        $this->seedSeries();
        Sanctum::actingAs($this->staff());

        $row = $this->details();
        unset($row['nca_no'], $row['orb_no'], $row['dv_no'], $row['nature_of_payment'], $row['unit_id'], $row['check_date'], $row['payee_id']);

        $this->postJson('/api/v1/lddaps', $row)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'nca_no',
                'orb_no',
                'dv_no',
                'nature_of_payment',
                'unit_id',
                'check_date',
                'payee_id',
            ]);

        // The UACS object code stays optional, as OBJ No. always was.
        $this->assertDatabaseCount('lddaps', 0);
    }

    public function test_a_registration_rejects_a_nature_unit_or_payee_not_on_the_list(): void
    {
        $this->seedSeries();
        Sanctum::actingAs($this->staff());

        $row = $this->details();
        $row['nature_of_payment'] = 'bribery';
        $row['unit_id'] = 9999;
        $row['payee_id'] = 9999;

        $this->postJson('/api/v1/lddaps', $row)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nature_of_payment', 'unit_id', 'payee_id']);
    }

    // ------------------------------------------------------ the reference lists

    public function test_the_options_endpoint_lists_every_nature_and_unit(): void
    {
        Unit::create(['name' => 'BUDGET']);
        Unit::create(['name' => 'ACCOUNTING']);
        Sanctum::actingAs($this->staff());

        $data = $this->getJson('/api/v1/lddaps/options')->assertOk()->json('data');

        $this->assertCount(count(NatureOfPayment::cases()), $data['natures']);
        $this->assertSame('PAYROLL / PERSONAL CLAIMS', $data['natures'][0]['label']);
        $this->assertSame('payroll_personal_claims', $data['natures'][0]['value']);
        // All caps, every one.
        foreach ($data['natures'] as $nature) {
            $this->assertSame(strtoupper($nature['label']), $nature['label']);
        }

        $this->assertSame(['ACCOUNTING', 'BUDGET'], array_column($data['units'], 'name'));
    }

    public function test_a_payee_can_be_fetched_with_its_accounts_for_the_edit_form(): void
    {
        $payee = $this->multiAccountPayee();
        Sanctum::actingAs($this->staff());

        $this->getJson("/api/v1/payees/{$payee->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payee->id)
            ->assertJsonPath('data.name', 'BLUEOCEAN LOGISTICS')
            ->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.1.account_no', '0451-2210-07');
        $this->getJson('/api/v1/payees/999999')->assertNotFound();
    }

    public function test_payees_are_searched_by_name_or_account_number(): void
    {
        $this->payee();
        $this->multiAccountPayee();
        Payee::create(['name' => 'METRO RENTALS'])->accounts()->create(['account_no' => '3001-0000-55', 'bank' => 'LBP']);
        Sanctum::actingAs($this->staff());

        // By name, case-insensitively — with every account the payee holds, labelled.
        $this->getJson('/api/v1/payees?search=blueocean')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'BLUEOCEAN LOGISTICS')
            ->assertJsonCount(2, 'data.0.accounts')
            ->assertJsonPath('data.0.accounts.0.account_no', '1701-0426-18')
            ->assertJsonPath('data.0.accounts.0.bank', 'LBP')
            ->assertJsonPath('data.0.accounts.0.label', '1701-0426-18 – LBP')
            ->assertJsonPath('data.0.accounts.1.label', '0451-2210-07 – DBP');

        // By any of a payee's account numbers.
        $this->getJson('/api/v1/payees?search=0451-2210')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'BLUEOCEAN LOGISTICS');

        // No term lists them all, name order.
        $this->getJson('/api/v1/payees')->assertOk()->assertJsonCount(3, 'data');
    }

    // ---------------------------------------------------------- payee accounts

    /** With several accounts on file, the one the payment goes to must be chosen. */
    public function test_a_payee_with_several_accounts_needs_one_chosen(): void
    {
        $payee = $this->multiAccountPayee();
        Sanctum::actingAs($this->staff());

        $details = array_merge($this->details(), ['payee_id' => $payee->id]);

        // No choice: refused, and told why.
        $this->postJson('/api/v1/lddaps', $details)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payee_account_id');

        // Choosing the second account records that account and its bank.
        $dbp = $payee->accounts->firstWhere('bank', 'DBP');
        $this->postJson('/api/v1/lddaps', $details + ['payee_account_id' => $dbp->id])
            ->assertCreated()
            ->assertJsonPath('data.payee_account_no', '0451-2210-07')
            ->assertJsonPath('data.payee_bank', 'DBP');
    }

    /** An account has to be the chosen payee's own. */
    public function test_another_payees_account_is_refused(): void
    {
        $acme = $this->payee();
        $other = $this->multiAccountPayee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', array_merge($this->details(), [
            'payee_id' => $acme->id,
            'payee_account_id' => $other->accounts->first()->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payee_account_id');

        $this->assertSame(0, Lddap::count());
    }

    // ------------------------------------------------------- the payment breakdown

    /** The form's rule, exactly: withheld = (gross / 1.12) × rate, to two decimals, in decimals. */
    public function test_withholding_is_computed_on_the_vat_exclusive_base(): void
    {
        $this->assertSame('100000.00', Tax::base('112000.00'));
        $this->assertSame('2000.00', Tax::withheld('112000.00', '0.02'));
        $this->assertSame('1000.00', Tax::withheld('112000.00', '0.01'));
        $this->assertSame('5000.00', Tax::withheld('112000.00', '0.05'));
        $this->assertSame('12000.00', Tax::withheld('112000.00', '0.12'));
        $this->assertSame('30000.00', Tax::withheld('112000.00', '0.30'));

        // Rounds to the centavo rather than truncating.
        $this->assertSame('0.89', Tax::withheld('100', '0.01'));
        $this->assertSame('71028.97', Tax::withheld('1591049.00', '0.05'));

        // And the money math is decimal, not float.
        $this->assertSame('0.30', Money::sum(['0.1', '0.2']));
        $this->assertSame('2.68', Money::of('2.675'));
        $this->assertSame(Tax::WTAX_RATES, ['0.01', '0.02', '0.03', '0.05']);
        $this->assertSame(Tax::VAT_RATES, ['0.01', '0.02', '0.03', '0.05', '0.10', '0.12', '0.30']);
    }

    /** Every amount lands as entered, and the net payable is derived from them. */
    public function test_a_registration_saves_the_whole_payment_breakdown(): void
    {
        $this->payee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', array_merge($this->details(), [
            'acic_ref' => '25-10-248',
            'gross_amount' => '112000.00',
            'wtax_1' => '0',
            'wtax_2' => '2000.00',        // 112,000 / 1.12 × 0.02
            'vat_5' => '5000.00',         // 112,000 / 1.12 × 0.05
            'vat_12' => '12000.00',
            'retention' => '1000.00',
            'liquidated_damages' => '250.50',
            'advance_payment' => '0',
            'fwd_to_lbp_at' => '2026-09-22',
            'date_loaded' => '2026-09-23',
            'note' => 'Progress billing no. 3, net of retention.',
            'remarks' => 'Rush',
        ]))->assertCreated();

        $row = $this->getJson('/api/v1/lddaps')->assertOk()->json('data.0');

        $this->assertSame('25-10-248', $row['acic_ref']);
        $this->assertSame('112000.00', $row['gross_amount']);
        $this->assertSame('2000.00', $row['wtax']['0.02']);
        $this->assertSame('0.00', $row['wtax']['0.01']);
        $this->assertSame('5000.00', $row['vat']['0.05']);
        $this->assertSame('12000.00', $row['vat']['0.12']);
        $this->assertSame('1000.00', $row['retention']);
        $this->assertSame('250.50', $row['liquidated_damages']);
        $this->assertSame('0.00', $row['advance_payment']);
        $this->assertSame('2026-09-22', $row['fwd_to_lbp_at']);
        $this->assertSame('2026-09-23', $row['date_loaded']);
        $this->assertSame('Progress billing no. 3, net of retention.', $row['note']);
        $this->assertSame('Rush', $row['remarks']);

        // Net = 112,000 − 2,000 − 5,000 − 12,000 − 1,000 − 250.50, to the centavo.
        $this->assertSame('91749.50', $row['amount']);
    }

    public function test_deductions_exceeding_the_gross_are_refused(): void
    {
        $this->payee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', array_merge($this->details(), [
            'gross_amount' => '100.00',
            'wtax_5' => '60.00',
            'retention' => '50.00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gross_amount');

        $this->assertSame(0, Lddap::count());
    }

    public function test_amounts_carry_at_most_two_decimals(): void
    {
        $this->payee();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps', array_merge($this->details(), ['wtax_2' => '1.005']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('wtax_2');
    }

    // ------------------------------------------------------------------ permissions

    public function test_only_an_admin_can_register_the_check_series(): void
    {
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/lddaps/add-range', ['start_at' => 1, 'end_at' => 5])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/lddaps/add-range', ['start_at' => 1, 'end_at' => 5])->assertCreated();

        $this->assertSame(5, LddapCheck::count());
    }

    public function test_a_guest_cannot_read_the_lddap_table(): void
    {
        $this->getJson('/api/v1/lddaps')->assertUnauthorized();
    }

    // ------------------------------------------------------------------- lifecycle

    /** Once on an ACIC — numbered — the teller confirms receipt; the status is unchanged. */
    public function test_the_teller_confirms_receipt(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();

        $teller = $this->teller();
        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_received', true)
            ->assertJsonPath('data.received_by.id', $teller->id);

        // And only once.
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")->assertStatus(422);
    }

    public function test_only_a_teller_can_confirm_receipt(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")->assertForbidden();
    }

    // -------------------------------------------------------------- editing a record

    /** The edit form's payload: the record's details with the given fields changed. */
    private function edited(Lddap $lddap, array $changes = []): array
    {
        return array_merge([
            'lddap_no' => $lddap->lddap_no,
            'nca_no' => $lddap->nca_no,
            'orb_no' => $lddap->orb_no,
            'dv_no' => $lddap->dv_no,
            'nature_of_payment' => $lddap->nature_of_payment->value,
            'obj_no' => $lddap->obj_no,
            'unit_id' => $lddap->unit_id,
            'check_date' => $lddap->check_date->toDateString(),
            'payee_id' => $lddap->payee_id,
            'payee_account_id' => $lddap->payee_account_id,
            'acic_ref' => $lddap->acic_ref,
            'gross_amount' => $lddap->gross_amount,
            'wtax_1' => $lddap->wtax_1, 'wtax_2' => $lddap->wtax_2, 'wtax_3' => $lddap->wtax_3, 'wtax_5' => $lddap->wtax_5,
            'vat_1' => $lddap->vat_1, 'vat_2' => $lddap->vat_2, 'vat_3' => $lddap->vat_3, 'vat_5' => $lddap->vat_5,
            'vat_10' => $lddap->vat_10, 'vat_12' => $lddap->vat_12, 'vat_30' => $lddap->vat_30,
            'retention' => $lddap->retention,
            'liquidated_damages' => $lddap->liquidated_damages,
            'advance_payment' => $lddap->advance_payment,
            'fwd_to_lbp_at' => $lddap->fwd_to_lbp_at?->toDateString(),
            'date_loaded' => $lddap->date_loaded?->toDateString(),
            'note' => $lddap->note,
            'remarks' => $lddap->remarks,
        ], $changes);
    }

    /** The table row carries every field the edit form pre-fills, and whether Edit is offered. */
    public function test_the_record_carries_every_field_the_edit_form_pre_fills(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $payee = $this->multiAccountPayee();

        app(LddapService::class)->register($staff, [
            'payee_id' => $payee->id,
            'payee_account_id' => $payee->accounts[1]->id,
            'acic_ref' => 'ACIC-77',
            'gross_amount' => '11200.00',
            'wtax_2' => '200.00',
            'vat_5' => '500.00',
            'retention' => '100.00',
            'liquidated_damages' => '50.00',
            'advance_payment' => '25.00',
            'fwd_to_lbp_at' => '2026-09-25',
            'date_loaded' => '2026-09-26',
            'note' => 'Handle with care.',
            'remarks' => 'Urgent',
        ] + $this->details(1));

        Sanctum::actingAs($staff);

        $row = $this->getJson('/api/v1/lddaps')->assertOk()->json('data.0');

        foreach ([
            'lddap_no' => 'LDDAP-0001', 'nca_no' => 'NCA-1', 'orb_no' => 'ORB-1', 'dv_no' => 'DV-1',
            'nature_of_payment' => 'commercial_claims', 'obj_no' => 'OBJ-1', 'unit_id' => $this->unit()->id,
            'check_date' => '2026-09-21',
            'payee_id' => $payee->id, 'payee_account_id' => $payee->accounts[1]->id,
            'payee_name' => 'BLUEOCEAN LOGISTICS', 'payee_account_no' => '0451-2210-07', 'payee_bank' => 'DBP',
            'acic_ref' => 'ACIC-77',
            'gross_amount' => '11200.00',
            'retention' => '100.00', 'liquidated_damages' => '50.00', 'advance_payment' => '25.00',
            'fwd_to_lbp_at' => '2026-09-25', 'date_loaded' => '2026-09-26',
            'note' => 'Handle with care.', 'remarks' => 'Urgent',
            'amount' => '10325.00',
            'check_no' => null,
            'can_edit' => true,
        ] as $field => $value) {
            $this->assertSame($value, $row[$field], $field);
        }
        $this->assertSame('200.00', $row['wtax']['0.02']);
        $this->assertSame('0.00', $row['wtax']['0.01']);
        $this->assertSame('500.00', $row['vat']['0.05']);
        $this->assertSame('0.00', $row['vat']['0.30']);
    }

    public function test_a_registered_record_can_be_edited_through_the_register_form(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $payee = $this->multiAccountPayee();
        $lddap = app(LddapService::class)->register($staff, $this->details(1));

        Sanctum::actingAs($staff);

        $response = $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap, [
            'lddap_no' => 'LDDAP-0001-A',
            'dv_no' => 'DV-1-REV',
            'nature_of_payment' => 'rental',
            'obj_no' => '5029905003',
            'payee_id' => $payee->id,
            'payee_account_id' => $payee->accounts[0]->id,
            'gross_amount' => '11200.00',
            'wtax_2' => '200.00',
            'vat_12' => '1200.00',
            'retention' => '100.00',
            'fwd_to_lbp_at' => '2026-09-25',
            'note' => 'Corrected after RTS.',
        ]))
            ->assertOk()
            ->assertJsonPath('data.lddap_no', 'LDDAP-0001-A')
            ->assertJsonPath('data.dv_no', 'DV-1-REV')
            ->assertJsonPath('data.nature_of_payment', 'rental')
            ->assertJsonPath('data.obj_no', '5029905003')
            ->assertJsonPath('data.payee_name', 'BLUEOCEAN LOGISTICS')
            ->assertJsonPath('data.payee_account_no', '1701-0426-18')
            ->assertJsonPath('data.payee_bank', 'LBP')
            ->assertJsonPath('data.gross_amount', '11200.00')
            // Net payable is re-derived: 11200 − 200 − 1200 − 100.
            ->assertJsonPath('data.amount', '9700.00')
            ->assertJsonPath('data.fwd_to_lbp_at', '2026-09-25')
            ->assertJsonPath('data.note', 'Corrected after RTS.')
            // Untouched by the edit.
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.check_no', null)
            ->assertJsonPath('data.used_by.id', $staff->id);
        // The rate maps are keyed by rate, which dot-notation would split.
        $this->assertSame('200.00', $response->json('data.wtax')['0.02']);
        $this->assertSame('1200.00', $response->json('data.vat')['0.12']);

        // The edit is kept: who, when, and each changed field's before and after.
        $entry = $lddap->editHistory()->sole();
        $this->assertSame($staff->id, $entry->user_id);
        $this->assertNotNull($entry->created_at);
        $this->assertSame(['from' => 'LDDAP-0001', 'to' => 'LDDAP-0001-A'], $entry->changes['lddap_no']);
        $this->assertSame(['from' => 'commercial_claims', 'to' => 'rental'], $entry->changes['nature_of_payment']);
        $this->assertSame(['from' => '100.00', 'to' => '9700.00'], $entry->changes['amount']);
        $this->assertSame(['from' => null, 'to' => '2026-09-25'], $entry->changes['fwd_to_lbp_at']);
        $this->assertArrayNotHasKey('nca_no', $entry->changes);
        $this->assertArrayNotHasKey('orb_no', $entry->changes);
        $this->assertDatabaseHas('cheque_logs', ['username' => $staff->username, 'action' => 'updated_lddap']);

        $this->getJson("/api/v1/lddaps/{$lddap->id}/edit-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $staff->id)
            ->assertJsonPath('data.0.changes.dv_no.to', 'DV-1-REV');
    }

    public function test_an_edit_keeps_the_records_own_lddap_number(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $lddap = app(LddapService::class)->register($staff, $this->details(1));

        Sanctum::actingAs($staff);

        // Same number, only the DV changes — its own number is not a duplicate of itself.
        $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap, ['dv_no' => 'DV-1-REV']))
            ->assertOk()
            ->assertJsonPath('data.lddap_no', 'LDDAP-0001')
            ->assertJsonPath('data.dv_no', 'DV-1-REV');

        $this->assertSame(['dv_no'], array_keys($lddap->editHistory()->sole()->changes));
    }

    public function test_an_edit_may_not_take_another_records_lddap_number(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $service = app(LddapService::class);
        $mine = $service->register($staff, $this->details(1));
        $service->register($staff, $this->details(2));   // LDDAP-0002

        Sanctum::actingAs($staff);

        $this->putJson("/api/v1/lddaps/{$mine->id}", $this->edited($mine, ['lddap_no' => 'LDDAP-0002']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lddap_no' => 'already been registered']);

        $this->assertSame('LDDAP-0001', $mine->fresh()->lddap_no);
        $this->assertSame(0, $mine->editHistory()->count());
    }

    /** The check number is not a field of the form; sending one changes nothing. */
    public function test_an_edit_never_touches_the_check_number(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $lddap = app(LddapService::class)->register($staff, $this->details(1));
        $check = LddapCheck::query()->where('check_no', 3)->firstOrFail();

        Sanctum::actingAs($staff);

        $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap, [
            'dv_no' => 'DV-1-REV',
            'lddap_check_id' => $check->id,
            'check_no' => 3,
            'status' => 'approved',
        ]))
            ->assertOk()
            ->assertJsonPath('data.check_no', null)
            ->assertJsonPath('data.status', 'registered');

        $this->assertNull($lddap->fresh()->lddap_check_id);
        $this->assertSame(LddapCheckStatus::Available, $check->fresh()->status);
    }

    public function test_only_registered_and_rts_records_can_be_edited(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();
        $admin = $this->admin();

        $forOut = $service->forward($staff, $service->register($staff, $this->details(2)), $this->forwardDetails());
        $returned = $this->returnedLddap($staff, 3);
        $approved = $service->approve($admin, $this->returnedLddap($staff, 4));
        $canceled = $service->cancel($admin, $this->returnedLddap($staff, 5), ['note' => 'Withdrawn.']);
        $rts = $service->rts($admin, $this->returnedLddap($staff, 6), $this->rtsDetails());

        Sanctum::actingAs($staff);

        foreach ([$forOut, $returned, $approved, $canceled] as $fixed) {
            $this->putJson("/api/v1/lddaps/{$fixed->id}", $this->edited($fixed, ['dv_no' => 'CHANGED']))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['lddap' => 'cannot be edited']);
            $this->assertNotSame('CHANGED', $fixed->fresh()->dv_no);
            $this->assertFalse($this->getJson("/api/v1/lddaps?search={$fixed->lddap_no}")->json('data.0.can_edit'));
        }

        // An RTS record is back in the registrant's hands: editable, and its status stays RTS.
        $this->putJson("/api/v1/lddaps/{$rts->id}", $this->edited($rts, ['dv_no' => 'DV-6-REV']))
            ->assertOk()
            ->assertJsonPath('data.dv_no', 'DV-6-REV')
            ->assertJsonPath('data.status', 'rts')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_an_edit_is_refused_while_a_correction_request_pends(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $lddap = app(LddapService::class)->register($staff, $this->details(1));
        app(LddapUpdateRequestService::class)->create($staff, $lddap, ['lddap_no' => 'LDDAP-0001', 'obj_no' => null, 'payee_name' => 'X', 'amount' => '100.00'], 'Wrong payee.');

        Sanctum::actingAs($staff);

        $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap, ['dv_no' => 'CHANGED']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lddap' => 'on hold']);
    }

    public function test_an_edit_that_changes_nothing_leaves_no_history(): void
    {
        $this->seedSeries(1, 10);
        $staff = $this->staff();
        $lddap = app(LddapService::class)->register($staff, $this->details(1));

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap))->assertOk();

        $this->assertSame(0, $lddap->editHistory()->count());
        $this->assertDatabaseMissing('cheque_logs', ['action' => 'updated_lddap']);
    }

    public function test_a_teller_cannot_edit(): void
    {
        $this->seedSeries(1, 10);
        $lddap = app(LddapService::class)->register($this->staff(), $this->details(1));

        Sanctum::actingAs($this->teller());

        $this->putJson("/api/v1/lddaps/{$lddap->id}", $this->edited($lddap, ['dv_no' => 'CHANGED']))->assertForbidden();
    }

    // -------------------------------------------------------------- the filter bar

    /**
     * Six records, one on each status — the approved one sits on an ACIC and holds check
     * number 1 — so every filter has exactly one row to find.
     */
    private function oneOfEachStatus(): User
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        $this->useChecks(1);                                                          // approved, on an ACIC
        $service->register($staff, $this->details(2));                                // registered
        $service->forward($staff, $service->register($staff, $this->details(3)), $this->forwardDetails()); // for out
        $this->returnedLddap(n: 4);                                                   // returned for ACIC
        $service->cancel($this->admin(), $this->returnedLddap(n: 5), ['note' => 'Withdrawn.']); // canceled
        $service->rts($this->admin(), $this->returnedLddap(n: 6), $this->rtsDetails()); // rts

        return $staff;
    }

    /** The status select offers every status in the routing, and the badge label rides along. */
    public function test_the_table_filters_on_status(): void
    {
        Sanctum::actingAs($this->oneOfEachStatus());

        foreach ([
            'registered' => 'Registered',
            'for_out' => 'For Out',
            'returned_for_acic' => 'Returned for ACIC',
            'rts' => 'RTS',
            'approved' => 'Approved',
            'canceled' => 'Canceled',
        ] as $status => $label) {
            $this->getJson("/api/v1/lddaps?status={$status}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.status_label', $label);
        }

        $this->getJson('/api/v1/lddaps?status=all')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/lddaps?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_table_filters_on_nature_of_payment(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        $service->register($staff, $this->details(1));                                        // commercial claims
        $service->register($staff, ['nature_of_payment' => 'rental'] + $this->details(2));
        $service->register($staff, ['nature_of_payment' => 'rental'] + $this->details(3));

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/lddaps?nature=rental')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lddaps?nature=commercial_claims')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lddap_no', 'LDDAP-0001');
        $this->getJson('/api/v1/lddaps?nature=honoraria')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lddaps?nature=all')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/lddaps?nature=bogus')->assertStatus(422)->assertJsonValidationErrors('nature');
    }

    /** Search matches part of the LDDAP number. */
    public function test_search_matches_part_of_the_lddap_number(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        $service->register($staff, ['lddap_no' => 'LDDAP-2026-0001'] + $this->details(1));
        $service->register($staff, ['lddap_no' => 'LDDAP-2026-0002'] + $this->details(2));
        $service->register($staff, ['lddap_no' => 'LDDAP-2025-0003'] + $this->details(3));

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/lddaps?search=2026')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lddaps?search=LDDAP-2025-0003')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lddap_no', 'LDDAP-2025-0003');
        $this->getJson('/api/v1/lddaps?search=nothing-like-this')->assertOk()->assertJsonCount(0, 'data');
    }

    /** Search matches part of the check number, once a record has one. */
    public function test_search_matches_part_of_the_check_number(): void
    {
        $this->seedSeries(120260, 120262);
        $this->useChecks(3);   // check numbers 120260, 120261, 120262

        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps?search=120261')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.check_no', 120261);
        $this->getJson('/api/v1/lddaps?search=2026')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/lddaps?search=99999')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Search matches the gross amount exactly, however it is typed — with or without commas,
     * the centavos, or the peso sign.
     */
    public function test_search_matches_the_gross_amount_with_or_without_commas(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        $service->register($staff, ['gross_amount' => '194032.00'] + $this->details(1));
        $service->register($staff, ['gross_amount' => '194032.50'] + $this->details(2));
        $service->register($staff, ['gross_amount' => '1940320.00'] + $this->details(3));

        Sanctum::actingAs($staff);

        foreach (['194032', '194,032', '194,032.00', '₱194,032', '₱ 194,032.00', '194032.00'] as $typed) {
            $this->getJson('/api/v1/lddaps?'.http_build_query(['search' => $typed]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.lddap_no', 'LDDAP-0001');
        }

        $this->getJson('/api/v1/lddaps?'.http_build_query(['search' => '₱194,032.50']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lddap_no', 'LDDAP-0002');

        // The amount must match exactly — 194032 is not "part of" 1940320.
        $this->getJson('/api/v1/lddaps?search=194032.01')->assertOk()->assertJsonCount(0, 'data');
    }

    /** Text that only reads as an amount is not mistaken for one. */
    public function test_amounts_are_parsed_from_the_search_text(): void
    {
        $this->assertSame('194032.00', Money::parse('194032'));
        $this->assertSame('194032.00', Money::parse('194,032.00'));
        $this->assertSame('194032.00', Money::parse('₱194,032'));
        $this->assertSame('194032.50', Money::parse(' ₱ 194,032.5 '));
        $this->assertSame('1500.00', Money::parse('PHP 1,500'));
        $this->assertNull(Money::parse('LDDAP-0001'));
        $this->assertNull(Money::parse('2026-0001'));
        $this->assertNull(Money::parse(''));
        $this->assertNull(Money::parse(null));
    }

    /** The filters combine: search AND status AND nature. */
    public function test_the_filters_combine(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();
        $admin = $this->admin();

        // Two records numbered 2026-…, one approved and one still registered; one numbered 2025-….
        $approved = $service->register($staff, ['lddap_no' => 'LDDAP-2026-0001', 'nature_of_payment' => 'rental'] + $this->details(1));
        $approved = $service->forward($staff, $approved, $this->forwardDetails());
        $approved = $service->receive($staff, $approved, $this->receiveDetails());
        $service->approve($admin, $approved);
        $service->register($staff, ['lddap_no' => 'LDDAP-2026-0002', 'nature_of_payment' => 'rental'] + $this->details(2));
        $service->register($staff, ['lddap_no' => 'LDDAP-2025-0003', 'nature_of_payment' => 'rental'] + $this->details(3));

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/lddaps?search=2026')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lddaps?search=2026&status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lddap_no', 'LDDAP-2026-0001');
        $this->getJson('/api/v1/lddaps?search=2026&status=approved&nature=rental')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/lddaps?search=2026&status=approved&nature=honoraria')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lddaps?status=registered&nature=rental')->assertOk()->assertJsonCount(2, 'data');
    }

    /** Clearing the filters — no parameters, or every one set to "all" / empty — shows everything. */
    public function test_clearing_the_filters_shows_every_record(): void
    {
        Sanctum::actingAs($this->oneOfEachStatus());

        $this->getJson('/api/v1/lddaps?search=LDDAP-0002&status=registered')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/lddaps')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('meta.total', 6);
        $this->getJson('/api/v1/lddaps?search=&status=all&nature=all')->assertOk()->assertJsonCount(6, 'data');
    }

    /** The pages carry the active filters, so page 2 of a filtered list is still filtered. */
    public function test_pagination_keeps_the_active_filters(): void
    {
        $this->seedSeries(1, 10);
        $service = app(LddapService::class);
        $staff = $this->staff();

        foreach (range(1, 5) as $n) {
            $service->register($staff, ['lddap_no' => "LDDAP-2026-000{$n}"] + $this->details($n));
        }
        $service->register($staff, ['lddap_no' => 'LDDAP-2025-0006'] + $this->details(6));

        Sanctum::actingAs($staff);

        $first = $this->getJson('/api/v1/lddaps?search=2026&per_page=2')->assertOk();
        $first->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
        $this->assertStringContainsString('search=2026', (string) $first->json('links.next'));

        $last = $this->getJson('/api/v1/lddaps?search=2026&per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
        $this->assertStringContainsString('2026', (string) $last->json('data.0.lddap_no'));
    }

    // -------------------------------------------------------------- ACIC linking

    /** @return array{Acic, Collection<int, Lddap>} an open ACIC and four LDDAPs, three approved */
    private function acicAndCompletedLddaps(): array
    {
        $this->seedSeries(1, 10);
        // Three signed off and waiting for an ACIC, one returned but not yet acted on.
        $this->approvedLddaps(3);
        $this->returnedLddap(n: 4);

        return [app(AcicService::class)->create($this->admin()), Lddap::orderBy('id')->get()];
    }

    public function test_linkable_lists_only_approved_lddaps_not_yet_on_an_acic(): void
    {
        [, $lddaps] = $this->acicAndCompletedLddaps();
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps/linkable')->assertOk()->assertJsonCount(3, 'data');
        $this->assertCount(4, $lddaps);
    }

    public function test_many_approved_lddaps_can_be_assigned_to_one_acic(): void
    {
        [$acic, $lddaps] = $this->acicAndCompletedLddaps();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", [
            'lddap_ids' => $lddaps->take(3)->pluck('id')->all(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'used');

        // The rows stay in the LDDAP table and now report the ACIC number.
        $this->assertSame(4, Lddap::count());
        $this->assertSame(3, Lddap::whereNotNull('acic_id')->count());

        $this->getJson('/api/v1/lddaps?search=LDDAP-0001')
            ->assertOk()
            ->assertJsonPath('data.0.acic_number', $acic->acic_number);
    }

    public function test_an_lddap_that_is_not_approved_cannot_be_linked(): void
    {
        [$acic, $lddaps] = $this->acicAndCompletedLddaps();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => $lddaps->pluck('id')->all()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_ids');

        $this->assertSame(0, Lddap::whereNotNull('acic_id')->count());
    }

    public function test_an_lddap_already_on_another_acic_cannot_be_reassigned(): void
    {
        [$first, $lddaps] = $this->acicAndCompletedLddaps();
        $second = app(AcicService::class)->create($this->admin());
        $ids = $lddaps->take(3)->pluck('id')->all();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$first->id}/lddaps", ['lddap_ids' => $ids])->assertOk();

        $this->postJson("/api/v1/acics/{$second->id}/lddaps", ['lddap_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_ids');

        $this->assertSame(3, $first->fresh()->lddaps()->count());
        $this->assertSame(0, $second->fresh()->lddaps()->count());
    }

    public function test_a_teller_cannot_assign_lddaps_to_an_acic(): void
    {
        [$acic, $lddaps] = $this->acicAndCompletedLddaps();
        Sanctum::actingAs($this->teller());

        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", [
            'lddap_ids' => $lddaps->take(1)->pluck('id')->all(),
        ])->assertForbidden();
    }

    // ------------------------------------------ "Assign LDDAP to ACIC" by number

    /** Tick several, type an ACIC number: they all go on it and each takes the next check number. */
    public function test_several_records_are_assigned_to_one_typed_acic_number(): void
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->approvedLddaps(3);
        $ids = $lddaps->pluck('id')->all();
        $next = app(AcicService::class)->nextNumber();

        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/lddaps/assign-acic', [
            'acic_no' => $next,
            'lddap_ids' => $ids,
            'expected_check_nos' => [1, 2, 3],
        ])
            ->assertOk()
            ->assertJsonPath('data.acic_number', $next)
            ->assertJsonCount(3, 'data.lddaps');

        // One ACIC, shared; three consecutive numbers, in the order listed.
        $rows = Lddap::whereIn('id', $ids)->with('lddapCheck')->get()->keyBy('id');
        $this->assertSame(1, $rows->map(fn ($l) => $l->acic_id)->unique()->count());
        $this->assertSame([1, 2, 3], array_map(fn ($id) => $rows[$id]->lddapCheck->check_no, $ids));

        // Who did it, and when, is in the audit trail.
        $this->assertDatabaseHas('cheque_logs', ['action' => 'used_acic']);
        $log = ChequeLog::where('action', 'used_acic')->latest('id')->first();
        $this->assertStringContainsString("ACIC number {$next}", $log->description);
        $this->assertStringContainsString('check #1', $log->description);
    }

    /** More records can join an ACIC that already carries some — the number is shared. */
    public function test_more_records_can_share_an_existing_acic_number(): void
    {
        $this->seedSeries(1, 10);
        $first = $this->approvedLddaps(2);
        $later = $this->approvedLddaps(2, offset: 10);
        $number = app(AcicService::class)->nextNumber();

        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/lddaps/assign-acic', ['acic_no' => $number, 'lddap_ids' => $first->pluck('id')->all()])
            ->assertOk();
        $this->postJson('/api/v1/lddaps/assign-acic', ['acic_no' => $number, 'lddap_ids' => $later->pluck('id')->all()])
            ->assertOk()
            ->assertJsonCount(4, 'data.lddaps');

        $this->assertSame(1, Acic::count());
        $this->assertSame([1, 2, 3, 4], Lddap::with('lddapCheck')->orderBy('id')->get()->pluck('lddapCheck.check_no')->all());
    }

    /** A typed number has to be an open ACIC or the next in the series — never invented. */
    public function test_a_typed_acic_number_must_be_open_or_next_in_the_series(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->approvedLddaps(1)->first();
        $next = app(AcicService::class)->nextNumber();

        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/lddaps/assign-acic', ['acic_no' => $next + 5, 'lddap_ids' => [$lddap->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('acic_no');

        $this->assertSame(0, Acic::count());
        $this->assertNull($lddap->fresh()->lddap_check_id);
    }

    /** Only approved records with no ACIC are offered — and the endpoint holds to that too. */
    public function test_linkable_offers_only_approved_records_without_an_acic(): void
    {
        $this->seedSeries(1, 10);
        $this->approvedLddaps(2);
        $this->useChecks(1, offset: 10);           // approved, already on an ACIC
        $this->returnedLddap(n: 20);               // not approved

        Sanctum::actingAs($this->staff());
        $offered = $this->getJson('/api/v1/lddaps/linkable')->assertOk()->json('data');

        $this->assertSame(['LDDAP-0001', 'LDDAP-0002'], array_column($offered, 'lddap_no'));
        // No check numbers yet — they arrive with the assignment.
        $this->assertSame([null, null], array_column($offered, 'check_no'));
    }

    // ------------------------------------------------------------------ forwarding

    /** @return Acic an ACIC carrying three approved LDDAPs */
    private function loadedAcic(): Acic
    {
        [$acic, $lddaps] = $this->acicAndCompletedLddaps();
        app(LddapService::class)->assignToAcic($this->staff(), $acic, $lddaps->take(3)->pluck('id')->all());
        // Forwarding follows the sign-off, so the ACIC is approved on the way through.
        app(AcicService::class)->approve($this->admin(), $acic);

        return $acic->fresh();
    }

    /**
     * The point of the ACIC number: many LDDAPs share it. A Used ACIC keeps accepting them, and
     * they all stay grouped on that one ACIC rather than each opening a new number.
     */
    public function test_a_used_acic_still_accepts_more_lddaps(): void
    {
        [$acic, $lddaps] = $this->acicAndCompletedLddaps();
        $ids = $lddaps->pluck('id')->all();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => [$ids[0]]])->assertOk();
        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);

        // Used, and still accepting — the rest land on the same ACIC number.
        $this->postJson("/api/v1/acics/{$acic->id}/lddaps", ['lddap_ids' => [$ids[1], $ids[2]]])
            ->assertOk();

        $this->assertSame(3, $acic->lddaps()->count());
        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);

        // All three report the same ACIC number, so they print as one table.
        $numbers = $acic->lddaps()->get()->map(fn ($l) => $l->acic_id)->unique();
        $this->assertCount(1, $numbers);
    }

    public function test_an_acic_carrying_only_lddaps_can_be_forwarded(): void
    {
        $acic = $this->loadedAcic();
        $teller = $this->teller();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $teller->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'forwarded')
            ->assertJsonPath('data.forwarded_to', $teller->name);

        Sanctum::actingAs($this->staff());
        $row = $this->getJson('/api/v1/lddaps?search=LDDAP-0001')->assertOk()->json('data.0');

        $this->assertSame($teller->name, $row['forwarded_to']);
        $this->assertNotNull($row['forwarded_at']);
    }

    public function test_forwarding_records_a_typed_in_name_when_the_recipient_is_not_a_user(): void
    {
        $acic = $this->loadedAcic();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_name' => 'J. Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('data.received_by', null)
            ->assertJsonPath('data.forwarded_to', 'J. Dela Cruz');
    }

    public function test_an_empty_acic_cannot_be_forwarded(): void
    {
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_name' => 'J. Dela Cruz'])
            ->assertStatus(422);
    }

    public function test_completing_an_acic_stamps_receipt_on_its_lddaps(): void
    {
        $acic = $this->loadedAcic();
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_name' => 'J. Dela Cruz'])->assertOk();

        $teller = $this->teller();
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $this->assertSame(3, Lddap::where('acic_id', $acic->id)->whereNotNull('received_at')->count());
        $this->assertSame(3, Lddap::where('received_by', $teller->id)->count());
    }

    // ------------------------------------------------------------------ audit trail

    public function test_the_series_and_its_use_are_audited(): void
    {
        $this->seedSeries(1, 10);
        $this->useChecks(2);

        $this->assertSame(1, ChequeLog::where('action', 'added_lddap_check_range')->count());

        $logs = ChequeLog::where('action', 'used_lddap_check')->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertStringContainsString('check number 1', $logs->first()->description);
        $this->assertStringContainsString('LDDAP-0001', $logs->first()->description);
    }
}

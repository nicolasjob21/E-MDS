<?php

namespace Tests\Feature;

use App\Enums\AcicStatus;
use App\Enums\LddapCheckStatus;
use App\Enums\LddapStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\ChequeLog;
use App\Models\Lddap;
use App\Models\LddapCheck;
use App\Models\User;
use App\Services\AcicService;
use App\Services\ChequeService;
use App\Services\LddapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?int $startAt = null): array
    {
        return [
            'start_at' => $startAt ?? app(LddapService::class)->nextNumbers(1)[0],
            'rows' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $count, int $offset = 0): array
    {
        return array_map(fn (int $i) => [
            'lddap_no' => 'LDDAP-'.str_pad((string) ($i + $offset), 4, '0', STR_PAD_LEFT),
            'obj_no' => 'OBJ-'.($i + $offset),
            'payee_name' => 'Payee '.($i + $offset),
            'amount' => 100 * ($i + $offset),
        ], range(1, $count));
    }

    /**
     * Take `$count` check numbers for LDDAPs.
     *
     * @return Collection<int, Lddap>
     */
    private function useChecks(int $count, ?User $user = null, int $offset = 0): Collection
    {
        return app(LddapService::class)->useCheckNumbers(
            $user ?? $this->staff(),
            app(LddapService::class)->nextNumbers(1)[0],
            $this->rows($count, $offset),
        );
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

        // ACIC numbering is at 2; the LDDAP series still hands out 200.
        $this->assertSame([200], $this->numbersOf($this->useChecks(1)));
        $this->assertSame(3, app(AcicService::class)->nextNumber());
    }

    public function test_using_a_check_number_consumes_no_cheque(): void
    {
        app(ChequeService::class)->addRange($this->admin(), 1, 10);
        $this->seedSeries(1, 10);

        $this->useChecks(3);

        $this->assertSame(10, Cheque::where('status', 'available')->count());
        $this->assertSame(0, Cheque::whereNotNull('used_at')->count());
    }

    // ---------------------------------------------------------- ascending & unskipped

    public function test_numbers_are_handed_out_lowest_first_in_strict_order(): void
    {
        $this->seedSeries(1, 10);

        $this->assertSame([1, 2, 3], $this->numbersOf($this->useChecks(3)));
        $this->assertSame([4, 5], $this->numbersOf($this->useChecks(2, offset: 10)));
    }

    public function test_a_number_cannot_be_skipped(): void
    {
        $this->seedSeries(1, 10);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($this->rows(2), startAt: 5))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        $this->assertSame(0, Lddap::count());
        $this->assertSame(10, LddapCheck::where('status', LddapCheckStatus::Available)->count());
    }

    public function test_a_lower_range_registered_later_is_used_before_higher_numbers(): void
    {
        // The series starts at 500 and 500-501 get used...
        $this->seedSeries(500, 505);
        $this->assertSame([500, 501], $this->numbersOf($this->useChecks(2)));

        // ...then a lower block is registered. Those numbers must come next, not 502.
        $this->seedSeries(1, 3);

        $this->assertSame([1, 2, 3, 502], $this->numbersOf($this->useChecks(4, offset: 10)));
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

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($this->rows(2)))
            ->assertCreated()
            ->assertJsonPath('data.0.check_no', 9910031148)
            ->assertJsonPath('data.1.check_no', 9910031149);

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

    public function test_the_series_steps_over_the_gap_between_two_registered_blocks(): void
    {
        $this->seedSeries(1, 3);
        $this->seedSeries(900, 902);

        $this->assertSame([1, 2, 3, 900, 901], $this->numbersOf($this->useChecks(5)));
    }

    public function test_next_numbers_previews_what_the_batch_would_take(): void
    {
        $this->seedSeries(40, 42);
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps/next-numbers?count=5')
            ->assertOk()
            ->assertJsonPath('data.numbers', [40, 41, 42])
            ->assertJsonPath('data.available', 3);
    }

    public function test_a_batch_larger_than_the_remaining_series_is_refused_whole(): void
    {
        $this->seedSeries(1, 2);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($this->rows(3)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');

        $this->assertSame(0, Lddap::count());
        $this->assertSame(2, LddapCheck::where('status', LddapCheckStatus::Available)->count());
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

    public function test_the_same_lddap_number_cannot_be_registered_twice(): void
    {
        $this->seedSeries(1, 10);
        $this->useChecks(1);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($this->rows(1)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');

        $this->assertSame(1, Lddap::count());
    }

    public function test_one_batch_cannot_repeat_the_same_lddap_number(): void
    {
        $this->seedSeries(1, 10);
        Sanctum::actingAs($this->staff());

        $rows = $this->rows(2);
        $rows[1]['lddap_no'] = $rows[0]['lddap_no'];

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($rows))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.1.lddap_no');

        $this->assertSame(0, Lddap::count());
    }

    public function test_a_failed_batch_releases_no_check_number(): void
    {
        $this->seedSeries(1, 10);
        $this->useChecks(1);

        $rows = $this->rows(2, 10);
        $rows[1]['lddap_no'] = 'LDDAP-0001'; // already registered

        try {
            app(LddapService::class)->useCheckNumbers(
                $this->staff(),
                app(LddapService::class)->nextNumbers(1)[0],
                $rows,
            );
            $this->fail('Expected the batch to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, Lddap::count());
        $this->assertSame(9, LddapCheck::where('status', LddapCheckStatus::Available)->count());
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

    public function test_a_teller_cannot_use_a_check_number(): void
    {
        $this->seedSeries(1, 10);
        Sanctum::actingAs($this->teller());

        $this->postJson('/api/v1/lddaps/use-cheque', $this->payload($this->rows(1)))->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_lddap_table(): void
    {
        $this->getJson('/api/v1/lddaps')->assertUnauthorized();
    }

    // ------------------------------------------------------------------- lifecycle

    public function test_the_teller_confirms_receipt(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();

        $teller = $this->teller();
        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.received_by.id', $teller->id);
    }

    public function test_only_a_teller_can_confirm_receipt(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")->assertForbidden();
    }

    public function test_an_admin_review_drives_the_status(): void
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->useChecks(3);
        Sanctum::actingAs($this->admin());

        foreach ([
            [0, 'approved', 'approved'],
            [1, 'compliance', 'compliance'],
            [2, 'cancelled', 'cancelled'],
        ] as [$index, $outcome, $expected]) {
            $this->postJson("/api/v1/lddaps/{$lddaps[$index]->id}/review", [
                'status' => $outcome,
                'review_note' => 'Checked.',
            ])
                ->assertOk()
                ->assertJsonPath('data.status', $expected);
        }
    }

    public function test_a_final_lddap_cannot_be_reviewed_again(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])->assertOk();
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'cancelled'])->assertStatus(422);

        $this->assertSame(LddapStatus::Approved, $lddap->fresh()->status);
    }

    public function test_a_record_sent_back_for_compliance_needs_a_note_and_can_be_reviewed_again(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'compliance'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('review_note');

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'Missing the OBJ reference.',
        ])->assertOk();

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_only_an_admin_can_review(): void
    {
        $this->seedSeries(1, 10);
        $lddap = $this->useChecks(1)->first();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])->assertForbidden();
    }

    public function test_the_table_can_be_filtered_and_searched(): void
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->useChecks(3);
        app(LddapService::class)->review($this->admin(), $lddaps->first(), LddapStatus::Approved);

        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps?status=approved')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/lddaps?status=used')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lddaps?search=LDDAP-0002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.check_no', 2);
    }

    // -------------------------------------------------------------- ACIC linking

    /** @return array{Acic, Collection<int, Lddap>} an open ACIC and four LDDAPs, three approved */
    private function acicAndCompletedLddaps(): array
    {
        $this->seedSeries(1, 10);
        $lddaps = $this->useChecks(4);

        $admin = $this->admin();
        foreach ($lddaps->take(3) as $lddap) {
            app(LddapService::class)->review($admin, $lddap, LddapStatus::Approved);
        }

        return [app(AcicService::class)->create($admin), Lddap::orderBy('id')->get()];
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

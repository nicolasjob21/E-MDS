<?php

namespace Tests\Feature;

use App\Enums\AcicStatus;
use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\Lddap;
use App\Models\User;
use App\Services\AcicService;
use App\Services\AcicTellerService;
use App\Services\ChequeService;
use App\Services\LddapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcicTest extends TestCase
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

    /**
     * Register 1–10, use the first five, and approve the first three so there are
     * linkable cheques plus some that must be refused.
     */
    private function seedCheques(): void
    {
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 1, 10);

        $staff = $this->staff();
        foreach (range(1, 5) as $number) {
            $cheques->useNext($staff, $number, [
                'payee_name' => 'Payee '.$number,
                'amount' => 100,
                'cheque_date' => '2026-08-30',
            ]);
        }

        Cheque::whereIn('cheque_number', [1, 2, 3])->update(['status' => ChequeStatus::ForSignature]);
    }

    /** Register a check series, use one number, and approve the record so it can go on an ACIC. */
    private function approvedLddap(): Lddap
    {
        $lddaps = app(LddapService::class);
        $lddaps->addRange($this->admin(), 1, 10);
        $staff = $this->staff();

        // Added — For Signature, ready for an ACIC, which is when it will take its check number.
        return $lddaps->register($staff, [
            'lddap_no' => '26-09-00001',
            'obj_no' => 'OBJ-1',
            'payee_name' => 'Payee 1',
            'amount' => 100,
        ]);
    }

    /** @return list<int> ids of the approved cheques, in number order */
    private function approvedIds(): array
    {
        return Cheque::whereIn('status', [ChequeStatus::ForSignature, ChequeStatus::Approved])
            ->orderBy('cheque_number')
            ->pluck('id')
            ->all();
    }

    // ---------------------------------------------------------------- sequence

    public function test_the_first_acic_is_number_one(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/acics')
            ->assertCreated()
            ->assertJsonPath('data.acic_number', 1)
            ->assertJsonPath('data.status', 'open');
    }

    public function test_acic_numbers_run_in_an_unbroken_sequence(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (range(1, 4) as $expected) {
            $this->postJson('/api/v1/acics')
                ->assertCreated()
                ->assertJsonPath('data.acic_number', $expected);
        }

        $this->assertSame([1, 2, 3, 4], Acic::orderBy('acic_number')->pluck('acic_number')->all());
    }

    /** Deleting a record must not let the next number skip the gap it left. */
    public function test_the_next_number_continues_from_the_highest_ever_used(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/acics')->assertCreated();
        $this->postJson('/api/v1/acics')->assertCreated();

        $this->getJson('/api/v1/acics/next')
            ->assertOk()
            ->assertJsonPath('data.acic_number', 3);
    }

    public function test_a_teller_cannot_create_an_acic(): void
    {
        Sanctum::actingAs($this->teller());

        $this->postJson('/api/v1/acics')->assertForbidden();
        $this->assertSame(0, Acic::count());
    }

    public function test_staff_can_create_an_acic(): void
    {
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/acics')->assertCreated();
    }

    // ------------------------------------------------------------ use / linking

    public function test_approved_cheques_can_be_assigned_to_an_acic(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        $ids = $this->approvedIds();

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $ids])
            ->assertOk()
            // Assigning cheques signs the ACIC off in the same breath.
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(3, Cheque::where('acic_id', $acic->id)->count());
        $this->assertSame(AcicStatus::Approved, $acic->refresh()->status);
        $this->assertNotNull($acic->used_by);
        $this->assertNotNull($acic->used_at);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'used_acic']);
    }

    public function test_many_cheques_share_one_acic_number_typed_in(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());
        [$first, $second, $third] = $this->approvedIds();

        // The next number in the series opens a new ACIC with the ticked cheques on it…
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 1, 'cheque_ids' => [$first, $second]])
            ->assertOk()->assertJsonPath('data.acic_number', 1);
        // …and typing that number again adds more to the same ACIC — no new number is taken.
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 1, 'cheque_ids' => [$third]])
            ->assertOk()->assertJsonPath('data.acic_number', 1);

        $this->assertSame(1, Acic::count());
        $this->assertSame(3, Cheque::where('acic_id', Acic::first()->id)->count());
        $this->assertSame(2, app(AcicService::class)->nextNumber());
    }

    public function test_an_acic_number_that_is_neither_open_nor_next_is_refused(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 7, 'cheque_ids' => $this->approvedIds()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('acic_no')
            ->assertJsonPath('errors.acic_no.0', 'ACIC #7 is not open. Enter an existing ACIC number, or the next one in the series (#1).');
        $this->assertSame(0, Acic::count());
    }

    public function test_assign_by_number_takes_only_for_signature_cheques_and_needs_a_role(): void
    {
        $this->seedCheques();
        $used = Cheque::where('cheque_number', 4)->value('id');

        Sanctum::actingAs($this->teller());
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 1, 'cheque_ids' => $this->approvedIds()])->assertForbidden();

        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 1, 'cheque_ids' => [$used]])->assertStatus(422);
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => 1, 'cheque_ids' => []])->assertJsonValidationErrors('cheque_ids');
        // Nothing half-done: the refused batch opened no ACIC.
        $this->assertNull(Cheque::find($used)->acic_id);
        $this->assertSame(0, Acic::count());
    }

    public function test_only_approved_cheques_can_be_assigned(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        // #4 and #5 are used but not approved; #6 is still available.
        $notApproved = Cheque::whereIn('cheque_number', [4, 5, 6])->pluck('id')->all();

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $notApproved])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids');

        // Nothing was linked, and the ACIC stays Open.
        $this->assertSame(0, Cheque::whereNotNull('acic_id')->count());
        $this->assertSame(AcicStatus::Open, $acic->refresh()->status);
    }

    /** A single bad cheque must fail the whole batch — no partial assignment. */
    public function test_a_mixed_batch_is_rejected_entirely(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        $ids = array_merge($this->approvedIds(), Cheque::where('cheque_number', 4)->pluck('id')->all());

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $ids])
            ->assertStatus(422);

        $this->assertSame(0, Cheque::whereNotNull('acic_id')->count());
    }

    public function test_a_cheque_cannot_be_on_two_acics(): void
    {
        $this->seedCheques();
        $service = app(AcicService::class);
        $first = $service->create($this->admin());
        $second = $service->create($this->admin());
        Sanctum::actingAs($this->staff());

        $ids = $this->approvedIds();
        $this->postJson("/api/v1/acics/{$first->id}/cheques", ['cheque_ids' => $ids])->assertOk();

        $this->postJson("/api/v1/acics/{$second->id}/cheques", ['cheque_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids');

        // They all still belong to the first ACIC.
        $this->assertSame(3, Cheque::where('acic_id', $first->id)->count());
        $this->assertSame(0, Cheque::where('acic_id', $second->id)->count());
    }

    public function test_assigning_the_same_cheque_twice_to_the_same_acic_is_harmless(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        $ids = $this->approvedIds();
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $ids])->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $ids])->assertOk();

        $this->assertSame(3, Cheque::where('acic_id', $acic->id)->count());
    }

    public function test_at_least_one_cheque_must_be_selected(): void
    {
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids');
    }

    public function test_linkable_cheques_lists_only_approved_and_unlinked(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/acics/linkable-cheques')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => $this->approvedIds()])
            ->assertOk();

        // Once linked they drop out of the pool.
        $this->getJson('/api/v1/acics/linkable-cheques')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Used says nothing about capacity: it only means the ACIC already carries something. Many
     * records share one ACIC number, so a Used ACIC keeps accepting them until it is signed off.
     */
    public function test_an_approved_acic_still_accepts_more_cheques(): void
    {
        $this->seedCheques();
        [$first, $second, $third] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$first]])->assertOk();
        $this->assertSame(AcicStatus::Approved, $acic->refresh()->status);

        // Approved on the first assignment — and still open for more. Many cheques share
        // one ACIC number; it is forwarding, not approval, that closes membership.
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$second, $third]])
            ->assertOk();

        $this->assertSame(3, $acic->cheques()->count());
        $this->assertSame(AcicStatus::Approved, $acic->refresh()->status);
    }

    /** Sign-off is what closes membership, not Used. */
    /** A forwarded ACIC is closed to new records; an approved one is not. */
    public function test_a_forwarded_acic_accepts_no_more_records(): void
    {
        $this->seedCheques();
        [$first, $second] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);
        $acic->update(['status' => AcicStatus::Forwarded]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$second]])
            ->assertStatus(422);
    }

    /** Assignment already signed it off, so the manual Approve has nothing left to do. */
    public function test_a_cheque_acic_is_already_approved_by_assignment(): void
    {
        $this->seedCheques();
        [$first] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);

        $this->assertSame(AcicStatus::Approved, $acic->refresh()->status);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'approved_acic']);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => 'has already been approved']);
    }

    // ---------------------------------------------------------------- approval

    public function test_an_empty_acic_cannot_be_approved(): void
    {
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/approve")->assertStatus(422);
        $this->assertSame(AcicStatus::Open, $acic->refresh()->status);
    }

    public function test_only_an_admin_can_approve(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/approve")->assertForbidden();
    }

    // ------------------------------------------------------------- re-assigning

    /**
     * The point of re-assigning: the cheque coming off goes back to the pool, so it can be put
     * on a later ACIC instead of being stranded on this one.
     */
    public function test_re_assigning_releases_the_old_cheque_and_puts_the_new_one_on(): void
    {
        $this->seedCheques();
        [$first, $second] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/reassign", [
            'type' => 'cheque',
            'release_id' => $first,
            'assign_id' => $second,
        ])->assertOk();

        $this->assertNull(Cheque::find($first)->acic_id);
        $this->assertSame($acic->id, Cheque::find($second)->acic_id);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'reassigned_acic']);

        // The released cheque is offered again; the newly assigned one is not.
        $linkable = $this->getJson('/api/v1/acics/linkable-cheques')->assertOk()->json('data');
        $ids = array_column($linkable, 'id');

        $this->assertContains($first, $ids);
        $this->assertNotContains($second, $ids);
    }

    public function test_re_assigning_refuses_a_cheque_that_is_not_on_this_acic(): void
    {
        $this->seedCheques();
        [$first, $second, $third] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/reassign", [
            'type' => 'cheque',
            'release_id' => $second,
            'assign_id' => $third,
        ])->assertStatus(422)->assertJsonValidationErrors('release_id');

        $this->assertSame($acic->id, Cheque::find($first)->acic_id);
    }

    public function test_re_assigning_refuses_a_cheque_already_on_another_acic(): void
    {
        $this->seedCheques();
        [$first, $second] = $this->approvedIds();
        $acics = app(AcicService::class);

        $one = $acics->create($this->admin());
        $acics->assignCheques($this->staff(), $one, [$first]);
        $two = $acics->create($this->admin());
        $acics->assignCheques($this->staff(), $two, [$second]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$one->id}/reassign", [
            'type' => 'cheque',
            'release_id' => $first,
            'assign_id' => $second,
        ])->assertStatus(422)->assertJsonValidationErrors('assign_id');
    }

    /** Three cheques on an ACIC, forwarded to the tellers — Pending. */
    private function forwardedAcic(): Acic
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        return app(AcicTellerService::class)->forwardToTeller($this->admin(), $acic->fresh());
    }

    // ------------------------------------------------ forwarded to the tellers: Pending

    public function test_a_cheque_acic_forwarded_to_the_tellers_shows_as_pending(): void
    {
        $acic = $this->forwardedAcic();
        Sanctum::actingAs($this->teller());

        $this->getJson('/api/v1/acics')
            ->assertOk()
            ->assertJsonPath('data.0.acic_number', $acic->acic_number)
            ->assertJsonPath('data.0.display_status', 'pending')
            ->assertJsonPath('data.0.display_status_label', 'Pending')
            ->assertJsonPath('data.0.can_accept', true);
        $this->getJson('/api/v1/acics?status=pending')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/acics?status=approved')->assertJsonCount(0, 'data');

        // Accepted, it moves on through the teller flow — and no longer Pending.
        $this->postJson("/api/v1/acics/{$acic->id}/accept")->assertOk();
        $this->getJson('/api/v1/acics?status=pending')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/acics?status=accepted_by_teller')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_accept', false);
    }

    public function test_a_teller_sees_only_acics_forwarded_to_the_tellers(): void
    {
        $forwarded = $this->forwardedAcic();
        $withAdmin = app(AcicService::class)->create($this->admin());   // open, still the admin's

        Sanctum::actingAs($this->teller());
        $this->assertSame([$forwarded->acic_number], array_column($this->getJson('/api/v1/acics')->json('data'), 'acic_number'));
        $this->getJson('/api/v1/acics?status=open')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/acics/{$withAdmin->id}")->assertNotFound();
        $this->getJson("/api/v1/acics/{$forwarded->id}")->assertOk();

        // Admins and staff still see every ACIC.
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/acics')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/acics?status=pending')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/acics?status=open')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_accept', false);
    }

    public function test_the_recipient_pick_forward_and_the_old_complete_are_gone(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_name' => 'J. Dela Cruz'])->assertNotFound();
        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertNotFound();
    }

    public function test_a_forwarded_acic_cannot_be_re_assigned(): void
    {
        $acic = $this->forwardedAcic();
        $first = $acic->cheques()->orderBy('cheque_number')->value('id');
        $spare = Cheque::where('cheque_number', 4)->first();
        $spare->update(['status' => ChequeStatus::ForSignature]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/reassign", [
            'type' => 'cheque',
            'release_id' => $first,
            'assign_id' => $spare->id,
        ])->assertStatus(422)->assertJsonValidationErrors('acic');
    }

    public function test_a_teller_cannot_re_assign(): void
    {
        $this->seedCheques();
        [$first, $second] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/acics/{$acic->id}/reassign", [
            'type' => 'cheque',
            'release_id' => $first,
            'assign_id' => $second,
        ])->assertForbidden();
    }

    public function test_the_category_tabs_filter_on_what_the_acic_carries(): void
    {
        $this->seedCheques();
        $admin = $this->admin();
        $acics = app(AcicService::class);

        // One with a cheque on it, one with an LDDAP on it, one left empty.
        $withCheque = $acics->create($admin);
        $acics->assignCheques($admin, $withCheque, [$this->approvedIds()[0]]);

        $withLddap = $acics->create($admin);
        $lddap = $this->approvedLddap();
        app(LddapService::class)->assignToAcic($admin, $withLddap, [$lddap->id]);

        $empty = $acics->create($admin);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/acics?category=cheques')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acic_number', $withCheque->acic_number);

        $this->getJson('/api/v1/acics?category=lddaps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acic_number', $withLddap->acic_number);

        // The empty one only appears with no category filter.
        $this->getJson('/api/v1/acics')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertNotNull($empty->acic_number);
    }

    /** The counts the Category column is derived from ride along on every row. */
    public function test_each_row_carries_its_cheque_and_lddap_counts(): void
    {
        $this->seedCheques();
        $admin = $this->admin();
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$this->approvedIds()[0]]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/acics')
            ->assertOk()
            ->assertJsonPath('data.0.cheque_count', 1)
            ->assertJsonPath('data.0.lddap_count', 0);
    }

    // --------------------------------------------------------------- listing

    public function test_the_cheque_resource_reports_its_acic(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/cheques?status=approved')
            ->assertOk()
            ->assertJsonPath('data.0.acic_number', $acic->acic_number);
    }

    public function test_show_returns_the_full_record_with_its_cheques(): void
    {
        $acic = $this->forwardedAcic();
        Sanctum::actingAs($this->teller());

        $response = $this->getJson("/api/v1/acics/{$acic->id}")
            ->assertOk()
            ->assertJsonPath('data.acic_number', $acic->acic_number)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.display_status', 'pending')
            ->assertJsonCount(3, 'data.cheques');

        // The confirmation popup renders these fields for each cheque.
        $first = $response->json('data.cheques.0');
        foreach (['cheque_number', 'payee_name', 'amount', 'cheque_date', 'status'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }

        // ...and these on the record itself.
        foreach (['used_by', 'received_by', 'created_by', 'created_at', 'forwarded_at'] as $field) {
            $this->assertArrayHasKey($field, $response->json('data'));
        }
    }

    public function test_guests_cannot_touch_acics(): void
    {
        $this->getJson('/api/v1/acics')->assertUnauthorized();
        $this->postJson('/api/v1/acics')->assertUnauthorized();
    }
}

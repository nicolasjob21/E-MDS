<?php

namespace Tests\Feature;

use App\Enums\AcicStatus;
use App\Enums\ChequeStatus;
use App\Enums\LddapStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\Lddap;
use App\Models\User;
use App\Services\AcicService;
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

        Cheque::whereIn('cheque_number', [1, 2, 3])->update(['status' => ChequeStatus::Approved]);
    }

    /** Register a check series, use one number, and approve the record so it can go on an ACIC. */
    private function approvedLddap(): Lddap
    {
        $lddaps = app(LddapService::class);
        $lddaps->addRange($this->admin(), 1, 10);

        $lddap = $lddaps->useCheckNumbers($this->staff(), 1, [[
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-1',
            'payee_name' => 'Payee 1',
            'amount' => 100,
        ]])->first();

        $lddap->update(['status' => LddapStatus::Approved]);

        return $lddap->fresh();
    }

    /** @return list<int> ids of the approved cheques, in number order */
    private function approvedIds(): array
    {
        return Cheque::where('status', ChequeStatus::Approved)
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
            ->assertJsonPath('data.status', 'used');

        $this->assertSame(3, Cheque::where('acic_id', $acic->id)->count());
        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);
        $this->assertNotNull($acic->used_by);
        $this->assertNotNull($acic->used_at);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'used_acic']);
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
    public function test_a_used_acic_still_accepts_more_cheques(): void
    {
        $this->seedCheques();
        [$first, $second, $third] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$first]])->assertOk();
        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);

        // Still Used — and still open for business.
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$second, $third]])
            ->assertOk();

        $this->assertSame(3, $acic->cheques()->count());
        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);
    }

    /** Sign-off is what closes membership, not Used. */
    public function test_an_approved_acic_accepts_no_more_records(): void
    {
        $this->seedCheques();
        [$first, $second] = $this->approvedIds();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$first]);
        app(AcicService::class)->approve($this->admin(), $acic);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$second]])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- approval

    public function test_an_admin_can_approve_a_used_acic(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(AcicStatus::Approved, $acic->refresh()->status);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'approved_acic']);
    }

    public function test_an_empty_acic_cannot_be_approved(): void
    {
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/approve")->assertStatus(422);
        $this->assertSame(AcicStatus::Open, $acic->refresh()->status);
    }

    public function test_an_acic_cannot_be_approved_twice(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/approve")->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/approve")->assertStatus(422);
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

    public function test_a_forwarded_acic_cannot_be_re_assigned(): void
    {
        $acic = $this->forwardedAcic();
        [$first] = $this->approvedIds();
        $spare = Cheque::where('cheque_number', 4)->first();
        $spare->update(['status' => ChequeStatus::Approved]);

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

    // -------------------------------------------------------------- forwarding

    public function test_an_admin_can_forward_an_approved_acic(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        $staff = $this->staff();
        app(AcicService::class)->assignCheques($staff, $acic, $this->approvedIds());
        app(AcicService::class)->approve($this->admin(), $acic);

        $recipient = User::factory()->create(['role' => UserRole::Staff]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $recipient->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'forwarded');

        $acic->refresh();
        $this->assertSame(AcicStatus::Forwarded, $acic->status);
        $this->assertNotNull($acic->forwarded_at);
        $this->assertSame($recipient->id, $acic->received_by);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'forwarded_acic']);
    }

    public function test_an_empty_acic_cannot_be_forwarded(): void
    {
        $acic = app(AcicService::class)->create($this->admin());
        $recipient = $this->staff();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $recipient->id])
            ->assertStatus(422);

        $this->assertSame(AcicStatus::Open, $acic->refresh()->status);
    }

    public function test_an_acic_cannot_be_forwarded_twice(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());
        app(AcicService::class)->approve($this->admin(), $acic);
        $recipient = $this->staff();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $recipient->id])->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $recipient->id])
            ->assertStatus(422);
    }

    public function test_staff_cannot_forward(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        $staff = $this->staff();
        app(AcicService::class)->assignCheques($staff, $acic, $this->approvedIds());

        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $staff->id])
            ->assertForbidden();
    }

    public function test_forwarding_requires_a_valid_active_recipient(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        $inactive = User::factory()->create(['role' => UserRole::Staff, 'is_active' => false]);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/acics/{$acic->id}/forward", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_by');

        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_by');
    }

    public function test_a_forwarded_acic_accepts_no_more_cheques(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        $approved = $this->approvedIds();
        app(AcicService::class)->assignCheques($this->staff(), $acic, [$approved[0]]);
        app(AcicService::class)->approve($this->admin(), $acic);

        $recipient = $this->staff();
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/forward", ['received_by' => $recipient->id])->assertOk();

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$approved[1]]])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- completion

    /** Forward an ACIC carrying the approved cheques, ready for the teller. */
    private function forwardedAcic(): Acic
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());
        // Forwarding follows the sign-off, so the ACIC is approved on the way through.
        $acic = app(AcicService::class)->approve($this->admin(), $acic);

        return app(AcicService::class)->forward($this->admin(), $acic, $this->staff());
    }

    public function test_a_teller_can_complete_a_forwarded_acic(): void
    {
        $acic = $this->forwardedAcic();
        $teller = $this->teller();
        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.awaits_teller', false);

        $acic->refresh();
        $this->assertSame(AcicStatus::Completed, $acic->status);
        $this->assertNotNull($acic->completed_at);
        $this->assertSame($teller->id, $acic->completed_by);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'completed_acic']);
    }

    /** Completing must bring the cheque records with it, not leave them behind. */
    public function test_completing_stamps_receipt_on_the_cheques(): void
    {
        $acic = $this->forwardedAcic();
        $teller = $this->teller();

        // None of the approved cheques were ever confirmed as received.
        $this->assertSame(3, Cheque::where('acic_id', $acic->id)->whereNull('received_at')->count());

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $this->assertSame(0, Cheque::where('acic_id', $acic->id)->whereNull('received_at')->count());
        $this->assertSame(
            3,
            Cheque::where('acic_id', $acic->id)->where('received_by', $teller->id)->count(),
        );
    }

    /** An existing receipt stamp belongs to whoever made it and must not be overwritten. */
    public function test_completing_does_not_overwrite_an_existing_receipt(): void
    {
        $acic = $this->forwardedAcic();
        $earlier = $this->teller();
        $first = Cheque::where('acic_id', $acic->id)->orderBy('cheque_number')->first();
        $first->update(['received_by' => $earlier->id, 'received_at' => now()->subDay()]);

        $teller = $this->teller();
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $this->assertSame($earlier->id, $first->refresh()->received_by);
    }

    public function test_an_acic_that_is_not_forwarded_cannot_be_completed(): void
    {
        $this->seedCheques();
        $acic = app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->teller());

        // Still Open.
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertStatus(422);

        app(AcicService::class)->assignCheques($this->staff(), $acic, $this->approvedIds());

        // Now Used, but still not forwarded.
        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertStatus(422);

        $this->assertSame(AcicStatus::Used, $acic->refresh()->status);
    }

    public function test_an_acic_cannot_be_completed_twice(): void
    {
        $acic = $this->forwardedAcic();
        Sanctum::actingAs($this->teller());

        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertStatus(422);
    }

    public function test_only_a_teller_can_complete(): void
    {
        $acic = $this->forwardedAcic();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertForbidden();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertForbidden();

        $this->assertSame(AcicStatus::Forwarded, $acic->refresh()->status);
    }

    public function test_a_completed_acic_accepts_no_more_cheques(): void
    {
        $acic = $this->forwardedAcic();
        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $spare = Cheque::where('cheque_number', 4)->first();
        $spare->update(['status' => ChequeStatus::Approved]);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$spare->id]])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------- tabs

    public function test_the_forwarded_tab_shows_only_records_awaiting_the_teller(): void
    {
        $acic = $this->forwardedAcic();
        // A second, still-open ACIC that must not appear.
        app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->teller());

        $this->getJson('/api/v1/acics?status=forwarded')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acic_number', $acic->acic_number);
    }

    public function test_a_completed_record_moves_from_the_forwarded_tab_to_the_completed_tab(): void
    {
        $acic = $this->forwardedAcic();
        Sanctum::actingAs($this->teller());

        $this->getJson('/api/v1/acics?status=forwarded')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/acics?status=completed')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $this->getJson('/api/v1/acics?status=forwarded')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/acics?status=completed')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acic_number', $acic->acic_number);
    }

    /**
     * The category tabs filter on what an ACIC carries, not on how it was opened. An ACIC
     * holding both kinds shows up under either, and an empty one under neither.
     */
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

    public function test_the_all_tab_shows_every_record_whatever_its_status(): void
    {
        $this->forwardedAcic();
        app(AcicService::class)->create($this->admin()); // open
        Sanctum::actingAs($this->teller());

        $this->getJson('/api/v1/acics?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
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
            ->assertJsonPath('data.status', 'forwarded')
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

    public function test_show_reports_completion_details_once_completed(): void
    {
        $acic = $this->forwardedAcic();
        $teller = $this->teller();
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/complete")->assertOk();

        $this->getJson("/api/v1/acics/{$acic->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_by.name', $teller->name)
            ->assertJsonPath('data.awaits_teller', false);
    }

    public function test_guests_cannot_touch_acics(): void
    {
        $this->getJson('/api/v1/acics')->assertUnauthorized();
        $this->postJson('/api/v1/acics')->assertUnauthorized();
    }
}

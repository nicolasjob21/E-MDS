<?php

namespace Tests\Feature;

use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ChequeLog;
use App\Models\Lddap;
use App\Models\LddapUpdateRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\AcicService;
use App\Services\LddapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LddapUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A numbered record goes on an ACIC, which needs a registered ACIC series.
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
     * Register a series and `$count` LDDAPs, forwarded and received back — Returned for ACIC,
     * awaiting the admin's action. With `$numbered`, the first is also approved and put on an
     * ACIC, which is when it takes its check number.
     */
    private function seedLddap(int $count = 1, bool $numbered = false): Lddap
    {
        $service = app(LddapService::class);
        $service->addRange($this->admin(), 1, 10);
        $staff = $this->staff();
        $unit = Unit::firstOrCreate(['name' => 'ACCOUNTING']);
        $first = null;

        foreach (range(1, $count) as $i) {
            $lddap = $service->register($staff, [
                'lddap_no' => 'LDDAP-000'.$i,
                'obj_no' => 'OBJ-'.$i,
                'payee_name' => 'Payee '.$i,
                'amount' => 100 * $i,
            ]);
            $lddap = $service->forward($staff, $lddap, [
                'forward_to' => 'Accounting', 'unit_id' => $unit->id, 'date_forwarded' => '2026-09-22',
            ]);
            $lddap = $service->receive($staff, $lddap, ['unit_id' => $unit->id, 'date_received' => '2026-09-23']);
            $first ??= $lddap;
        }

        if ($numbered) {
            $admin = $this->admin();
            $first = $service->approve($admin, $first);
            $acic = app(AcicService::class)->create($admin);
            $service->assignToAcic($staff, $acic, [$first->id]);
            $first = $first->fresh(['lddapCheck', 'usedBy']);
        }

        return $first;
    }

    /** @return array<string, mixed> */
    private function correction(array $overrides = []): array
    {
        return array_merge([
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-999',
            'payee_name' => 'Corrected Payee',
            'amount' => 250.75,
            'reason' => 'The payee and OBJ number were entered from the wrong voucher.',
        ], $overrides);
    }

    // ------------------------------------------------------------------ requesting

    public function test_staff_can_propose_a_correction(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.proposed_payee_name', 'Corrected Payee')
            ->assertJsonPath('data.proposed_obj_no', 'OBJ-999')
            ->assertJsonPath('data.proposed_amount', '250.75');

        // Nothing on the LDDAP itself has changed yet.
        $lddap->refresh();
        $this->assertSame('Payee 1', $lddap->payee_name);
        $this->assertSame('OBJ-1', $lddap->obj_no);
    }

    public function test_a_reason_is_required(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction(['reason' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_no_op_correction_is_rejected(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction([
            'lddap_no' => $lddap->lddap_no,
            'obj_no' => $lddap->obj_no,
            'payee_name' => $lddap->payee_name,
            'amount' => $lddap->amount,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_only_one_pending_request_per_lddap(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction(['payee_name' => 'Another']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');

        $this->assertSame(1, LddapUpdateRequest::count());
    }

    public function test_a_correction_cannot_take_another_records_lddap_number(): void
    {
        $this->seedLddap(2);
        $first = Lddap::where('lddap_no', 'LDDAP-0001')->firstOrFail();

        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/lddaps/{$first->id}/update-requests", $this->correction([
            'lddap_no' => 'LDDAP-0002',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no');
    }

    public function test_only_staff_can_propose_a_correction(): void
    {
        $lddap = $this->seedLddap();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertForbidden();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertForbidden();
    }

    // -------------------------------------------------------------------- the hold

    public function test_a_pending_request_blocks_teller_receipt(): void
    {
        $lddap = $this->seedLddap(numbered: true);
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');
    }

    public function test_a_pending_request_blocks_the_admins_action(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');

        $this->assertSame(LddapStatus::ReturnedForAcic, $lddap->fresh()->status);
    }

    public function test_the_hold_lifts_once_the_request_is_resolved(): void
    {
        $lddap = $this->seedLddap(numbered: true);
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/reject")->assertOk();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")->assertOk();
    }

    public function test_the_list_flags_an_lddap_on_hold(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/lddaps')->assertOk()->assertJsonPath('data.0.has_pending_update', false);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        $this->getJson('/api/v1/lddaps')->assertOk()->assertJsonPath('data.0.has_pending_update', true);
    }

    // -------------------------------------------------------------------- approval

    public function test_an_admin_approval_applies_the_corrected_details(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve", ['review_note' => 'Verified.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $lddap->refresh();
        $this->assertSame('Corrected Payee', $lddap->payee_name);
        $this->assertSame('OBJ-999', $lddap->obj_no);
        $this->assertSame('250.75', $lddap->amount);
    }

    public function test_the_check_number_is_never_changed_by_a_correction(): void
    {
        $lddap = $this->seedLddap(numbered: true);
        $checkId = $lddap->lddap_check_id;
        $checkNo = $lddap->lddapCheck->check_no;

        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        $lddap->refresh();
        $this->assertSame($checkId, $lddap->lddap_check_id);
        $this->assertSame($checkNo, $lddap->lddapCheck->check_no);
    }

    public function test_a_rejection_leaves_the_lddap_untouched(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/reject", ['review_note' => 'Not justified.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $lddap->refresh();
        $this->assertSame('Payee 1', $lddap->payee_name);
        $this->assertSame('OBJ-1', $lddap->obj_no);
    }

    public function test_a_request_cannot_be_reviewed_twice(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/lddap-update-requests/{$id}/reject")->assertStatus(422);
    }

    public function test_approval_is_refused_if_the_number_was_taken_meanwhile(): void
    {
        $this->seedLddap(2);
        $first = Lddap::where('lddap_no', 'LDDAP-0001')->firstOrFail();
        $second = Lddap::where('lddap_no', 'LDDAP-0002')->firstOrFail();

        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$first->id}/update-requests", $this->correction([
            'lddap_no' => 'LDDAP-FREE',
        ]))->assertCreated()->json('data.id');

        // Another record takes the proposed number before the admin gets to it.
        $second->update(['lddap_no' => 'LDDAP-FREE']);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no');

        $this->assertSame('LDDAP-0001', $first->fresh()->lddap_no);
        $this->assertSame(RequestStatus::Pending, LddapUpdateRequest::find($id)->status);
    }

    public function test_only_an_admin_can_resolve_a_request(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertForbidden();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/reject")->assertForbidden();
    }

    // -------------------------------------------------------------------- listings

    public function test_the_admin_queue_and_the_per_record_history(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/lddap-update-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lddap.lddap_no', 'LDDAP-0001');

        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        $this->getJson('/api/v1/lddap-update-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lddap-update-requests?status=approved')->assertOk()->assertJsonCount(1, 'data');

        // The history is readable by anyone authenticated, outcomes included.
        Sanctum::actingAs($this->teller());
        $this->getJson("/api/v1/lddaps/{$lddap->id}/update-requests")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'approved');
    }

    // ------------------------------------------------- admin direct correction

    public function test_an_admin_can_correct_the_details_directly_and_it_takes_effect_at_once(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-CORRECTED',
            'payee_name' => 'Corrected Payee',
            'amount' => 999.99,
            'reason' => 'Corrected on the spot from the source voucher.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.applied_directly', true);

        $lddap->refresh();
        $this->assertSame('OBJ-CORRECTED', $lddap->obj_no);
        $this->assertSame('Corrected Payee', $lddap->payee_name);
        $this->assertSame('999.99', $lddap->amount);
    }

    public function test_a_direct_correction_requires_a_reason(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-X',
            'payee_name' => 'Someone',
            'amount' => 50,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('OBJ-1', $lddap->fresh()->obj_no);
    }

    public function test_a_direct_correction_is_recorded_in_the_history(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-X',
            'payee_name' => 'Someone',
            'amount' => 50,
            'reason' => 'Amount transposed on entry.',
        ])->assertOk();

        $this->getJson("/api/v1/lddaps/{$lddap->id}/update-requests")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.applied_directly', true)
            ->assertJsonPath('data.0.reason', 'Amount transposed on entry.');

        $this->assertSame(1, ChequeLog::where('action', 'updated_lddap')->count());
    }

    public function test_a_direct_correction_is_refused_while_a_request_is_pending(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001',
            'amount' => 42,
            'reason' => 'Editing around the pending request.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');
    }

    public function test_a_direct_correction_cannot_take_another_records_number(): void
    {
        $this->seedLddap(2);
        $first = Lddap::where('lddap_no', 'LDDAP-0001')->firstOrFail();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/v1/lddaps/{$first->id}", [
            'lddap_no' => 'LDDAP-0002',
            'amount' => 42,
            'reason' => 'Trying to reuse a number that is taken.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap_no');
    }

    public function test_staff_and_teller_cannot_correct_directly(): void
    {
        $lddap = $this->seedLddap();
        $payload = [
            'lddap_no' => 'LDDAP-0001',
            'amount' => 42,
            'reason' => 'Should not be permitted.',
        ];

        Sanctum::actingAs($this->staff());
        $this->patchJson("/api/v1/lddaps/{$lddap->id}", $payload)->assertForbidden();

        Sanctum::actingAs($this->teller());
        $this->patchJson("/api/v1/lddaps/{$lddap->id}", $payload)->assertForbidden();

        $this->assertSame('OBJ-1', $lddap->fresh()->obj_no);
    }

    // ----------------------------------------------------------- the RTS loop

    /**
     * The correction path now runs through RTS: the admin sends the record back to Registered,
     * the details are corrected, and it is forwarded again. Approving a correction changes the
     * details only — it never moves the record in its routing.
     */
    public function test_approving_a_correction_leaves_the_routing_alone(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        $lddap->refresh();
        $this->assertSame('OBJ-999', $lddap->obj_no);
        $this->assertSame(LddapStatus::ReturnedForAcic, $lddap->status);
        $this->assertNull($lddap->reviewed_at);
    }

    public function test_an_rtsd_record_is_corrected_and_forwarded_again(): void
    {
        $lddap = $this->seedLddap();
        $service = app(LddapService::class);
        $unit = Unit::firstOrCreate(['name' => 'ACCOUNTING']);

        // Admin sends it back.
        $service->rts($this->admin(), $lddap, [
            'received_on' => '2026-09-23', 'received_by' => 'M. Santos', 'unit_id' => $unit->id,
            'rts_date' => '2026-09-24', 'note' => 'Wrong OBJ code.',
        ]);
        $this->assertSame(LddapStatus::Rts, $lddap->fresh()->status);

        // Staff correct it, admin approves the correction — still RTS.
        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();
        $this->assertSame(LddapStatus::Rts, $lddap->fresh()->status);

        // Forwarded again, received again, approved.
        $staff = $this->staff();
        $lddap = $service->forward($staff, $lddap->fresh(), [
            'forward_to' => 'Accounting', 'unit_id' => $unit->id, 'date_forwarded' => '2026-09-24',
        ]);
        $lddap = $service->receive($staff, $lddap, ['unit_id' => $unit->id, 'date_received' => '2026-09-25']);
        $this->assertSame(LddapStatus::Approved, $service->approve($this->admin(), $lddap)->status);
    }

    /** A canceled record is closed to corrections, from staff and from an admin alike. */
    public function test_a_canceled_record_takes_no_correction(): void
    {
        $lddap = $this->seedLddap();
        app(LddapService::class)->cancel($this->admin(), $lddap, ['note' => 'Withdrawn.']);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/v1/lddaps/{$lddap->id}", $this->correction())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');

        $this->assertSame('OBJ-1', $lddap->fresh()->obj_no);
    }

    public function test_any_staff_member_may_propose_a_correction(): void
    {
        $lddap = $this->seedLddap();

        // Not only the staff member who registered it.
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated();
    }

    public function test_staff_cannot_read_the_admin_queue(): void
    {
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/lddap-update-requests')->assertForbidden();
    }
}

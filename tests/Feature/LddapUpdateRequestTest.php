<?php

namespace Tests\Feature;

use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ChequeLog;
use App\Models\Lddap;
use App\Models\LddapUpdateRequest;
use App\Models\User;
use App\Services\LddapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LddapUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

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

    /** Register a series and use `$count` numbers, returning the LDDAPs. */
    private function seedLddap(int $count = 1): Lddap
    {
        app(LddapService::class)->addRange($this->admin(), 1, 10);

        $rows = array_map(fn (int $i) => [
            'lddap_no' => 'LDDAP-000'.$i,
            'obj_no' => 'OBJ-'.$i,
            'payee_name' => 'Payee '.$i,
            'amount' => 100 * $i,
        ], range(1, $count));

        return app(LddapService::class)
            ->useCheckNumbers($this->staff(), 1, $rows)
            ->first();
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
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/receive")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');
    }

    public function test_a_pending_request_blocks_admin_review(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lddap');

        $this->assertSame(LddapStatus::Used, $lddap->fresh()->status);
    }

    public function test_the_hold_lifts_once_the_request_is_resolved(): void
    {
        $lddap = $this->seedLddap();
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
        $lddap = $this->seedLddap();
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

    // ----------------------------------------------------- the compliance loop

    public function test_a_compliance_record_returns_to_staff_with_the_admin_remark(): void
    {
        $lddap = $this->seedLddap();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'The OBJ number belongs to a different voucher.',
        ])->assertOk();

        // The staff member sees the record flagged for their action, with the remark.
        Sanctum::actingAs($this->staff());
        $row = $this->getJson('/api/v1/lddaps?status=compliance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertTrue($row['awaits_compliance']);
        $this->assertSame('The OBJ number belongs to a different voucher.', $row['review_note']);
        $this->assertFalse($row['is_final']);
    }

    /**
     * A returned record is handed back to one person — the staff member who used the check
     * number. Another staff member correcting it would be answering a remark they never got.
     */
    public function test_only_the_staff_member_a_record_was_returned_to_may_correct_it(): void
    {
        $lddap = $this->seedLddap();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'OBJ number is wrong.',
        ])->assertOk();

        // A different staff member is refused, by name.
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertStatus(422)
            ->assertJsonPath('errors.lddap.0', "LDDAP LDDAP-0001 was returned to {$lddap->usedBy->name}. Only they can update it.");

        $this->assertDatabaseCount('lddap_update_requests', 0);

        // The staff member it was returned to may.
        Sanctum::actingAs($lddap->usedBy);
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated();
    }

    /** The restriction is specific to a returned record; an ordinary correction is open. */
    public function test_any_staff_member_may_propose_a_correction_on_a_record_not_returned(): void
    {
        $lddap = $this->seedLddap();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated();
    }

    public function test_returning_a_record_notifies_the_staff_member_it_was_returned_to(): void
    {
        $lddap = $this->seedLddap();
        $owner = $lddap->usedBy;

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'OBJ number is wrong.',
        ])->assertOk();

        Sanctum::actingAs($owner);
        $note = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        $this->assertSame('request', $note['kind']);
        $this->assertSame('LDDAP LDDAP-0001 returned to you', $note['title']);
        $this->assertStringContainsString('it needs your attention', $note['message']);
        $this->assertStringContainsString('OBJ number is wrong.', $note['message']);
    }

    public function test_the_full_compliance_loop_ends_in_approval(): void
    {
        $lddap = $this->seedLddap();

        // 1. Admin returns it for compliance with a remark.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'OBJ number is wrong.',
        ])->assertOk();

        // 2. The staff member it was returned to updates the details against that remark.
        Sanctum::actingAs($lddap->usedBy);
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction([
            'obj_no' => 'OBJ-CORRECT',
        ]))->assertCreated()->json('data.id');

        // 3. While it waits, the record is on hold — it cannot be reviewed.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])
            ->assertStatus(422);

        // 4. Admin confirms the correction. That approval *is* the sign-off: the deficiency is
        //    fixed and accepted, so the record moves straight to Approved.
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        $lddap->refresh();
        $this->assertSame('OBJ-CORRECT', $lddap->obj_no);
        $this->assertSame(LddapStatus::Approved, $lddap->status);

        // 5. ...and it is immediately eligible for an ACIC.
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/lddaps/linkable')
            ->assertOk()
            ->assertJsonPath('data.0.lddap_no', 'LDDAP-0001');
    }

    public function test_approving_a_correction_on_a_record_not_in_compliance_leaves_its_status_alone(): void
    {
        $lddap = $this->seedLddap();

        Sanctum::actingAs($this->staff());
        $id = $this->postJson("/api/v1/lddaps/{$lddap->id}/update-requests", $this->correction())
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/lddap-update-requests/{$id}/approve")->assertOk();

        // Still merely Used — only a compliance record is signed off by the approval.
        $this->assertSame(LddapStatus::Used, $lddap->fresh()->status);
    }

    public function test_an_admin_can_resolve_compliance_by_correcting_it_themselves(): void
    {
        $lddap = $this->seedLddap();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", [
            'status' => 'compliance',
            'review_note' => 'OBJ number is wrong.',
        ])->assertOk();

        $this->patchJson("/api/v1/lddaps/{$lddap->id}", [
            'lddap_no' => 'LDDAP-0001',
            'obj_no' => 'OBJ-CORRECT',
            'payee_name' => 'Payee 1',
            'amount' => 100,
            'reason' => 'Fixed the OBJ number myself rather than sending it back.',
        ])->assertOk();

        $this->assertSame('OBJ-CORRECT', $lddap->fresh()->obj_no);

        $this->postJson("/api/v1/lddaps/{$lddap->id}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_staff_cannot_read_the_admin_queue(): void
    {
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/lddap-update-requests')->assertForbidden();
    }
}

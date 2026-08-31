<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChequeReviewTest extends TestCase
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

    /** Seed a range and use cheque #1 so there is something to review. */
    private function usedCheque(): Cheque
    {
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 1, 3);
        $cheques->useNext($this->staff(), 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-08-29',
        ]);

        return Cheque::where('cheque_number', 1)->first();
    }

    public function test_admin_can_approve_a_used_cheque(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_reviewed', true);

        $cheque->refresh();
        $this->assertSame(ChequeStatus::Approved, $cheque->status);
        $this->assertNotNull($cheque->reviewed_at);
        $this->assertNotNull($cheque->reviewed_by);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'reviewed_cheque', 'cheque_number' => 1]);
    }

    public function test_admin_can_record_a_disapproved_outcome_with_a_note(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [
            'status' => 'disapproved',
            'review_note' => 'Supporting documents were incomplete.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'disapproved')
            ->assertJsonPath('data.review_note', 'Supporting documents were incomplete.');

        $this->assertSame(ChequeStatus::Disapproved, $cheque->refresh()->status);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'reviewed_cheque', 'cheque_number' => 1]);
    }

    public function test_admin_can_record_a_complies_outcome_with_a_note(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [
            'status' => 'complies',
            'review_note' => 'Reviewed against the disbursement voucher — it complies.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'complies');

        $this->assertSame(ChequeStatus::Complies, $cheque->refresh()->status);
    }

    public function test_a_note_is_required_unless_the_outcome_is_approved(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'disapproved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('review_note');

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'complies'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('review_note');
    }

    public function test_the_outcome_must_be_a_real_review_status(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        // "received" is a lifecycle status, not a review outcome.
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'received'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_only_an_admin_can_review(): void
    {
        $cheque = $this->usedCheque();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertForbidden();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertForbidden();

        $this->assertSame(ChequeStatus::Used, $cheque->refresh()->status);
    }

    public function test_an_available_cheque_cannot_be_reviewed(): void
    {
        app(ChequeService::class)->addRange($this->admin(), 1, 3);
        $cheque = Cheque::where('cheque_number', 1)->first();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertStatus(422);
    }

    public function test_an_approved_cheque_cannot_be_reviewed_again(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertOk();
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertStatus(422);
    }

    public function test_a_disapproved_cheque_cannot_be_reviewed_again(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [
            'status' => 'disapproved',
            'review_note' => 'Rejected — the payee is not an accredited supplier.',
        ])->assertOk();

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertSame(ChequeStatus::Disapproved, $cheque->refresh()->status);
    }

    /**
     * "Returned" hands the cheque back to the staff member rather than settling it,
     * so it must stay reviewable once they have complied.
     */
    public function test_a_cheque_returned_for_compliance_can_be_reviewed_again(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [
            'status' => 'complies',
            'review_note' => 'Attach the signed disbursement voucher.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'complies')
            ->assertJsonPath('data.awaits_compliance', true)
            ->assertJsonPath('data.is_final', false);

        // The staff member complies; the admin can now settle it.
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_final', true)
            ->assertJsonPath('data.awaits_compliance', false);

        $this->assertSame(ChequeStatus::Approved, $cheque->refresh()->status);
        // The second review replaces the first note and reviewer stamp.
        $this->assertNull($cheque->review_note);
    }

    public function test_returning_for_compliance_notifies_the_staff_member_who_used_it(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 1, 3);
        $cheques->useNext($staff, 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-08-29',
        ]);
        $cheque = Cheque::where('cheque_number', 1)->first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", [
            'status' => 'complies',
            'review_note' => 'Attach the signed disbursement voucher.',
        ])->assertOk();

        $notification = $staff->fresh()->unreadNotifications()->first();
        $this->assertNotNull($notification);
        // It is an action item for the staff member, not a verdict.
        $this->assertSame('request', $notification->data['kind']);
    }

    public function test_a_cheque_on_hold_cannot_be_reviewed(): void
    {
        $cheque = $this->usedCheque();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", [
            'payee_name' => 'Acme Corporation',
            'amount' => 1250.50,
            'cheque_date' => '2026-08-30',
            'reason' => 'The payee name is misspelled.',
        ])->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertStatus(422);
    }

    public function test_a_reviewed_cheque_keeps_its_receipt_record(): void
    {
        $cheque = $this->usedCheque();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertOk();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            // Receipt is recorded by its own timestamp, so it survives the status moving on.
            ->assertJsonPath('data.is_received', true);

        $this->assertNotNull($cheque->refresh()->received_at);
    }

    public function test_a_reviewed_cheque_can_no_longer_be_received(): void
    {
        $cheque = $this->usedCheque();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertOk();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertStatus(422);
    }

    public function test_the_list_can_be_filtered_by_a_review_outcome(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertOk();

        $this->getJson('/api/v1/cheques?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cheque_number', 1);
    }

    public function test_the_summary_counts_the_review_outcomes(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/review", ['status' => 'approved'])->assertOk();

        $this->getJson('/api/v1/cheques/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.total', 3)
            ->assertJsonPath('data.counts.available', 2)
            ->assertJsonPath('data.counts.used', 0)
            ->assertJsonPath('data.counts.approved', 1);
    }
}

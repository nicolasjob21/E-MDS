<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\ChequeUpdateRequest;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function teller(): User
    {
        return User::factory()->teller()->create();
    }

    /** Seed a range and use cheque #1 so it has editable details. */
    private function usedCheque(): Cheque
    {
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 3, 1);
        $cheques->useNext($this->staff(), 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-07-19',
        ]);

        return Cheque::where('cheque_number', 1)->first();
    }

    /**
     * A valid "propose new details" payload.
     *
     * @return array<string, mixed>
     */
    private function proposePayload(array $overrides = []): array
    {
        return array_merge([
            'payee_name' => 'Acme Corporation',
            'amount' => 1250.50,
            'cheque_date' => '2026-07-20',
            'reason' => 'Payee name is misspelled and must be corrected.',
        ], $overrides);
    }

    public function test_staff_can_request_a_detail_update(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.proposed_payee_name', 'Acme Corporation');

        $this->assertDatabaseHas('cheque_update_requests', [
            'cheque_id' => $cheque->id,
            'status' => 'pending',
            'proposed_payee_name' => 'Acme Corporation',
        ]);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'requested_update', 'cheque_number' => 1]);
    }

    public function test_a_reason_and_proposed_values_are_required(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'payee_name', 'amount', 'cheque_date']);
    }

    public function test_a_no_op_request_is_rejected(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());

        // Propose the exact same values the cheque already has.
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-07-19',
            'reason' => 'No actual change here.',
        ])->assertStatus(422);
    }

    public function test_non_staff_cannot_request_an_update(): void
    {
        $cheque = $this->usedCheque();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertForbidden();
    }

    public function test_an_available_cheque_cannot_be_requested(): void
    {
        app(ChequeService::class)->addRange($this->admin(), 3, 1);
        $cheque = Cheque::where('cheque_number', 1)->first(); // available
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())
            ->assertStatus(422);
    }

    public function test_a_cheque_cannot_have_two_pending_requests(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertStatus(422);
    }

    public function test_admin_can_approve_and_the_proposed_values_are_applied(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $req = ChequeUpdateRequest::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/update-requests/{$req->id}/approve", [
            'review_note' => 'Corrected per finance.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('cheques', [
            'id' => $cheque->id,
            'payee_name' => 'Acme Corporation',
        ]);
        $this->assertDatabaseHas('cheque_logs', ['action' => 'approved_update', 'cheque_number' => 1]);
    }

    public function test_admin_can_reject_and_the_cheque_is_unchanged(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $req = ChequeUpdateRequest::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/update-requests/{$req->id}/reject", [
            'review_note' => 'No change needed.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('cheques', ['id' => $cheque->id, 'payee_name' => 'Acme Co']);
    }

    public function test_a_pending_request_holds_the_cheque_from_teller_confirmation(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();

        // While the request is pending the cheque is on hold — the teller cannot confirm receipt.
        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertStatus(422);

        // Once an admin resolves it (reject here), the hold lifts.
        $req = ChequeUpdateRequest::first();
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/update-requests/{$req->id}/reject")->assertOk();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received');
    }

    public function test_a_cheques_request_history_is_readable_with_outcome(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $req = ChequeUpdateRequest::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/update-requests/{$req->id}/approve", ['review_note' => 'Done.'])->assertOk();

        // Any authenticated user can read the history, and it shows the resolved outcome.
        Sanctum::actingAs($this->teller());
        $this->getJson("/api/v1/cheques/{$cheque->id}/update-requests")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.review_note', 'Done.')
            ->assertJsonPath('data.0.reviewed_by.username', fn ($u) => is_string($u));
    }

    public function test_staff_cannot_approve_requests(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $req = ChequeUpdateRequest::first();

        // Still acting as staff.
        $this->postJson("/api/v1/update-requests/{$req->id}/approve", [])->assertForbidden();
    }

    public function test_a_reviewed_request_cannot_be_reviewed_again(): void
    {
        $cheque = $this->usedCheque();
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$cheque->id}/update-requests", $this->proposePayload())->assertCreated();
        $req = ChequeUpdateRequest::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/update-requests/{$req->id}/reject", [])->assertOk();
        $this->postJson("/api/v1/update-requests/{$req->id}/reject", [])->assertStatus(422);
    }
}

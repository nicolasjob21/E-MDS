<?php

namespace Tests\Feature;

use App\Enums\AcicTellerStatus;
use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\AcicService;
use App\Services\AcicTellerService;
use App\Services\ChequeFlowService;
use App\Services\ChequeService;
use App\Support\Validity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The cheque flow, end to end:
 *
 *   Registered → Out for Signature → (Received) → For ACIC → Approved ─┬─▶ Released to Payee
 *                                                                      └─▶ Forwarded to Teller
 *                                                                               → Accepted → Completed
 *
 * and the three ways out of it — RTS, Cancel and Spoil.
 */
class ChequeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcicSeries(1, 20);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Ada Admin']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'name' => 'Sam Staff']);
    }

    private function teller(string $name = 'Tess Teller'): User
    {
        return User::factory()->teller()->create(['name' => $name]);
    }

    /** A cheque claimed from the book, with its details. Status: Registered. */
    private function registered(?User $by = null): Cheque
    {
        $by ??= $this->staff();

        if (Cheque::query()->doesntExist()) {
            app(ChequeService::class)->addRange($this->admin(), 100, 119);
        }

        return app(ChequeService::class)->useNext($by, app(ChequeService::class)->nextAvailable()->cheque_number, [
            'payee_name' => 'Acme Co', 'account_no' => '0012345678', 'unit_name' => 'CG-4 Logistics',
            'amount' => 1500.50, 'cheque_date' => '2026-09-20',
        ]);
    }

    /** Drafted, approved and printed, so it is For Signature — waiting for an ACIC. */
    private function forSignature(?User $admin = null): Cheque
    {
        return $this->readyForAcic($this->registered(), $admin ?? $this->admin());
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['name' => 'Sue Super']);
    }

    /** On an ACIC, ready for either branch. @return array{Cheque, Acic} */
    private function onAcic(?User $admin = null): array
    {
        $admin ??= $this->admin();
        $cheque = $this->forSignature($admin);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$cheque->id]);

        return [$cheque->fresh(), $acic->fresh()];
    }

    // ------------------------------------------------------------------ the order

    public function test_the_flow_runs_in_order(): void
    {
        Notification::fake();
        $staff = $this->staff();
        $super = $this->superAdmin();
        $cheque = $this->registered($staff);

        // Used: no status shown.
        $this->assertSame(ChequeStatus::Registered, $cheque->status);
        $this->assertSame('', $cheque->status->label());

        // Print Draft → For Checking, and the admin in charge is told.
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/cheques/{$cheque->id}/print-draft")
            ->assertOk()->assertJsonPath('data.status', 'for_checking')->assertJsonPath('data.status_label', 'For Checking');
        Notification::assertSentTo($super, ActivityNotification::class);

        // Approve → For Final Print, and the preparer is told.
        Sanctum::actingAs($super);
        $this->postJson("/api/v1/cheques/{$cheque->id}/approve-draft")
            ->assertOk()->assertJsonPath('data.status', 'for_final_print');
        Notification::assertSentTo($staff, ActivityNotification::class);

        // Final Print, confirmed → For Signature, which is what an ACIC takes.
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/cheques/{$cheque->id}/final-print")
            ->assertOk()->assertJsonPath('data.status', 'for_signature')->assertJsonPath('data.status_label', 'For Signature');
        Sanctum::actingAs($super);
        $this->getJson('/api/v1/cheques?search='.$cheque->cheque_number)->assertJsonPath('data.0.can_assign', true);

        $steps = $cheque->statusHistory()->orderBy('id')->pluck('action')->all();
        $this->assertSame(['draft_printed', 'draft_approved', 'final_printed'], $steps);
    }

    /** Return → For Compliance, fix it, print a new draft — as many times as it takes. */
    public function test_a_returned_draft_is_corrected_and_goes_round_again(): void
    {
        Notification::fake();
        $staff = $this->staff();
        $super = $this->superAdmin();
        $cheque = $this->registered($staff);
        app(ChequeFlowService::class)->printDraft($staff, $cheque);

        Sanctum::actingAs($super);
        // A return needs a comment on what to change.
        $this->postJson("/api/v1/cheques/{$cheque->id}/return-draft", ['comment' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/cheques/{$cheque->id}/return-draft", ['comment' => 'Payee is misspelled.'])
            ->assertOk()->assertJsonPath('data.status', 'for_compliance');
        Notification::assertSentTo($staff, ActivityNotification::class,
            fn (ActivityNotification $n) => str_contains($n->toArray($staff)['message'], 'Payee is misspelled.'));

        // The preparer corrects it and prints a new draft.
        Sanctum::actingAs($staff);
        $this->putJson("/api/v1/cheques/{$cheque->id}", [
            'payee_name' => 'Acme Company', 'amount' => 1500.50, 'cheque_date' => '2026-09-20',
        ])->assertOk()->assertJsonPath('data.payee_name', 'Acme Company');
        $this->postJson("/api/v1/cheques/{$cheque->id}/print-draft")->assertOk()->assertJsonPath('data.status', 'for_checking');

        // And again.
        Sanctum::actingAs($super);
        $this->postJson("/api/v1/cheques/{$cheque->id}/return-draft", ['comment' => 'Amount too.'])->assertOk();
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/cheques/{$cheque->id}/print-draft")->assertOk();

        $history = $this->getJson("/api/v1/cheques/{$cheque->id}/status-history")->assertOk()->json('data');
        $this->assertSame(
            ['used', 'draft_printed', 'draft_returned', 'edited', 'draft_printed', 'draft_returned', 'draft_printed'],
            array_column($history, 'action'),
        );
        // The timeline opens with the cheque being used: who, and when.
        $this->assertSame('Sam Staff', $history[0]['user']['name']);
        $this->assertSame($cheque->fresh()->used_at->toIso8601String(), $history[0]['created_at']);
        $this->assertSame('Payee is misspelled.', $history[2]['note']);
        $this->assertSame('Sue Super', $history[2]['user']['name']);
    }

    /** The admins in charge — Administrators and Super Admins — check drafts; nobody else. */
    public function test_administrators_and_super_admins_check_drafts(): void
    {
        $staff = $this->staff();
        $cheque = $this->registered($staff);
        app(ChequeFlowService::class)->printDraft($staff, $cheque);

        foreach ([$staff, $this->teller()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/cheques/{$cheque->id}/approve-draft")->assertForbidden();
            $this->postJson("/api/v1/cheques/{$cheque->id}/return-draft", ['comment' => 'No.'])->assertForbidden();
        }

        // An Administrator returns it; after a new draft, a Super Admin approves it.
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/cheques?search='.$cheque->cheque_number)->assertJsonPath('data.0.can_check_draft', true);
        $this->postJson("/api/v1/cheques/{$cheque->id}/return-draft", ['comment' => 'Fix the payee.'])
            ->assertOk()->assertJsonPath('data.status', 'for_compliance');
        app(ChequeFlowService::class)->printDraft($staff, $cheque->fresh());
        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/approve-draft")->assertOk()->assertJsonPath('data.status', 'for_final_print');

        // Tellers do not prepare cheques either.
        Sanctum::actingAs($this->teller('Tim'));
        $this->postJson("/api/v1/cheques/{$this->registered()->id}/print-draft")->assertForbidden();
    }

    /** Details can be edited only with no status yet, or while For Compliance. */
    public function test_details_are_editable_only_before_the_first_draft_or_for_compliance(): void
    {
        $staff = $this->staff();
        $cheque = $this->registered($staff);
        $edit = fn (string $payee) => $this->putJson("/api/v1/cheques/{$cheque->id}", [
            'payee_name' => $payee, 'account_no' => '0012', 'unit_name' => 'CG-4 Logistics',
            'amount' => 99.95, 'cheque_date' => '2026-09-21',
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/cheques?search='.$cheque->cheque_number)->assertJsonPath('data.0.can_edit', true);
        $edit('First Payee')->assertOk()
            ->assertJsonPath('data.account_no', '0012')->assertJsonPath('data.amount', '99.95');

        // What changed is on the timeline.
        $step = $cheque->statusHistory()->where('action', 'edited')->sole();
        $this->assertSame(['from' => 'Acme Co', 'to' => 'First Payee'], $step->details['payee_name']);

        app(ChequeFlowService::class)->printDraft($staff, $cheque);
        $edit('While checking')->assertUnprocessable()->assertJsonValidationErrors(['cheque' => 'can be edited only']);

        app(ChequeFlowService::class)->approveDraft($this->superAdmin(), $cheque->fresh());
        $edit('After approval')->assertUnprocessable();

        $this->assertSame('First Payee', $cheque->fresh()->payee_name);
    }

    /** No skipping, and no walking backwards. */
    public function test_out_of_order_moves_are_blocked(): void
    {
        $staff = $this->staff();
        $super = $this->superAdmin();
        $cheque = $this->registered($staff);

        // Final Print before a draft was approved.
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/cheques/{$cheque->id}/final-print")
            ->assertStatus(422)->assertJsonValidationErrors(['cheque' => 'cannot be moved']);

        // Approving a draft nobody printed.
        Sanctum::actingAs($super);
        $this->postJson("/api/v1/cheques/{$cheque->id}/approve-draft")->assertStatus(422);

        // Straight to the payee.
        $this->postJson("/api/v1/cheques/{$cheque->id}/release", [
            'received_by_name' => 'Juan dela Cruz', 'date_received' => '2026-09-22',
        ])->assertStatus(422);

        // A second draft while the first is still being checked.
        app(ChequeFlowService::class)->printDraft($staff, $cheque);
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/cheques/{$cheque->id}/print-draft")->assertStatus(422);

        $this->assertSame(ChequeStatus::ForChecking, $cheque->fresh()->status);
    }

    /** A page that has gone stale cannot act on what it was showing. */
    public function test_a_step_taken_from_a_stale_page_is_refused(): void
    {
        $staff = $this->staff();
        $cheque = $this->registered($staff);
        app(ChequeFlowService::class)->printDraft($staff, $cheque);

        Sanctum::actingAs($this->superAdmin());

        // The page still believes the cheque has no status.
        $this->postJson("/api/v1/cheques/{$cheque->id}/approve-draft", ['expected_status' => 'registered'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque' => ChequeFlowService::CONFLICT]);
    }

    // ------------------------------------------------------------------ the ACIC

    /** "Assign Cheque to ACIC" offers cheques that are For Signature, and nothing else. */
    public function test_only_for_signature_cheques_can_be_assigned(): void
    {
        $admin = $this->admin();
        $ready = $this->forSignature($admin);
        $registered = $this->registered();

        Sanctum::actingAs($admin);

        $linkable = $this->getJson('/api/v1/acics/linkable-cheques')->assertOk()->json('data');
        $this->assertSame([$ready->cheque_number], array_column($linkable, 'cheque_number'));

        $acic = app(AcicService::class)->create($admin);

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$registered->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque_ids' => 'Only cheques that are For Signature']);

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$ready->id]])->assertOk();
        $this->assertSame(ChequeStatus::Approved, $ready->fresh()->status);
    }

    /** Several cheques can share one ACIC number. */
    public function test_several_cheques_share_one_acic(): void
    {
        $admin = $this->admin();
        $a = $this->forSignature($admin);
        $b = $this->forSignature($admin);
        $acic = app(AcicService::class)->create($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$a->id, $b->id]])->assertOk();

        $this->assertSame($acic->id, $a->fresh()->acic_id);
        $this->assertSame($acic->id, $b->fresh()->acic_id);
    }

    // ---------------------------------------------------------- Branch A — the payee

    public function test_release_to_payee_is_final_and_needs_an_acic(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/release", [
            'received_by_name' => 'Juan dela Cruz',
            'date_received' => '2026-09-23',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released_to_payee')
            ->assertJsonPath('data.release.received_by_name', 'Juan dela Cruz');

        // Final: nothing else can be done with it.
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'Changed our minds.'])
            ->assertStatus(422);
    }

    public function test_release_is_blocked_without_an_acic(): void
    {
        $admin = $this->admin();
        $cheque = $this->forSignature($admin);   // signed and back, but on no ACIC

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/release", [
            'received_by_name' => 'Juan dela Cruz', 'date_received' => '2026-09-23',
        ])->assertStatus(422);

        $this->assertSame(ChequeStatus::ForSignature, $cheque->fresh()->status);
    }

    // --------------------------------------------------------- Branch B — the teller

    public function test_forwarding_an_acic_moves_every_cheque_and_tells_the_tellers(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheque, $acic] = $this->onAcic($admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", ['note' => 'For deposit.'])->assertOk();

        $this->assertSame(ChequeStatus::ForwardedToTeller, $cheque->fresh()->status);
        Notification::assertSentTo($teller, ActivityNotification::class);
    }

    /** An ACIC holding a cheque already given to its payee cannot be sent to the bank. */
    public function test_forwarding_is_blocked_if_any_cheque_was_released(): void
    {
        $admin = $this->admin();
        $flow = app(ChequeFlowService::class);
        $a = $this->forSignature($admin);
        $b = $this->forSignature($admin);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$a->id, $b->id]);

        $flow->releaseToPayee($admin, $a->fresh(), [
            'received_by_name' => 'Juan dela Cruz', 'date_received' => '2026-09-23',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => 'already been released to the payee']);

        $this->assertSame(ChequeStatus::Approved, $b->fresh()->status);
    }

    /** Two tellers, one ACIC: the first to accept takes it. */
    public function test_the_first_teller_to_accept_wins(): void
    {
        $admin = $this->admin();
        $first = $this->teller('First Teller');
        $second = $this->teller('Second Teller');
        [$cheque, $acic] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);

        Sanctum::actingAs($first);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.accepted_by.name', 'First Teller');

        $this->assertSame(ChequeStatus::AcceptedByTeller, $cheque->fresh()->status);

        // The second is turned away, by name.
        Sanctum::actingAs($second);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => 'Already accepted by First Teller.']);

        $this->assertSame($first->id, $acic->fresh()->accepted_by);
    }

    public function test_only_the_accepting_teller_completes_it(): void
    {
        $admin = $this->admin();
        $mine = $this->teller('First Teller');
        $other = $this->teller('Second Teller');
        [$cheque, $acic] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        app(AcicTellerService::class)->accept($mine, $acic);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it or take action on it']);

        Sanctum::actingAs($mine);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])->assertOk();

        $acic->refresh();
        $this->assertSame(ChequeStatus::Completed, $cheque->fresh()->status);
        $this->assertSame(AcicTellerStatus::Completed, $acic->teller_status);
    }

    public function test_return_to_admin_puts_every_cheque_back_to_approved(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheque, $acic] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        app(AcicTellerService::class)->accept($teller, $acic);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Signature missing.'])
            ->assertOk();

        $this->assertSame(ChequeStatus::Approved, $cheque->fresh()->status);
        $this->assertNull($acic->fresh()->accepted_by, 'the claim is released');
        $this->assertSame('Signature missing.', $acic->fresh()->return_reason);
        Notification::assertSentTo($admin, ActivityNotification::class);

        // And the admin can send it out again.
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])->assertOk();
        $this->assertSame(ChequeStatus::ForwardedToTeller, $cheque->fresh()->status);
    }

    public function test_the_teller_queue_lists_pending_and_accepted(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        [, $mine] = $this->onAcic($admin);
        [, $loose] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $mine);
        app(AcicTellerService::class)->forwardToTeller($admin, $loose);
        app(AcicTellerService::class)->accept($teller, $mine);

        Sanctum::actingAs($teller);

        $queue = $this->getJson('/api/v1/acics/teller-queue')->assertOk()->json('data');
        $this->assertSame([$loose->acic_number], array_column($queue['pending'], 'acic_number'));
        $this->assertSame([$mine->acic_number], array_column($queue['accepted'], 'acic_number'));
    }

    // ------------------------------------------------------------------ exceptions

    public function test_cancel_is_allowed_only_before_an_acic(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/cancel", ['reason' => 'Raised in error.'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Final.
        $this->postJson("/api/v1/cheques/{$cheque->id}/print-draft")->assertStatus(422);

        // Cancel works at any status before an ACIC — here, mid-check.
        $checking = $this->registered();
        app(ChequeFlowService::class)->printDraft($admin, $checking);
        $this->postJson("/api/v1/cheques/{$checking->id}/cancel", ['reason' => 'Duplicate.'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // And a cheque already on an ACIC is spoiled instead, not cancelled.
        [$onAcic] = $this->onAcic($admin);
        $this->postJson("/api/v1/cheques/{$onAcic->id}/cancel", ['reason' => 'Nope.'])->assertStatus(422);
    }

    public function test_spoil_is_allowed_on_an_acic_but_not_once_a_teller_has_it(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheque] = $this->onAcic($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'Misprinted.'])
            ->assertOk()->assertJsonPath('data.status', 'spoiled')->assertJsonPath('data.status_label', 'Spoiled');

        // Once the ACIC is with a teller, spoiling is refused.
        [$second, $acic2] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic2);
        app(AcicTellerService::class)->accept($teller, $acic2);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$second->id}/spoil", ['reason' => 'Too late.'])->assertStatus(422);
        $this->assertSame(ChequeStatus::AcceptedByTeller, $second->fresh()->status);

        // And before an ACIC it is Cancel, not Spoil.
        $early = $this->forSignature($admin);
        $this->postJson("/api/v1/cheques/{$early->id}/spoil", ['reason' => 'Nope.'])->assertStatus(422);
    }

    public function test_spoiling_moves_the_payment_to_the_next_available_number(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);
        $next = app(ChequeService::class)->nextAvailable()->cheque_number;

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", [
            'reason' => 'Ink smudged over the amount.',
            'replacement_number' => $next,
        ])
            ->assertOk()
            ->assertJsonPath('data.cheque_number', $cheque->cheque_number)
            ->assertJsonPath('data.replaced_by.cheque_number', $next)
            ->assertJsonPath('data.spoil.reason', 'Ink smudged over the amount.')
            ->assertJsonPath('data.spoil.spoiled_by.name', 'Ada Admin');

        $spoiled = $cheque->fresh();
        $replacement = Cheque::where('cheque_number', $next)->sole();

        // The spoiled cheque keeps its number, records who, when and why, and points onward.
        $this->assertSame(ChequeStatus::Spoiled, $spoiled->status);
        $this->assertSame($admin->id, $spoiled->spoiled_by);
        $this->assertNotNull($spoiled->spoiled_at);
        $this->assertSame('Ink smudged over the amount.', $spoiled->exception_reason);
        $this->assertSame($replacement->id, $spoiled->replaced_by_id);

        // The replacement: same details, today's date, the start of the flow, pointing back.
        $this->assertSame(ChequeStatus::Registered, $replacement->status);
        $this->assertSame('Acme Co', $replacement->payee_name);
        $this->assertSame('1500.50', $replacement->amount);
        $this->assertSame('0012345678', $replacement->account_no);
        $this->assertSame('CG-4 Logistics', $replacement->unit_name);
        $this->assertSame(Validity::today()->toDateString(), $replacement->cheque_date->toDateString());
        $this->assertSame($cheque->id, $replacement->replaces_id);
        $this->assertNull($replacement->acic_id);

        // Both directions read back through the API.
        $this->getJson('/api/v1/cheques?search='.$next)
            ->assertOk()->assertJsonPath('data.0.replaces.cheque_number', $cheque->cheque_number);

        // The replacement's timeline opens with its use, naming the cheque it replaces.
        $this->getJson("/api/v1/cheques/{$replacement->id}/status-history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'used')
            ->assertJsonPath('data.0.user.name', 'Ada Admin')
            ->assertJsonPath('data.0.note', "Replaces cheque #{$cheque->cheque_number}.");

        // Neither number is handed out again.
        $after = app(ChequeService::class)->nextAvailable()->cheque_number;
        $this->assertNotContains($after, [$cheque->cheque_number, $next]);
        $this->assertSame($next + 1, $after);
    }

    /** Two cheques on one ACIC, then the first spoiled: [spoiled, other, acic, replacement]. */
    private function spoiledOnSharedAcic(User $admin): array
    {
        $first = $this->forSignature($admin);
        $second = $this->forSignature($admin);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$first->id, $second->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$first->id}/spoil", ['reason' => 'Wrong payee name.'])->assertOk();

        $replacement = Cheque::where('replaces_id', $first->id)->sole();

        return [$first->fresh(), $second->fresh(), $acic->fresh(), $replacement];
    }

    public function test_spoiling_takes_the_cheque_off_its_acic_and_remembers_which(): void
    {
        $admin = $this->admin();
        [$spoiled, $other, $acic] = $this->spoiledOnSharedAcic($admin);

        $this->assertNull($spoiled->acic_id);
        $this->assertSame($acic->id, $spoiled->spoiled_from_acic_id);
        $this->assertSame([$other->id], $acic->cheques()->pluck('id')->all());
        $history = $this->getJson("/api/v1/cheques/{$spoiled->id}/status-history")->json('data');
        $this->assertSame('spoiled', end($history)['action']);
        $this->assertSame(
            "Wrong payee name. — replaced by cheque #{$spoiled->replacedBy->cheque_number}; taken off ACIC #{$acic->acic_number}.",
            end($history)['note'],
        );

        // The ACIC is no longer held up by a record that can never be forwarded.
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        $this->assertSame(ChequeStatus::ForwardedToTeller, $other->fresh()->status);
    }

    public function test_the_replacement_may_take_the_spoiled_cheques_place_on_its_acic(): void
    {
        $admin = $this->admin();
        [$spoiled, , $acic, $replacement] = $this->spoiledOnSharedAcic($admin);

        // Not before it is For Signature.
        $this->postJson("/api/v1/cheques/{$replacement->id}/use-previous-acic")->assertStatus(422);

        $replacement = $this->readyForAcic($replacement, $admin);
        $this->getJson('/api/v1/cheques?search='.$replacement->cheque_number)
            ->assertJsonPath('data.0.previous_acic.acic_number', $acic->acic_number)
            ->assertJsonPath('data.0.previous_acic.allowed', true);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/cheques/{$replacement->id}/use-previous-acic")
            ->assertOk()->assertJsonPath('data.acic_number', $acic->acic_number);

        $replacement->refresh();
        $this->assertSame($acic->id, $replacement->acic_id);
        $this->assertSame(ChequeStatus::Approved, $replacement->status);
        // The spoiled cheque still says where it was.
        $this->assertSame($acic->id, $spoiled->fresh()->spoiled_from_acic_id);

        $history = $this->getJson("/api/v1/cheques/{$replacement->id}/status-history")->json('data');
        $this->assertSame('assigned', end($history)['action']);
        $this->assertSame(
            "Used previous ACIC #{$acic->acic_number} — in place of spoiled cheque #{$spoiled->cheque_number}.",
            end($history)['note'],
        );
    }

    public function test_once_the_old_acic_is_with_a_teller_only_a_new_acic_is_allowed(): void
    {
        $admin = $this->admin();
        [$spoiled, , $acic, $replacement] = $this->spoiledOnSharedAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        $replacement = $this->readyForAcic($replacement, $admin);
        $why = "ACIC #{$acic->acic_number} is with the teller (Pending), so the replacement must go on a new ACIC.";

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/cheques?search='.$replacement->cheque_number)
            ->assertJsonPath('data.0.previous_acic.allowed', false)
            ->assertJsonPath('data.0.previous_acic.reason', $why);
        $this->postJson("/api/v1/cheques/{$replacement->id}/use-previous-acic")
            ->assertStatus(422)->assertJsonPath('errors.acic.0', $why);
        // Nor can the old number be typed into the usual flow while the teller has it.
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => $acic->acic_number, 'cheque_ids' => [$replacement->id]])
            ->assertStatus(422);

        // A new ACIC, the usual way — and the timeline says why.
        $next = app(AcicService::class)->nextNumber();
        $this->postJson('/api/v1/cheques/assign-acic', ['acic_no' => $next, 'cheque_ids' => [$replacement->id]])->assertOk();

        $history = $this->getJson("/api/v1/cheques/{$replacement->id}/status-history")->json('data');
        $this->assertSame(
            "Assigned to a new ACIC #{$next} — spoiled cheque #{$spoiled->cheque_number} was on ACIC #{$acic->acic_number}, which is with the teller (Pending).",
            end($history)['note'],
        );
    }

    public function test_use_previous_acic_is_only_for_a_replacement_of_a_spoiled_cheque(): void
    {
        $admin = $this->admin();
        $plain = $this->forSignature($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$plain->id}/use-previous-acic")
            ->assertStatus(422)->assertJsonValidationErrors('cheque');
        $this->getJson('/api/v1/cheques?search='.$plain->cheque_number)->assertJsonPath('data.0.previous_acic', null);

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$plain->id}/use-previous-acic")->assertForbidden();
    }

    public function test_a_stale_cheque_can_be_replaced_through_the_endpoint(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();
        $this->travelTo(now()->addDays(200));

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/replace")
            ->assertCreated()
            ->assertJsonPath('data.replaces.cheque_number', $cheque->cheque_number);
        $this->assertSame(ChequeStatus::Replaced, $cheque->fresh()->status);
    }

    public function test_spoil_requires_a_reason(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertSame(ChequeStatus::Approved, $cheque->fresh()->status);
    }

    public function test_spoil_is_refused_whole_when_the_previewed_number_was_taken(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);
        $previewed = app(ChequeService::class)->nextAvailable()->cheque_number;

        // Someone else uses that number first.
        $this->registered();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'Torn.', 'replacement_number' => $previewed])
            ->assertUnprocessable()
            ->assertJsonPath('errors.replacement_number.0', "Cheque number {$previewed} is already used. Please refresh and try again.");

        // Nothing happened: not spoiled, no replacement.
        $this->assertSame(ChequeStatus::Approved, $cheque->fresh()->status);
        $this->assertSame(0, Cheque::whereNotNull('replaces_id')->count());
    }

    public function test_spoil_is_refused_whole_when_no_number_is_left(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);
        while (($n = app(ChequeService::class)->nextAvailable()) !== null) {
            app(ChequeService::class)->useNext($admin, $n->cheque_number, ['payee_name' => 'X', 'amount' => 1, 'cheque_date' => '2026-09-20']);
        }

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'Torn.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['replacement_number']);
        $this->assertSame(ChequeStatus::Approved, $cheque->fresh()->status);
    }

    public function test_a_spoiled_cheque_never_goes_stale_or_gets_the_expiry_alert(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'Torn.'])->assertOk();

        // Long past 90 days from its cheque date.
        $this->travelTo(now()->addDays(200));
        $this->artisan('cheques:sweep-validity')->assertSuccessful();

        $spoiled = $cheque->fresh();
        $this->assertSame(ChequeStatus::Spoiled, $spoiled->status);
        $this->assertSame(ChequeStatus::Spoiled, $spoiled->effectiveStatus());
        $this->assertNull($spoiled->expiry_alert_sent_at);
        $this->assertNull($spoiled->stale_at);
    }

    // ------------------------------------------------------------------ roles

    public function test_only_admins_take_the_admin_steps(): void
    {
        $staff = $this->staff();
        $cheque = $this->registered($staff);

        foreach ([$staff, $this->teller()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/cheques/{$cheque->id}/cancel", ['reason' => 'No.'])->assertForbidden();
            $this->postJson("/api/v1/cheques/{$cheque->id}/spoil", ['reason' => 'No.'])->assertForbidden();
        }
    }

    public function test_only_tellers_accept_complete_or_return(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        [, $acic] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);

        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")->assertForbidden();
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertForbidden();
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])->assertForbidden();
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'No.'])->assertForbidden();
    }

    // ------------------------------------------------------------------ history

    public function test_every_step_is_written_to_the_status_history(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheque, $acic] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        app(AcicTellerService::class)->accept($teller, $acic);
        app(AcicTellerService::class)->forward($teller, $acic->fresh(), 'land_bank');
        app(AcicTellerService::class)->complete($teller, $acic->fresh());

        Sanctum::actingAs($admin);

        $history = $this->getJson("/api/v1/cheques/{$cheque->id}/status-history")->assertOk()->json('data');

        $this->assertSame(
            ['used', 'draft_printed', 'draft_approved', 'final_printed', 'assigned', 'forwarded_to_teller', 'accepted_by_teller', 'forwarded_to_land_bank', 'completed'],
            array_column($history, 'action'),
        );

        $last = end($history);
        $this->assertSame('completed', $last['action']);
        $this->assertSame('Forwarded to LBP', $last['from_status_label']);
        $this->assertSame('Completed', $last['to_status_label']);
        $this->assertSame($teller->name, $last['user']['name']);
        $this->assertSame($acic->acic_number, $last['acic_number']);
    }
}

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
 * and the three ways out of it — RTS, Cancel and Void.
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
            'payee_name' => 'Acme Co', 'amount' => 1500.50, 'cheque_date' => '2026-09-20',
        ]);
    }

    /** Carried through routing and receipt, so it is waiting for an ACIC. */
    private function forAcic(?User $admin = null): Cheque
    {
        $admin ??= $this->admin();
        $flow = app(ChequeFlowService::class);
        $cheque = $this->registered();

        $cheque = $flow->routeForSignature($admin, $cheque, [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-21',
        ]);

        return $flow->markAsReceived($admin, $cheque, ['date_received' => '2026-09-22']);
    }

    /** On an ACIC, ready for either branch. @return array{Cheque, Acic} */
    private function onAcic(?User $admin = null): array
    {
        $admin ??= $this->admin();
        $cheque = $this->forAcic($admin);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$cheque->id]);

        return [$cheque->fresh(), $acic->fresh()];
    }

    // ------------------------------------------------------------------ the order

    public function test_the_flow_runs_in_order(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();
        $this->assertSame(ChequeStatus::Registered, $cheque->status);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'The Treasurer',
            'forward_unit_name' => 'Accounting',
            'date_forwarded' => '2026-09-21',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_signature')
            ->assertJsonPath('data.routing.forward_to_name', 'The Treasurer');

        // Receipt carries the cheque straight on to For ACIC.
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive", [
            'date_received' => '2026-09-22',
            'from_unit_name' => 'Accounting',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_acic')
            ->assertJsonPath('data.receipt.from_unit_name', 'Accounting');

        // The history names every step, including the pass-through.
        $steps = $cheque->statusHistory()->orderBy('id')->pluck('action')->all();
        $this->assertSame(['routed', 'received', 'ready_for_acic'], $steps);
    }

    /** No skipping, and no walking backwards. */
    public function test_out_of_order_moves_are_blocked(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();

        Sanctum::actingAs($admin);

        // Registered → Received skips the signature.
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive", ['date_received' => '2026-09-22'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque' => 'cannot be moved']);

        // Registered → Released skips everything.
        $this->postJson("/api/v1/cheques/{$cheque->id}/release", [
            'received_by_name' => 'Juan dela Cruz', 'date_received' => '2026-09-22',
        ])->assertStatus(422);

        $this->assertSame(ChequeStatus::Registered, $cheque->fresh()->status);
    }

    /** A page that has gone stale cannot act on what it was showing. */
    public function test_a_step_taken_from_a_stale_page_is_refused(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();
        app(ChequeFlowService::class)->routeForSignature($admin, $cheque, [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-21',
        ]);

        Sanctum::actingAs($admin);

        // The page still believes the cheque is Registered.
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive", [
            'date_received' => '2026-09-22',
            'expected_status' => 'registered',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque' => ChequeFlowService::CONFLICT]);
    }

    public function test_dates_cannot_be_in_the_future_or_before_the_step_before(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'The Treasurer',
            'date_forwarded' => Validity::today()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['date_forwarded' => 'cannot be in the future']);

        // Before the cheque was even written.
        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['date_forwarded' => 'cannot be earlier than the cheque date']);

        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-21',
        ])->assertOk();

        // Received before it was forwarded.
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive", ['date_received' => '2026-09-20'])
            ->assertStatus(422)->assertJsonValidationErrors(['date_received' => 'cannot be earlier than the date forwarded']);
    }

    // ------------------------------------------------------------------ the ACIC

    /** "Assign Cheque to ACIC" offers cheques that are For ACIC, and nothing else. */
    public function test_only_for_acic_cheques_can_be_assigned(): void
    {
        $admin = $this->admin();
        $ready = $this->forAcic($admin);
        $registered = $this->registered();

        Sanctum::actingAs($admin);

        $linkable = $this->getJson('/api/v1/acics/linkable-cheques')->assertOk()->json('data');
        $this->assertSame([$ready->cheque_number], array_column($linkable, 'cheque_number'));

        $acic = app(AcicService::class)->create($admin);

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$registered->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque_ids' => 'Only cheques that are For ACIC']);

        $this->postJson("/api/v1/acics/{$acic->id}/cheques", ['cheque_ids' => [$ready->id]])->assertOk();
        $this->assertSame(ChequeStatus::Approved, $ready->fresh()->status);
    }

    /** Several cheques can share one ACIC number. */
    public function test_several_cheques_share_one_acic(): void
    {
        $admin = $this->admin();
        $a = $this->forAcic($admin);
        $b = $this->forAcic($admin);
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
        $this->postJson("/api/v1/cheques/{$cheque->id}/void", ['reason' => 'Changed our minds.'])
            ->assertStatus(422);
    }

    public function test_release_is_blocked_without_an_acic(): void
    {
        $admin = $this->admin();
        $cheque = $this->forAcic($admin);   // signed and back, but on no ACIC

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/release", [
            'received_by_name' => 'Juan dela Cruz', 'date_received' => '2026-09-23',
        ])->assertStatus(422);

        $this->assertSame(ChequeStatus::ForAcic, $cheque->fresh()->status);
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
        $a = $this->forAcic($admin);
        $b = $this->forAcic($admin);
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
        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", [
            'forwarded_at' => now()->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'),
        ])->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can act on it']);

        Sanctum::actingAs($mine);
        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", [
            'forwarded_at' => now()->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'),
        ])->assertOk();

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

    /** RTS sends the cheque back to the start, with a reason. */
    public function test_rts_loops_back_to_registered(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();
        app(ChequeFlowService::class)->routeForSignature($admin, $cheque, [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-21',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/rts", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/cheques/{$cheque->id}/rts", ['reason' => 'Wrong payee on the face.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.exception_reason', 'Wrong payee on the face.');

        $cheque->refresh();
        $this->assertNull($cheque->date_forwarded, 'the routing is cleared for the next trip');
        $this->assertNotNull($cheque->rts_at);

        // And it can go round again.
        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-22',
        ])->assertOk()->assertJsonPath('data.status', 'out_for_signature');
    }

    public function test_rts_is_refused_once_the_cheque_is_on_an_acic(): void
    {
        $admin = $this->admin();
        [$cheque] = $this->onAcic($admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/rts", ['reason' => 'Too late.'])->assertStatus(422);
        $this->assertSame(ChequeStatus::Approved, $cheque->fresh()->status);
    }

    public function test_cancel_is_allowed_only_before_an_acic(): void
    {
        $admin = $this->admin();
        $cheque = $this->registered();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/cheques/{$cheque->id}/cancel", ['reason' => 'Raised in error.'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Final.
        $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
            'forward_to_name' => 'X', 'date_forwarded' => '2026-09-22',
        ])->assertStatus(422);

        // And a cheque already on an ACIC is voided instead, not cancelled.
        [$onAcic] = $this->onAcic($admin);
        $this->postJson("/api/v1/cheques/{$onAcic->id}/cancel", ['reason' => 'Nope.'])->assertStatus(422);
    }

    public function test_void_is_allowed_on_an_acic_but_not_once_a_teller_has_it(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheque, $acic] = $this->onAcic($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$cheque->id}/void", ['reason' => 'Misprinted.'])
            ->assertOk()->assertJsonPath('data.status', 'voided');

        // The number is used up for good.
        $this->assertNotSame($cheque->cheque_number, app(ChequeService::class)->nextAvailable()->cheque_number);

        // Once the ACIC is with a teller, voiding is refused.
        [$second, $acic2] = $this->onAcic($admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic2);
        app(AcicTellerService::class)->accept($teller, $acic2);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cheques/{$second->id}/void", ['reason' => 'Too late.'])->assertStatus(422);
        $this->assertSame(ChequeStatus::AcceptedByTeller, $second->fresh()->status);
    }

    // ------------------------------------------------------------------ roles

    public function test_only_admins_take_the_admin_steps(): void
    {
        $staff = $this->staff();
        $cheque = $this->registered($staff);

        foreach ([$staff, $this->teller()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/cheques/{$cheque->id}/route", [
                'forward_to_name' => 'X', 'date_forwarded' => '2026-09-21',
            ])->assertForbidden();
            $this->postJson("/api/v1/cheques/{$cheque->id}/cancel", ['reason' => 'No.'])->assertForbidden();
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
        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", [
            'forwarded_at' => now()->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'),
        ])->assertForbidden();
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
        // The trip to the bank happens after the ACIC was accepted.
        $this->travelTo(now()->addHours(2));
        app(AcicTellerService::class)->confirmAndComplete($teller, $acic, [
            'forwarded_at' => now()->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'),
        ]);

        Sanctum::actingAs($admin);

        $history = $this->getJson("/api/v1/cheques/{$cheque->id}/status-history")->assertOk()->json('data');

        $this->assertSame(
            ['routed', 'received', 'ready_for_acic', 'assigned', 'forwarded_to_teller', 'accepted_by_teller', 'completed'],
            array_column($history, 'action'),
        );

        $last = end($history);
        $this->assertSame('completed', $last['action']);
        $this->assertSame('Accepted by Teller', $last['from_status_label']);
        $this->assertSame('Completed', $last['to_status_label']);
        $this->assertSame($teller->name, $last['user']['name']);
        $this->assertSame($acic->acic_number, $last['acic_number']);
    }
}

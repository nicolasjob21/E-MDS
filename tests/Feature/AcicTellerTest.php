<?php

namespace Tests\Feature;

use App\Enums\AcicTellerStatus;
use App\Enums\AcicType;
use App\Enums\ChequeStatus;
use App\Enums\LddapStatus;
use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\Creditor;
use App\Models\Lddap;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\AcicService;
use App\Services\AcicTellerService;
use App\Services\ChequeFlowService;
use App\Services\ChequeService;
use App\Services\LddapService;
use App\Support\Validity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The teller's half of an ACIC's life — the same workflow whether it carries cheques or
 * LDDAPs:
 *
 *   Pending → Accepted by Teller → Forwarded to Land Bank → Completed (credited)
 *                                          └─▶ Returned by Bank ─┬─▶ lodged again
 *                                                                └─▶ back to the admin
 */
class AcicTellerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcicSeries(1, 30);
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

    /** An ACIC carrying `$count` cheques, ready to forward. @return array{Acic, User} */
    private function chequeAcic(int $count = 1, ?User $admin = null): array
    {
        $admin ??= $this->admin();
        $flow = app(ChequeFlowService::class);
        $service = app(ChequeService::class);

        if (Cheque::query()->doesntExist()) {
            $service->addRange($admin, 100, 149);
        }

        $ids = [];

        foreach (range(1, $count) as $i) {
            $c = $service->useNext($admin, $service->nextAvailable()->cheque_number, [
                'payee_name' => "Payee {$i}", 'amount' => 1000 * $i, 'cheque_date' => '2026-09-20',
            ]);
            $ids[] = $this->readyForAcic($c, $admin)->id;
        }

        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, $ids);

        return [$acic->fresh(), $admin];
    }

    /** An ACIC carrying `$count` LDDAPs, ready to forward. @return array{Acic, User} */
    private function lddapAcic(int $count = 1, ?User $admin = null): array
    {
        $admin ??= $this->admin();
        $staff = $this->staff();
        $lddaps = app(LddapService::class);
        $lddaps->addRange($admin, 1, 50);

        $unit = 'CG-8 Comptrollership';
        $payee = Creditor::firstOrCreate(['name' => 'ACME SUPPLIES INC.'], ['account_no' => '0028901011']);

        $ids = [];

        foreach (range(1, $count) as $i) {
            $l = $lddaps->register($staff, [
                'lddap_no' => sprintf('26-09-%05d', $i), 'nca_no' => sprintf('%07d', $i), 'obr_no' => "OBR-{$i}",
                'dv_no' => sprintf('10-00-%05d', $i), 'nature_of_payment' => NatureOfPayment::CommercialClaims->value,
                'unit_name' => $unit, 'check_date' => '2026-09-21', 'payee_type' => 'creditor', 'payee_ref' => $payee->id,
                'gross_amount' => 1000 * $i,
            ]);
            // Added straight to For Signature — ready for an ACIC.
            $ids[] = $l->id;
        }

        $acic = app(AcicService::class)->create($admin);
        $lddaps->assignToAcic($admin, $acic, $ids);

        return [$acic->fresh(), $admin];
    }

    /** One cheque carried as far as For ACIC, ready to be assigned. */
    private function readyCheque(User $admin): Cheque
    {
        $flow = app(ChequeFlowService::class);
        $service = app(ChequeService::class);

        if (Cheque::query()->doesntExist()) {
            $service->addRange($admin, 100, 149);
        }

        $c = $service->useNext($admin, $service->nextAvailable()->cheque_number, [
            'payee_name' => 'Spare', 'amount' => 500, 'cheque_date' => '2026-09-20',
        ]);

        return $this->readyForAcic($c, $admin);
    }

    /** Carry an ACIC as far as a teller holding it. */
    private function accepted(Acic $acic, User $admin, ?User $teller = null): User
    {
        $teller ??= $this->teller();
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        app(AcicTellerService::class)->accept($teller, $acic->fresh());

        // The trip to the bank happens after the ACIC was accepted, so move the clock on and
        // let the tests date their steps within that window.
        $this->travelTo(now()->addHours(6));

        return $teller;
    }

    /**
     * A wall-clock time in Manila, as the browser's datetime-local input sends it — which is
     * how the service reads an incoming date and time.
     */
    private function manila(int $hoursAgo = 0): string
    {
        return now()->setTimezone(Validity::TZ)->subHours($hoursAgo)->format('Y-m-d H:i:s');
    }

    /** Carried as far as forwarded to Land Bank (or the payee) by the accepting teller. */
    private function forwarded(Acic $acic, User $admin, ?User $teller = null, string $to = 'land_bank'): User
    {
        $teller = $this->accepted($acic, $admin, $teller);
        app(AcicTellerService::class)->forward($teller, $acic->fresh(), $to);

        return $teller;
    }

    /** Carried all the way to Completed, through Land Bank. */
    private function completed(Acic $acic, User $admin, ?User $teller = null): User
    {
        $teller = $this->forwarded($acic, $admin, $teller);
        app(AcicTellerService::class)->complete($teller, $acic->fresh());

        return $teller;
    }

    /** "cheque:ID" / "lddap:ID" for every record on the ACIC, mapped to one value. */
    private function perRecord(Acic $acic, mixed $value): array
    {
        return $acic->fresh()->records()->mapWithKeys(fn ($r) => [($r instanceof Cheque ? 'cheque:' : 'lddap:').$r->id => $value])->all();
    }

    /** The statuses of every record on the ACIC, of whichever kind. */
    private function recordStatuses(Acic $acic): array
    {
        return $acic->fresh()->records()->map(fn ($r) => $r->status->value)->unique()->values()->all();
    }

    // ------------------------------------------------------------------ 1. forwarding

    public function test_every_teller_is_notified_when_an_acic_is_forwarded(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(2);
        $tellers = [$this->teller('One'), $this->teller('Two'), $this->teller('Three')];
        $staff = $this->staff();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", ['note' => 'For deposit.'])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'pending');

        foreach ($tellers as $teller) {
            Notification::assertSentTo($teller, ActivityNotification::class, function (ActivityNotification $n) use ($acic) {
                // The notice carries the ACIC, its type, the count, the total and who sent it.
                return str_contains($n->message, "ACIC #{$acic->acic_number}")
                    && str_contains($n->message, 'Cheque')
                    && str_contains($n->message, '2 record(s)')
                    && str_contains($n->message, '3,000.00')
                    && str_contains($n->message, 'Ada Admin');
            });
        }

        Notification::assertNotSentTo($staff, ActivityNotification::class);
        $this->assertSame([ChequeStatus::ForwardedToTeller->value], $this->recordStatuses($acic));
    }

    public function test_only_an_admin_forwards_an_acic_to_the_tellers(): void
    {
        [$acic] = $this->chequeAcic();

        foreach ([$this->staff(), $this->teller()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ 2. accepting

    public function test_the_first_teller_to_accept_wins_and_the_second_is_told_who_has_it(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic();
        $first = $this->teller('First Teller');
        $second = $this->teller('Second Teller');
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);

        Sanctum::actingAs($first);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'accepted_by_teller')
            ->assertJsonPath('data.accepted_by.name', 'First Teller');

        Sanctum::actingAs($second);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => 'Already accepted by First Teller.']);

        $acic->refresh();
        $this->assertSame($first->id, $acic->accepted_by);
        $this->assertNotNull($acic->accepted_at);
        $this->assertSame([ChequeStatus::AcceptedByTeller->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);
    }

    public function test_only_the_accepting_teller_can_act_from_then_on(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $mine = $this->teller('Mine');
        $other = $this->teller('Other');
        $this->accepted($acic, $admin, $mine);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it or take action on it']);
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Not mine.'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can act on it']);

        // Not even an admin forwards or acts for the teller.
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it or take action on it']);

        app(AcicTellerService::class)->forward($mine, $acic->fresh(), 'land_bank');
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it or take action on it']);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", ['reason' => 'No.', 'outcomes' => $this->perRecord($acic, 'returned')])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it or take action on it']);
    }

    // --------------------------------------- 3. forwarded to Land Bank or to the payee

    public function test_the_accepting_teller_forwards_it_to_land_bank_or_the_payee(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->accepted($acic, $admin);

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", [])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'the_moon'])->assertStatus(422)->assertJsonValidationErrors('to');

        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'forwarded_to_land_bank')
            ->assertJsonPath('data.teller_status_label', 'Forwarded to LBP')
            ->assertJsonPath('data.teller_forwarded_to', 'land_bank')
            ->assertJsonPath('data.teller_forwarded_by.name', $teller->name)
            ->assertJsonPath('data.can_teller_forward', false)
            ->assertJsonPath('data.can_teller_act', true);

        $acic->refresh();
        $this->assertNotNull($acic->teller_forwarded_at);
        $this->assertSame([ChequeStatus::ForwardedToLandBank->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);

        // Out once, it can't be forwarded again until the Action — whatever the page believed.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => "ACIC #{$acic->acic_number} has already been forwarded to LBP."]);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'payee'])->assertStatus(422);

        // Forwarded to Bank is the server's time at confirmation, not anything the client sent.
        $this->assertTrue($acic->fresh()->forwarded_to_land_bank_at->between(now()->subMinute(), now()));
        $this->assertSame(1, $acic->history()->where('action', 'forwarded_to_land_bank')->count());
    }

    public function test_the_forward_buttons_offered_follow_the_kind_of_acic(): void
    {
        [$cheques, $admin] = $this->chequeAcic(1);
        [$lddaps] = $this->lddapAcic(1, $admin);
        $teller = $this->accepted($cheques, $admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $lddaps);
        app(AcicTellerService::class)->accept($teller, $lddaps->fresh());

        Sanctum::actingAs($teller);
        $queue = collect($this->getJson('/api/v1/acics/teller-queue')->json('data.accepted'))->keyBy('acic_number');
        $this->assertSame(['land_bank'], $queue[$cheques->acic_number]['teller_forward_options']);
        $this->assertTrue($queue[$cheques->acic_number]['can_forward_to_payee']);
        $this->assertFalse($queue[$lddaps->acic_number]['can_forward_to_payee']);
        $this->assertSame(['forwarded' => 0, 'total' => 1], $queue[$cheques->acic_number]['payee_progress']);
        $this->assertSame(['land_bank'], $queue[$lddaps->acic_number]['teller_forward_options']);
        $this->assertSame('Accepted', $queue[$cheques->acic_number]['teller_status_label']);

        // The ACIC page offers the same buttons, from its own list.
        $rows = collect($this->getJson('/api/v1/acics')->json('data'))->keyBy('acic_number');
        $this->assertSame(['land_bank'], $rows[$cheques->acic_number]['teller_forward_options']);
        $this->assertTrue($rows[$cheques->acic_number]['can_forward_to_payee']);
        $this->assertSame(['land_bank'], $rows[$lddaps->acic_number]['teller_forward_options']);
        $this->assertFalse($rows[$cheques->acic_number]['can_teller_act']);

        // Another teller is offered nothing.
        Sanctum::actingAs($this->teller('Other'));
        $this->assertSame([], $this->getJson("/api/v1/acics/{$cheques->id}")->json('data.teller_forward_options'));
    }

    public function test_the_history_is_readable_only_where_the_acic_is(): void
    {
        [$acic, $admin] = $this->chequeAcic(1);
        Sanctum::actingAs($this->teller());
        // Still with the admin: a teller can't see it, nor its history.
        $this->getJson("/api/v1/acics/{$acic->id}/history")->assertNotFound();

        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        $this->getJson("/api/v1/acics/{$acic->id}/history")
            ->assertOk()->assertJsonPath('data.0.action', 'forwarded_to_teller')->assertJsonPath('data.0.user.name', $admin->name);
    }

    public function test_completed_through_land_bank_closes_it_and_every_check(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->forwarded($acic, $admin);

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.teller_action_by.name', $teller->name);

        $acic->refresh();
        $this->assertNotNull($acic->teller_action_at);
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));
        // Completed is final.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertStatus(422);
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Too late.'])->assertStatus(422);
    }

    /** The batch form: who received the cheques, when, and their unit. */
    private function receipt(array $chequeIds, array $overrides = []): array
    {
        return $overrides + [
            'cheque_ids' => $chequeIds,
            'received_by' => 'Juan Dela Cruz',
            'date_received' => Validity::today()->toDateString(),
            'unit' => 'CG-8 Comptrollership',
        ];
    }

    public function test_cheques_go_to_their_payees_one_several_or_all(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(3);
        $teller = $this->accepted($acic, $admin);
        [$a, $b, $c] = $acic->cheques()->orderBy('cheque_number')->get()->all();
        $today = Validity::today()->toDateString();

        Sanctum::actingAs($teller);
        // One first: the ACIC stays Accepted and shows its progress.
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$a->id]))
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'accepted_by_teller')
            ->assertJsonPath('data.payee_progress', ['forwarded' => 1, 'total' => 3])
            ->assertJsonPath('data.can_forward_to_payee', true)
            // Now it can neither go to LBP nor back to the admin.
            ->assertJsonPath('data.teller_forward_options', [])
            ->assertJsonPath('data.can_return_to_admin', false);

        $a->refresh();
        $this->assertSame(ChequeStatus::ForwardedToPayee, $a->status);
        $this->assertSame('Juan Dela Cruz', $a->received_by_name);
        $this->assertSame($today, $a->date_received->toDateString());
        $this->assertSame('CG-8 Comptrollership', $a->payee_unit_name);
        $this->assertSame($teller->id, $a->released_by);
        $this->assertNotNull($a->released_at);

        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])
            ->assertStatus(422)->assertJsonValidationErrors(['to' => 'already been forwarded to their payees']);
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Changed my mind.'])
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'cannot be returned to the admin']);
        // Once forwarded, a cheque can't go again.
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$a->id]))
            ->assertStatus(422)->assertJsonValidationErrors('cheque_ids');

        // The rest together: every cheque is out, so the ACIC is Forwarded to Payee — and gets the Action.
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$b->id, $c->id], ['received_by' => 'Maria Santos']))
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'forwarded_to_payee')
            ->assertJsonPath('data.payee_progress', ['forwarded' => 3, 'total' => 3])
            ->assertJsonPath('data.can_forward_to_payee', false)
            ->assertJsonPath('data.can_teller_act', true);
        $this->assertSame('Maria Santos', $c->fresh()->received_by_name);
        Notification::assertSentTo($admin, ActivityNotification::class);

        // Each cheque's timeline and the ACIC's history say who received it.
        $timeline = $this->getJson("/api/v1/cheques/{$b->id}/status-history")->json('data');
        $this->assertSame("Received by Maria Santos (CG-8 Comptrollership) on {$today}.", end($timeline)['note']);
        $this->getJson('/api/v1/cheques?search='.$b->cheque_number)
            ->assertJsonPath('data.0.payee_receipt.received_by', 'Maria Santos')
            ->assertJsonPath('data.0.payee_receipt.unit', 'CG-8 Comptrollership');
        $this->assertSame(
            ['forwarded_to_teller', 'accepted', 'cheques_forwarded_to_payee', 'cheques_forwarded_to_payee', 'forwarded_to_payee'],
            array_column($this->getJson("/api/v1/acics/{$acic->id}/history")->json('data'), 'action'),
        );

        // Completed asks for nothing more.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])->assertOk()->assertJsonPath('data.teller_status', 'completed');
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));
    }

    public function test_forward_to_payee_has_its_rules(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        [$lddapAcic] = $this->lddapAcic(1, $admin);
        $teller = $this->accepted($acic, $admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $lddapAcic);
        app(AcicTellerService::class)->accept($teller, $lddapAcic->fresh());
        [$fresh, $stale] = $acic->cheques()->orderBy('cheque_number')->get()->all();
        // One cheque ran out of time: shown, but never forwardable.
        $stale->forceFill(['validity_until' => Validity::today()->subDay()->toDateString()])->saveQuietly();

        Sanctum::actingAs($teller);
        // The whole-ACIC "to payee" is gone; LDDAP ACICs never go to a payee.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'payee'])->assertStatus(422);
        $lddapId = $lddapAcic->lddaps()->value('id');
        $this->postJson("/api/v1/acics/{$lddapAcic->id}/forward-to-payee", $this->receipt([$lddapId]))
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'forwarded to LBP only']);

        // Every field is required; no future date; the unit comes from the list.
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", [])
            ->assertStatus(422)->assertJsonValidationErrors(['cheque_ids', 'received_by', 'date_received', 'unit']);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$fresh->id], ['date_received' => Validity::today()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('date_received');
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$fresh->id], ['unit' => 'Nowhere']))
            ->assertStatus(422)->assertJsonValidationErrors('unit');

        // A stale cheque is refused, and doesn't count toward the ACIC going out.
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$stale->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['cheque_ids' => 'Stale cheques cannot be forwarded']);
        $this->getJson("/api/v1/acics/{$acic->id}")->assertJsonPath('data.payee_progress', ['forwarded' => 0, 'total' => 1]);

        // Only the teller who accepted it.
        Sanctum::actingAs($this->teller('Other'));
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$fresh->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['acic' => 'only they can forward it']);

        // The only fresh cheque out → the ACIC is Forwarded to Payee; the stale one stays behind.
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-payee", $this->receipt([$fresh->id]))
            ->assertOk()->assertJsonPath('data.teller_status', 'forwarded_to_payee');
        $this->assertSame(ChequeStatus::AcceptedByTeller, $stale->fresh()->status);
    }

    // ----------------------------------------------------------------- 4. the Action: RTS

    public function test_rts_needs_a_reason_and_a_status_for_each_check(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(3);
        $teller = $this->forwarded($acic, $admin);
        [$a, $b, $c] = $acic->cheques()->orderBy('cheque_number')->get()->all();

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", ['outcomes' => $this->perRecord($acic, 'returned')])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", ['reason' => 'Signature missing.', 'outcomes' => ["cheque:{$a->id}" => 'returned']])
            ->assertStatus(422)->assertJsonValidationErrors("outcomes.cheque:{$b->id}");

        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", [
            'reason' => 'Signature missing on one; one past its date.',
            'outcomes' => ["cheque:{$a->id}" => 'completed', "cheque:{$b->id}" => 'returned', "cheque:{$c->id}" => 'stale'],
        ])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'rts')
            ->assertJsonPath('data.rts_reason', 'Signature missing on one; one past its date.')
            ->assertJsonPath('data.can_teller_forward', true);

        $this->assertSame(ChequeStatus::Completed, $a->fresh()->status);
        $this->assertSame(ChequeStatus::Returned, $b->fresh()->status);
        $this->assertSame(ChequeStatus::Stale, $c->fresh()->status);
        $this->assertSame('returned', $b->fresh()->rts_status);
        Notification::assertSentTo($admin, ActivityNotification::class);

        // Forwarded again, only the Returned one goes out.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertOk();
        $this->assertSame(ChequeStatus::ForwardedToLandBank, $b->fresh()->status);
        $this->assertSame(ChequeStatus::Completed, $a->fresh()->status);
        $this->assertNull($b->fresh()->rts_status);

        // Every step is in the ACIC's history, the RTS with its reason and each check's status.
        $history = $this->getJson("/api/v1/acics/{$acic->id}/history")->assertOk()->json('data');
        $this->assertSame(
            ['forwarded_to_teller', 'accepted', 'forwarded_to_land_bank', 'rts', 'forwarded_to_land_bank'],
            array_column($history, 'action'),
        );
        $this->assertSame('returned', $history[3]['details']['outcomes']["cheque:{$b->id}"]);
    }

    public function test_after_an_rts_the_teller_may_hand_it_back_to_the_admin(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->forwarded($acic, $admin);
        [$a, $b] = $acic->cheques()->orderBy('cheque_number')->get()->all();
        app(AcicTellerService::class)->rts($teller, $acic->fresh(), 'Wrong payee.', ["cheque:{$a->id}" => 'cancelled', "cheque:{$b->id}" => 'returned']);

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Needs a new ACIC.'])->assertOk();

        // The Returned check goes back to the admin; the cancelled one stays cancelled.
        $this->assertSame(ChequeStatus::Approved, $b->fresh()->status);
        $this->assertSame(ChequeStatus::Cancelled, $a->fresh()->status);
        $this->assertSame('Wrong payee.', $a->fresh()->exception_reason);
    }

    // ------------------------------------------------------------- 6. back to the admin

    public function test_the_teller_can_hand_it_back_and_the_admin_can_send_it_out_again(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);
        $second = $this->teller('Second Teller');

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Wrong ACIC number.'])
            ->assertOk()
            ->assertJsonPath('data.teller_status', null);

        $acic->refresh();
        $this->assertNull($acic->accepted_by, 'the claim is released');
        // The records go back to where the admin picks them up.
        $this->assertSame([ChequeStatus::Approved->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);

        // Forwarding again starts a fresh cycle, and every teller hears about it.
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])->assertOk();
        Notification::assertSentTo($second, ActivityNotification::class);
        $this->assertSame(AcicTellerStatus::Pending, $acic->fresh()->teller_status);
    }

    // ----------------------------------------------------------- a stale page

    public function test_a_step_taken_from_a_stale_page_is_refused(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->completed($acic, $admin);

        Sanctum::actingAs($teller);

        // The page still believes the ACIC is merely accepted.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", [
            'to' => 'land_bank',
            'expected_status' => 'accepted_by_teller',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => AcicTellerService::CONFLICT]);
    }

    // --------------------------------------------------------- the same for LDDAPs

    /** An LDDAP ACIC travels exactly as a cheque ACIC does. */
    public function test_the_flow_works_the_same_for_an_lddap_acic(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->lddapAcic(2);
        $teller = $this->teller();

        $this->assertSame(AcicType::Lddap, $acic->type);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])->assertOk();
        $this->assertSame([LddapStatus::ForwardedToTeller->value], $this->recordStatuses($acic));
        Notification::assertSentTo($teller, ActivityNotification::class);

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")->assertOk();
        $this->assertSame([LddapStatus::AcceptedByTeller->value], $this->recordStatuses($acic));
        $this->travelTo(now()->addHours(6));

        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertOk();
        $this->assertSame([LddapStatus::ForwardedToLandBank->value], $this->recordStatuses($acic));

        // An LDDAP can't be marked Stale.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", ['reason' => 'Account closed.', 'outcomes' => $this->perRecord($acic, 'stale')])
            ->assertStatus(422);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-rts", ['reason' => 'Account closed.', 'outcomes' => $this->perRecord($acic, 'returned')])
            ->assertOk();
        $this->assertSame([LddapStatus::Returned->value], $this->recordStatuses($acic));

        // Put right and out again — to LBP only: an LDDAP ACIC never goes to the payee.
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'payee'])
            ->assertStatus(422)->assertJsonValidationErrors(['to' => 'forwarded to LBP only']);
        $this->postJson("/api/v1/acics/{$acic->id}/teller-forward", ['to' => 'land_bank'])->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/teller-complete", [])->assertOk();
        $this->assertSame([LddapStatus::Completed->value], $this->recordStatuses($acic));

        // Each record carries the whole trip in its own trail.
        $steps = Lddap::query()->where('acic_id', $acic->id)->first()
            ->routingHistory()->orderBy('id')->pluck('to_status')->all();
        $this->assertContains(
            LddapStatus::Completed->value,
            array_map(fn ($s) => $s instanceof LddapStatus ? $s->value : $s, $steps),
        );
    }

    public function test_the_lddap_timeline_shows_the_teller_steps(): void
    {
        [$acic, $admin] = $this->lddapAcic(1);
        $teller = $this->teller();
        $lddap = $acic->lddaps()->sole();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-teller", [])->assertOk();
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/accept")->assertOk();
        $this->postJson("/api/v1/acics/{$acic->id}/return-to-admin", ['reason' => 'Wrong bank branch.'])->assertOk();

        $timeline = collect($this->getJson("/api/v1/lddaps/{$lddap->id}/routing-history")->assertOk()->json('data'));

        $this->assertSame(
            ['registered', 'assigned', 'forwarded_to_teller', 'accepted_by_teller', 'returned_to_admin'],
            $timeline->pluck('action')->all(),
        );
        $forwarded = $timeline->firstWhere('action', 'forwarded_to_teller');
        $this->assertSame('Forwarded to Teller', $forwarded['action_label']);
        $this->assertSame("ACIC #{$acic->acic_number}.", $forwarded['note']);
        $this->assertSame($admin->name, $forwarded['user']['name']);
        $this->assertSame("ACIC #{$acic->acic_number} — Wrong bank branch.", $timeline->last()['note']);
    }

    public function test_an_acic_carries_one_kind_of_record_only(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $this->assertSame(AcicType::Cheque, $acic->type);

        // The LDDAP side refuses to join a cheque ACIC.
        [$lddapAcic] = $this->lddapAcic(1, $admin);
        $spare = Lddap::query()->where('acic_id', $lddapAcic->id)->firstOrFail();
        $spare->update(['acic_id' => null]);

        $this->expectExceptionMessage('cannot also carry LDDAP records');
        app(LddapService::class)->assignToAcic($admin, $acic, [$spare->id]);
    }

    // ------------------------------------------------------------------ the dashboard

    public function test_the_teller_dashboard_lists_every_stage(): void
    {
        $admin = $this->admin();
        $mine = $this->teller('Mine');

        [$pending] = $this->chequeAcic(1, $admin);
        [$accepted] = $this->chequeAcic(1, $admin);
        [$done] = $this->chequeAcic(1, $admin);
        [$out] = $this->chequeAcic(1, $admin);
        [$returned] = $this->chequeAcic(1, $admin);

        app(AcicTellerService::class)->forwardToTeller($admin, $pending);
        $this->accepted($accepted, $admin, $mine);
        $this->completed($done, $admin, $mine);
        $this->forwarded($out, $admin, $mine);
        $this->forwarded($returned, $admin, $mine);
        app(AcicTellerService::class)->rts($mine, $returned->fresh(), 'Endorsement missing.', $this->perRecord($returned, 'returned'));

        Sanctum::actingAs($mine);
        $queue = $this->getJson('/api/v1/acics/teller-queue')->assertOk()->json('data');

        $this->assertSame([$pending->acic_number], array_column($queue['pending'], 'acic_number'));
        $this->assertSame([$accepted->acic_number], array_column($queue['accepted'], 'acic_number'));
        $this->assertSame([$out->acic_number], array_column($queue['forwarded'], 'acic_number'));
        $this->assertSame([$returned->acic_number], array_column($queue['rts'], 'acic_number'));
        $this->assertSame([$done->acic_number], array_column($queue['completed'], 'acic_number'));
        $this->assertSame('Land Bank of the Philippines', $queue['bank_name']);

        // The rows carry what the table's columns need.
        $row = $queue['completed'][0];
        $this->assertSame('cheque', $row['type']);
        $this->assertSame(1, $row['cheque_count']);
        $this->assertSame(1, $row['total_records']);
        $this->assertSame('Ada Admin', $row['forwarded_to_teller_by']['name']);
        $this->assertNotNull($row['forwarded_to_teller_at']);
        $this->assertNotNull($row['forwarded_to_land_bank_at']);
    }

    public function test_the_dashboard_filters_by_type(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        [$cheques] = $this->chequeAcic(1, $admin);
        [$lddaps] = $this->lddapAcic(1, $admin);
        app(AcicTellerService::class)->forwardToTeller($admin, $cheques);
        app(AcicTellerService::class)->forwardToTeller($admin, $lddaps);

        Sanctum::actingAs($teller);

        $this->assertSame(
            [$lddaps->acic_number],
            array_column($this->getJson('/api/v1/acics/teller-queue?type=lddap')->json('data.pending'), 'acic_number'),
        );
        $this->assertSame(
            [$cheques->acic_number],
            array_column($this->getJson('/api/v1/acics/teller-queue?type=cheque')->json('data.pending'), 'acic_number'),
        );
    }

    // ------------------------------------------------------------ the tables' filters

    public function test_the_acic_table_searches_every_number_on_the_acic_and_filters_by_status(): void
    {
        [$cheques, $admin] = $this->chequeAcic(1);          // ACIC #1: cheque #100, approved on assignment
        [$lddaps] = $this->lddapAcic(1, $admin);             // ACIC #2: LDDAP 26-09-00001, DV 10-00-00001, Used
        $checkNo = $lddaps->lddaps()->sole()->lddapCheck->check_no;
        $find = fn (string $query) => array_column($this->getJson("/api/v1/acics?{$query}")->assertOk()->json('data'), 'acic_number');

        Sanctum::actingAs($this->staff());

        $this->assertSame([$cheques->acic_number], $find('search=100'));                   // cheque number, partly
        $this->assertSame([$lddaps->acic_number], $find('search=26-09-0000'));             // LDDAP number, partly
        $this->assertSame([$lddaps->acic_number], $find('search=10-00-00001'));            // DV number
        $this->assertContains($lddaps->acic_number, $find("search={$checkNo}"));           // LDDAP check number
        $this->assertSame([$lddaps->acic_number, $cheques->acic_number], $find('search=')); // blank: everything
        $this->assertSame([], $find('search=nothing-like-it'));

        // Status on its own, and with the search.
        $this->assertSame([$cheques->acic_number], $find('status=approved'));
        $this->assertSame([$lddaps->acic_number], $find('status=used'));
        $this->assertSame([], $find('status=used&search=100'));
        $this->getJson('/api/v1/acics?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_tellers_table_filters_only_narrow_what_the_teller_already_sees(): void
    {
        [$cheques, $admin] = $this->chequeAcic(1);
        [$lddaps] = $this->lddapAcic(1, $admin);
        $mine = $this->teller('Mine');
        $other = $this->teller('Other');
        $this->accepted($cheques, $admin, $mine);
        app(AcicTellerService::class)->forwardToTeller($admin, $lddaps);
        $queue = fn (string $query) => collect($this->getJson("/api/v1/acics/teller-queue?{$query}")->assertOk()->json('data'))
            ->only(['pending', 'accepted', 'forwarded', 'rts', 'completed'])
            ->map(fn ($rows) => array_column($rows, 'acic_number'))->filter()->all();

        Sanctum::actingAs($mine);
        $this->assertSame(['accepted' => [$cheques->acic_number]], $queue('search=100'));
        $this->assertSame(['pending' => [$lddaps->acic_number]], $queue('search=10-00-00001'));
        $this->assertSame(['pending' => [$lddaps->acic_number]], $queue('status=pending'));
        $this->assertSame([], $queue('status=pending&search=100'));

        // Another teller searching for my ACIC finds nothing: it is not theirs to see.
        Sanctum::actingAs($other);
        $this->assertSame([], $queue('search=100'));

        $this->getJson('/api/v1/acics/teller-queue?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    }
}

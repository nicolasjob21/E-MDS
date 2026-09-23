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
use App\Models\Lddap;
use App\Models\Payee;
use App\Models\Unit;
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
            $c = $flow->routeForSignature($admin, $c, ['forward_to_name' => 'Treasurer', 'date_forwarded' => '2026-09-21']);
            $ids[] = $flow->markAsReceived($admin, $c, ['date_received' => '2026-09-22'])->id;
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

        $unit = Unit::firstOrCreate(['name' => 'ACCOUNTING']);
        $payee = Payee::firstOrCreate(['name' => 'ACME SUPPLIES INC.']);
        $payee->accounts()->firstOrCreate(['account_no' => '2028-9010-11'], ['bank' => 'LBP']);

        $ids = [];

        foreach (range(1, $count) as $i) {
            $l = $lddaps->register($staff, [
                'lddap_no' => sprintf('LDDAP-%04d', $i), 'nca_no' => "NCA-{$i}", 'orb_no' => "ORB-{$i}",
                'dv_no' => "DV-{$i}", 'nature_of_payment' => NatureOfPayment::CommercialClaims->value,
                'unit_id' => $unit->id, 'check_date' => '2026-09-21', 'payee_id' => $payee->id,
                'gross_amount' => 1000 * $i,
            ]);
            $l = $lddaps->forward($staff, $l, ['forward_to' => 'Accounting', 'unit_id' => $unit->id, 'date_forwarded' => '2026-09-22']);
            $l = $lddaps->receive($staff, $l, ['unit_id' => $unit->id, 'date_received' => '2026-09-23']);
            $ids[] = $lddaps->approve($admin, $l)->id;
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
        $c = $flow->routeForSignature($admin, $c, ['forward_to_name' => 'Treasurer', 'date_forwarded' => '2026-09-21']);

        return $flow->markAsReceived($admin, $c, ['date_received' => '2026-09-22']);
    }

    /** Carry an ACIC as far as a teller holding it. */
    private function accepted(Acic $acic, User $admin, ?User $teller = null): User
    {
        $teller ??= $this->teller();
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);
        app(AcicTellerService::class)->accept($teller, $acic->fresh());

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
        foreach ([
            ['forward-to-land-bank', []],
            ['returned-by-bank', ['reason' => 'Signature missing.']],
            ['complete-teller', ['bank_confirmation_no' => 'BC-1']],
            ['return-to-admin', ['reason' => 'Not mine.']],
        ] as [$path, $payload]) {
            $this->postJson("/api/v1/acics/{$acic->id}/{$path}", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['acic' => 'only they can act on it']);
        }
    }

    // ------------------------------------------------------- 3. off to Land Bank

    public function test_forwarding_to_land_bank_records_the_transmittal_and_tells_the_admin(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-land-bank", [
            'transmittal_no' => 'TR-2026-118',
            'note' => 'Hand-carried.',
        ])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'forwarded_to_land_bank')
            ->assertJsonPath('data.transmittal_no', 'TR-2026-118');

        $acic->refresh();
        $this->assertNotNull($acic->forwarded_to_land_bank_at);
        $this->assertSame([ChequeStatus::ForwardedToLandBank->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);
    }

    public function test_the_land_bank_date_cannot_be_in_the_future_or_before_it_was_accepted(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-land-bank", [
            'forwarded_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['forwarded_at' => 'cannot be in the future']);

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-land-bank", [
            'forwarded_at' => now()->subYear()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['forwarded_at' => 'earlier than the date and time the ACIC was accepted']);

        $this->assertNull($acic->fresh()->forwarded_to_land_bank_at);
    }

    // --------------------------------------------------- 4. the bank sends it back

    public function test_a_bank_return_needs_notes_and_flags_the_records(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->accepted($acic, $admin);
        app(AcicTellerService::class)->forwardToLandBank($teller, $acic->fresh(), []);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", ['reason' => 'Endorsement missing.'])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'returned_by_bank');

        $acic->refresh();
        $this->assertSame('Endorsement missing.', $acic->bank_return_reason);
        // Defaulting to all records: both carry the flag and the note.
        $this->assertSame(2, $acic->cheques()->where('returned_by_bank', true)->count());
        $this->assertSame([ChequeStatus::ReturnedByBank->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);
    }

    public function test_only_the_records_the_bank_named_are_flagged(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->accepted($acic, $admin);
        app(AcicTellerService::class)->forwardToLandBank($teller, $acic->fresh(), []);
        $one = $acic->cheques()->orderBy('cheque_number')->first();

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", [
            'reason' => 'Wrong amount on one.',
            'cheque_ids' => [$one->id],
        ])->assertOk();

        $this->assertTrue($one->fresh()->returned_by_bank);
        $this->assertSame(1, $acic->cheques()->where('returned_by_bank', true)->count());
    }

    /** Every trip to the bank is kept; a later one never overwrites an earlier. */
    public function test_every_forward_and_return_cycle_is_kept(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);
        $svc = app(AcicTellerService::class);

        $svc->forwardToLandBank($teller, $acic->fresh(), ['transmittal_no' => 'TR-1']);
        $first = $acic->fresh()->forwarded_to_land_bank_at;

        $svc->returnedByBank($teller, $acic->fresh(), ['reason' => 'Endorsement missing.']);
        $svc->forwardToLandBank($teller, $acic->fresh(), ['transmittal_no' => 'TR-2']);

        $acic->refresh();
        $this->assertSame(AcicTellerStatus::ForwardedToLandBank, $acic->teller_status);
        $this->assertSame('TR-2', $acic->transmittal_no);
        $this->assertNotNull($first);

        // Both trips and the return between them are on the record.
        $actions = $acic->history()->orderBy('id')->pluck('action')->all();
        $this->assertSame([
            'forwarded_to_teller', 'accepted', 'forwarded_to_land_bank',
            'returned_by_bank', 're_forwarded_to_land_bank',
        ], $actions);

        Sanctum::actingAs($teller);
        $history = $this->getJson("/api/v1/acics/{$acic->id}/history")->assertOk()->json('data');
        $this->assertCount(5, $history);
        $this->assertSame('Endorsement missing.', $history[3]['note']);
    }

    // ------------------------------------------------------------------ 5. credited

    public function test_completion_is_blocked_while_a_record_is_still_returned(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->accepted($acic, $admin);
        $svc = app(AcicTellerService::class);
        $svc->forwardToLandBank($teller, $acic->fresh(), []);
        $svc->returnedByBank($teller, $acic->fresh(), ['reason' => 'Endorsement missing.']);
        $svc->forwardToLandBank($teller, $acic->fresh(), []);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", ['bank_confirmation_no' => 'BC-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acic' => 'Resolve returned records before completing.']);

        // Once the flags are cleared it goes through.
        $acic->cheques()->update(['returned_by_bank' => false]);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", ['bank_confirmation_no' => 'BC-1'])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed');
    }

    public function test_completion_records_the_credit_and_is_final(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);
        app(AcicTellerService::class)->forwardToLandBank($teller, $acic->fresh(), []);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [])
            ->assertStatus(422)->assertJsonValidationErrors('bank_confirmation_no');

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'bank_confirmation_no' => 'LBP-CR-88120',
            'note' => 'Credited same day.',
        ])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed')
            ->assertJsonPath('data.bank_confirmation_no', 'LBP-CR-88120');

        $acic->refresh();
        $this->assertSame($teller->id, $acic->confirmed_by);
        $this->assertNotNull($acic->credited_at);
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);

        // Final: nothing further is allowed.
        foreach ([
            ['forward-to-land-bank', []],
            ['returned-by-bank', ['reason' => 'Too late.']],
            ['complete-teller', ['bank_confirmation_no' => 'BC-2']],
            ['return-to-admin', ['reason' => 'Too late.']],
        ] as [$path, $payload]) {
            $this->postJson("/api/v1/acics/{$acic->id}/{$path}", $payload)->assertStatus(422);
        }

        $this->assertSame(AcicTellerStatus::Completed, $acic->fresh()->teller_status);
    }

    /**
     * A teller who hands the ACIC over and gets the credit in one visit closes it straight
     * from Accepted, recording when it went over the counter as part of that.
     */
    public function test_a_teller_can_complete_straight_from_accepted_with_the_handover_time(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);
        $this->assertNull($acic->fresh()->forwarded_to_land_bank_at, 'never lodged separately');

        Sanctum::actingAs($teller);

        // The visit happens after the ACIC was accepted, so move the clock on first.
        $this->travelTo(now()->addHours(5));
        $handed = $this->manila(3);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'handed_to_bank_at' => $handed,
            'credited_at' => $this->manila(1),
            'bank_confirmation_no' => 'LBP-CR-55010',
        ])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed');

        $acic->refresh();
        // The handover is kept, even though there was no separate forwarding step.
        $this->assertSame(
            $handed,
            $acic->forwarded_to_land_bank_at->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'),
        );
        $this->assertNotNull($acic->credited_at);
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));

        // And the history says where it came from.
        $last = $acic->history()->orderByDesc('id')->first();
        $this->assertSame('completed', $last->action);
        $this->assertSame(AcicTellerStatus::AcceptedByTeller, $last->from_status);
    }

    public function test_the_handover_time_must_be_sane_when_given_at_completion(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);

        Sanctum::actingAs($teller);
        $this->travelTo(now()->addHours(5));

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'handed_to_bank_at' => $this->manila(-24),
            'bank_confirmation_no' => 'BC-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['handed_to_bank_at' => 'cannot be in the future']);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'handed_to_bank_at' => $this->manila(24 * 365),
            'bank_confirmation_no' => 'BC-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['handed_to_bank_at' => 'earlier than the date and time the ACIC was accepted']);

        // The credit cannot come before the handover.
        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'handed_to_bank_at' => $this->manila(1),
            'credited_at' => $this->manila(3),
            'bank_confirmation_no' => 'BC-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['credited_at' => 'earlier than the date and time it was handed to the bank']);

        $this->assertSame(AcicTellerStatus::AcceptedByTeller, $acic->fresh()->teller_status);
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
        $teller = $this->accepted($acic, $admin);
        app(AcicTellerService::class)->forwardToLandBank($teller, $acic->fresh(), []);

        Sanctum::actingAs($teller);

        // The page still believes the ACIC is merely accepted.
        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", [
            'bank_confirmation_no' => 'BC-1',
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

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-land-bank", ['transmittal_no' => 'TR-9'])->assertOk();
        $this->assertSame([LddapStatus::ForwardedToLandBank->value], $this->recordStatuses($acic));

        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", ['reason' => 'Account closed.'])->assertOk();
        $this->assertSame([LddapStatus::ReturnedByBank->value], $this->recordStatuses($acic));
        $this->assertSame(2, $acic->lddaps()->where('returned_by_bank', true)->count());

        $this->postJson("/api/v1/acics/{$acic->id}/forward-to-land-bank", [])->assertOk();
        $acic->lddaps()->update(['returned_by_bank' => false]);

        $this->postJson("/api/v1/acics/{$acic->id}/complete-teller", ['bank_confirmation_no' => 'LBP-77'])->assertOk();
        $this->assertSame([LddapStatus::Completed->value], $this->recordStatuses($acic));

        // Each record carries the whole trip in its own trail.
        $steps = Lddap::query()->where('acic_id', $acic->id)->first()
            ->routingHistory()->orderBy('id')->pluck('to_status')->all();
        $this->assertContains(LddapStatus::Completed->value, array_map(fn ($s) => $s instanceof LddapStatus ? $s->value : $s, $steps));
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
        $svc = app(AcicTellerService::class);

        [$pending] = $this->chequeAcic(1, $admin);
        [$accepted] = $this->chequeAcic(1, $admin);
        [$atBank] = $this->chequeAcic(1, $admin);

        $svc->forwardToTeller($admin, $pending);
        $this->accepted($accepted, $admin, $mine);
        $this->accepted($atBank, $admin, $mine);
        $svc->forwardToLandBank($mine, $atBank->fresh(), []);

        Sanctum::actingAs($mine);
        $queue = $this->getJson('/api/v1/acics/teller-queue')->assertOk()->json('data');

        $this->assertSame([$pending->acic_number], array_column($queue['pending'], 'acic_number'));
        $this->assertSame([$accepted->acic_number], array_column($queue['accepted'], 'acic_number'));
        $this->assertSame([$atBank->acic_number], array_column($queue['forwarded'], 'acic_number'));
        $this->assertSame([], $queue['returned']);
        $this->assertSame([], $queue['completed']);
        $this->assertSame('Land Bank of the Philippines', $queue['bank_name']);

        // The rows carry what the table's columns need.
        $row = $queue['forwarded'][0];
        $this->assertSame('cheque', $row['type']);
        $this->assertSame(1, $row['cheque_count']);
        $this->assertSame('Ada Admin', $row['forwarded_to_teller_by']['name']);
        $this->assertNotNull($row['forwarded_to_teller_at']);
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
}

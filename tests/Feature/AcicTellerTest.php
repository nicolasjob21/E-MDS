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

    /** Carried all the way to Completed, ready for the bank to send back. */
    private function completed(Acic $acic, User $admin, ?User $teller = null): User
    {
        $teller = $this->accepted($acic, $admin, $teller);
        app(AcicTellerService::class)->confirmAndComplete($teller, $acic->fresh(), ['forwarded_at' => $this->manila(2)]);

        return $teller;
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
            ['confirm-complete', ['forwarded_at' => $this->manila()]],
            ['returned-by-bank', ['reason' => 'Signature missing.']],

            ['return-to-admin', ['reason' => 'Not mine.']],
        ] as [$path, $payload]) {
            $this->postJson("/api/v1/acics/{$acic->id}/{$path}", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['acic' => 'only they can act on it']);
        }
    }

    // --------------------------------------------- 3. lodged with the bank, and closed

    public function test_confirm_and_complete_lodges_it_and_closes_it_in_one_step(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->accepted($acic, $admin);
        $at = $this->manila(1);

        Sanctum::actingAs($teller);

        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", [])
            ->assertStatus(422)->assertJsonValidationErrors('forwarded_at');

        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", [
            'forwarded_at' => $at,
            'note' => 'Hand-carried, teller 3.',
        ])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed');

        $acic->refresh();
        $this->assertSame($at, $acic->forwarded_to_land_bank_at->setTimezone(Validity::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame('Hand-carried, teller 3.', $acic->completion_note);
        $this->assertSame($teller->id, $acic->completed_by);
        // Every record on it goes with it.
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));
        Notification::assertSentTo($admin, ActivityNotification::class);
    }

    public function test_the_land_bank_date_cannot_be_in_the_future_or_before_it_was_accepted(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);

        Sanctum::actingAs($teller);
        $this->travelTo(now()->addHours(5));

        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", ['forwarded_at' => $this->manila(-24)])
            ->assertStatus(422)->assertJsonValidationErrors(['forwarded_at' => 'cannot be in the future']);

        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", ['forwarded_at' => $this->manila(24 * 365)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['forwarded_at' => 'earlier than the date and time the ACIC was accepted']);

        $this->assertSame(AcicTellerStatus::AcceptedByTeller, $acic->fresh()->teller_status);
    }

    // --------------------------------------------------- 4. the bank sends it back

    public function test_a_bank_return_needs_notes_and_flags_the_records(): void
    {
        Notification::fake();
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->completed($acic, $admin);

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
        $teller = $this->completed($acic, $admin);
        $one = $acic->cheques()->orderBy('cheque_number')->first();

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", [
            'reason' => 'Wrong amount on one.',
            'cheque_ids' => [$one->id],
        ])->assertOk();

        $this->assertTrue($one->fresh()->returned_by_bank);
        $this->assertSame(1, $acic->cheques()->where('returned_by_bank', true)->count());
    }

    /** Completing again after a return clears what the bank objected to. */
    public function test_completing_again_clears_the_bank_return_flags(): void
    {
        [$acic, $admin] = $this->chequeAcic(2);
        $teller = $this->completed($acic, $admin);
        $svc = app(AcicTellerService::class);

        $svc->returnedByBank($teller, $acic->fresh(), ['returned_at' => $this->manila(1), 'reason' => 'Endorsement missing.']);
        $this->assertSame(2, $acic->cheques()->where('returned_by_bank', true)->count());

        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", ['forwarded_at' => $this->manila()])
            ->assertOk()
            ->assertJsonPath('data.teller_status', 'completed');

        $acic->refresh();
        $this->assertSame(0, $acic->cheques()->where('returned_by_bank', true)->count(), 'the flags are cleared');
        $this->assertNull($acic->bank_return_reason);
        $this->assertSame([ChequeStatus::Completed->value], $this->recordStatuses($acic));
    }

    /** Every trip to the bank is kept; a later one never overwrites an earlier. */
    public function test_every_forward_and_return_cycle_is_kept(): void
    {
        [$acic, $admin] = $this->chequeAcic();
        $teller = $this->accepted($acic, $admin);
        $svc = app(AcicTellerService::class);

        $svc->confirmAndComplete($teller, $acic->fresh(), ['forwarded_at' => $this->manila(2), 'note' => 'First lodging.']);
        $svc->returnedByBank($teller, $acic->fresh(), ['returned_at' => $this->manila(1), 'reason' => 'Endorsement missing.']);
        $svc->confirmAndComplete($teller, $acic->fresh(), ['forwarded_at' => $this->manila(), 'note' => 'Second lodging.']);

        $acic->refresh();
        $this->assertSame(AcicTellerStatus::Completed, $acic->teller_status);
        $this->assertSame('Second lodging.', $acic->completion_note);

        // Both trips and the return between them are on the record, in order.
        $this->assertSame(
            ['forwarded_to_teller', 'accepted', 'completed', 'returned_by_bank', 're_completed'],
            $acic->history()->orderBy('id')->pluck('action')->all(),
        );

        Sanctum::actingAs($teller);
        $history = $this->getJson("/api/v1/acics/{$acic->id}/history")->assertOk()->json('data');
        $this->assertCount(5, $history);
        $this->assertSame('Endorsement missing.', $history[3]['note']);
        // The first lodging's own date survives the second.
        $this->assertNotSame($history[2]['details']['forwarded_at'], $history[4]['details']['forwarded_at']);
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
        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", [
            'reason' => 'Endorsement missing.',
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

        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", ['forwarded_at' => $this->manila(2)])->assertOk();
        $this->assertSame([LddapStatus::Completed->value], $this->recordStatuses($acic));

        $this->postJson("/api/v1/acics/{$acic->id}/returned-by-bank", [
            'returned_at' => $this->manila(1), 'reason' => 'Account closed.',
        ])->assertOk();
        $this->assertSame([LddapStatus::ReturnedByBank->value], $this->recordStatuses($acic));
        $this->assertSame(2, $acic->lddaps()->where('returned_by_bank', true)->count());

        // Put right and completed again; the flags clear with it.
        $this->postJson("/api/v1/acics/{$acic->id}/confirm-complete", ['forwarded_at' => $this->manila()])->assertOk();
        $this->assertSame([LddapStatus::Completed->value], $this->recordStatuses($acic));
        $this->assertSame(0, $acic->lddaps()->where('returned_by_bank', true)->count());

        // Each record carries the whole trip in its own trail.
        $steps = Lddap::query()->where('acic_id', $acic->id)->first()
            ->routingHistory()->orderBy('id')->pluck('to_status')->all();
        $this->assertContains(
            LddapStatus::Completed->value,
            array_map(fn ($s) => $s instanceof LddapStatus ? $s->value : $s, $steps),
        );
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
        [$returned] = $this->chequeAcic(1, $admin);

        app(AcicTellerService::class)->forwardToTeller($admin, $pending);
        $this->accepted($accepted, $admin, $mine);
        $this->completed($done, $admin, $mine);
        $this->completed($returned, $admin, $mine);
        app(AcicTellerService::class)->returnedByBank($mine, $returned->fresh(), [
            'returned_at' => $this->manila(1), 'reason' => 'Endorsement missing.',
        ]);

        Sanctum::actingAs($mine);
        $queue = $this->getJson('/api/v1/acics/teller-queue')->assertOk()->json('data');

        $this->assertSame([$pending->acic_number], array_column($queue['pending'], 'acic_number'));
        $this->assertSame([$accepted->acic_number], array_column($queue['accepted'], 'acic_number'));
        $this->assertSame([$returned->acic_number], array_column($queue['returned'], 'acic_number'));
        $this->assertSame([$done->acic_number], array_column($queue['completed'], 'acic_number'));
        $this->assertSame('Land Bank of the Philippines', $queue['bank_name']);

        // The rows carry what the table's columns need.
        $row = $queue['completed'][0];
        $this->assertSame('cheque', $row['type']);
        $this->assertSame(1, $row['cheque_count']);
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
}

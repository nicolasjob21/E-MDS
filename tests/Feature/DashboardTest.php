<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\Lddap;
use App\Models\Payee;
use App\Models\Unit;
use App\Models\User;
use App\Services\AcicService;
use App\Services\AcicTellerService;
use App\Services\ChequeFlowService;
use App\Services\ChequeService;
use App\Services\LddapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard: the attention items each role sees, and the register counts behind the tiles.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcicSeries(1, 5);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff]);
    }

    /** Full registration details for one LDDAP. */
    private function details(int $n): array
    {
        $payee = Payee::firstOrCreate(['name' => 'ACME SUPPLIES INC.']);
        $payee->accounts()->firstOrCreate(['account_no' => '2028-9010-11'], ['bank' => 'LBP']);

        return [
            'lddap_no' => sprintf('LDDAP-%04d', $n),
            'nca_no' => "NCA-{$n}", 'orb_no' => "ORB-{$n}", 'dv_no' => "DV-{$n}",
            'nature_of_payment' => NatureOfPayment::CommercialClaims->value,
            'unit_id' => Unit::firstOrCreate(['name' => 'ACCOUNTING'])->id,
            'check_date' => '2026-09-21',
            'payee_id' => $payee->id,
            'gross_amount' => 1000 * $n,
        ];
    }

    private function unit(): Unit
    {
        return Unit::firstOrCreate(['name' => 'ACCOUNTING']);
    }

    /** Register → forward → receive: a record back from routing, awaiting the admin's action. */
    private function returned(User $staff, int $n): Lddap
    {
        $service = app(LddapService::class);
        $lddap = $service->register($staff, $this->details($n));
        $lddap = $service->forward($staff, $lddap, ['forward_to' => 'Accounting', 'unit_id' => $this->unit()->id, 'date_forwarded' => '2026-09-22']);

        return $service->receive($staff, $lddap, ['unit_id' => $this->unit()->id, 'date_received' => '2026-09-23']);
    }

    public function test_the_dashboard_requires_login(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_it_reports_every_register_at_a_glance(): void
    {
        $staff = $this->staff();
        app(ChequeService::class)->addRange($this->admin(), 100, 104);
        app(ChequeService::class)->useNext($staff, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        app(LddapService::class)->addRange($this->admin(), 1, 3);
        $service = app(LddapService::class);
        $service->register($staff, $this->details(1));
        $service->approve($this->admin(), $this->returned($staff, 2));

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cheques.counts.total', 5)
            ->assertJsonPath('data.cheques.counts.available', 4)
            ->assertJsonPath('data.cheques.counts.registered', 1)
            ->assertJsonPath('data.cheques.next.cheque_number', 101)
            ->assertJsonPath('data.lddaps.counts.total', 2)
            ->assertJsonPath('data.lddaps.counts.registered', 1)
            ->assertJsonPath('data.lddaps.counts.approved', 1)
            ->assertJsonPath('data.lddaps.awaiting_acic', 1)
            ->assertJsonPath('data.lddaps.on_acic', 0)
            ->assertJsonPath('data.acics.counts.total', 0)
            ->assertJsonPath('data.series.cheques.available', 4)
            ->assertJsonPath('data.series.cheques.next', 101)
            ->assertJsonPath('data.series.cheques.low', true)
            ->assertJsonPath('data.series.lddap_checks.registered', 3)
            ->assertJsonPath('data.series.lddap_checks.available', 3)
            ->assertJsonPath('data.series.lddap_checks.next', 1)
            ->assertJsonPath('data.series.acic_numbers.available', 5)
            ->assertJsonPath('data.series.acic_numbers.next', 1)
            // Staff never see the audit log, on the dashboard either.
            ->assertJsonPath('data.recent', []);
    }

    public function test_an_admin_sees_what_awaits_their_action(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        app(ChequeService::class)->addRange($admin, 100, 102);
        $cheque = app(ChequeService::class)->useNext($staff, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        app(ChequeFlowService::class)->routeForSignature($admin, $cheque, [
            'forward_to_name' => 'The Treasurer', 'date_forwarded' => '2026-09-22',
        ]);
        $this->returned($staff, 1);
        $this->returned($staff, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();
        $attention = collect($response->json('data.attention'))->keyBy('key');

        $this->assertSame(2, $attention['lddap_action']['count']);
        $this->assertSame('/lddaps?status=returned_for_acic', $attention['lddap_action']['to']);
        $this->assertSame(1, $attention['cheque_receive']['count']);
        // Nothing pending → not listed at all.
        $this->assertArrayNotHasKey('update_requests', $attention->all());
        $this->assertArrayNotHasKey('acic_signoff', $attention->all());

        // The admin's recent activity: newest first, labelled by action.
        $recent = $response->json('data.recent');
        $this->assertNotEmpty($recent);
        $this->assertSame('received_lddap_back', $recent[0]['action']);
        $this->assertLessThanOrEqual(8, count($recent));
    }

    public function test_staff_see_only_what_was_returned_to_them(): void
    {
        $admin = $this->admin();
        $me = $this->staff();
        $other = $this->staff();
        $service = app(LddapService::class);

        // One RTS'd record of mine, one of someone else's; one of mine still to forward.
        $service->rts($admin, $this->returned($me, 1), ['received_on' => '2026-09-23', 'received_by' => 'M', 'unit_id' => $this->unit()->id, 'rts_date' => '2026-09-24', 'note' => 'Fix the OBJ code.']);
        $service->rts($admin, $this->returned($other, 2), ['received_on' => '2026-09-23', 'received_by' => 'M', 'unit_id' => $this->unit()->id, 'rts_date' => '2026-09-24', 'note' => 'Fix the payee.']);
        $service->register($me, $this->details(3));
        // A cheque returned to me, and one returned to the other staff member.
        app(ChequeService::class)->addRange($admin, 100, 101);
        $cheques = app(ChequeService::class);
        $cheques->useNext($me, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        $cheques->useNext($other, 101, ['payee_name' => 'B', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        // Both come back RTS'd: one to me, one to the other staff member.
        Cheque::where('cheque_number', 100)->update(['status' => ChequeStatus::Registered, 'rts_at' => now()]);
        Cheque::where('cheque_number', 101)->update(['status' => ChequeStatus::Registered, 'rts_at' => now()]);

        Sanctum::actingAs($me);

        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, $attention['my_rts']['count']);
        $this->assertSame('/lddaps?status=rts', $attention['my_rts']['to']);
        $this->assertSame(1, $attention['cheques_rts']['count']);
        $this->assertSame(1, $attention['my_registered']['count']);
        $this->assertArrayNotHasKey('lddap_action', $attention->all());
    }

    public function test_a_teller_sees_what_is_waiting_to_be_received(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        app(ChequeService::class)->addRange($admin, 100, 101);
        $cheque = app(ChequeService::class)->useNext($staff, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        // Carry it through to an ACIC and forward that to the tellers.
        $cheque->update(['status' => ChequeStatus::ForAcic]);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$cheque->id]);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);

        Sanctum::actingAs(User::factory()->teller()->create());

        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, $attention['acics_to_accept']['count']);
        $this->assertSame('/cheques?tab=forwarded_to_teller', $attention['acics_to_accept']['to']);
        $this->assertArrayNotHasKey('acics_to_deposit', $attention->all());
    }

    public function test_nothing_waiting_is_an_empty_list(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.attention', []);
    }
}

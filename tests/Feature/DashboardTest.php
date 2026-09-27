<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\Creditor;
use App\Models\Lddap;
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
        $payee = Creditor::firstOrCreate(['name' => 'ACME SUPPLIES INC.'], ['account_no' => '0028901011']);

        return [
            'lddap_no' => sprintf('26-09-%05d', $n),
            'nca_no' => sprintf('%07d', $n), 'obr_no' => "OBR-{$n}", 'dv_no' => sprintf('10-00-%05d', $n),
            'nature_of_payment' => NatureOfPayment::CommercialClaims->value,
            'unit_name' => 'CG-8 Comptrollership',
            'check_date' => '2026-09-21',
            'payee_type' => 'creditor', 'payee_ref' => $payee->id,
            'gross_amount' => 1000 * $n,
        ];
    }

    private function unit(): string
    {
        return 'CG-8 Comptrollership';
    }

    /** An added LDDAP — For Signature: RTS / Cancel / assign to an ACIC. */
    private function returned(User $staff, int $n): Lddap
    {
        return app(LddapService::class)->register($staff, $this->details($n));
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
        $this->returned($staff, 2);

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cheques.counts.total', 5)
            ->assertJsonPath('data.cheques.counts.available', 4)
            ->assertJsonPath('data.cheques.counts.registered', 1)
            ->assertJsonMissingPath('data.cheques.next')
            ->assertJsonPath('data.lddaps.counts.total', 2)
            ->assertJsonPath('data.lddaps.counts.for_signature', 2)
            ->assertJsonPath('data.lddaps.counts.approved', 0)
            ->assertJsonPath('data.lddaps.awaiting_acic', 2)
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
        app(ChequeFlowService::class)->printDraft($staff, $cheque);
        $this->returned($staff, 1);
        $this->returned($staff, 2);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();
        $attention = collect($response->json('data.attention'))->keyBy('key');

        $this->assertSame(2, $attention['lddap_for_signature']['count']);
        $this->assertSame('/lddaps?status=for_signature', $attention['lddap_for_signature']['to']);
        // Administrators check drafts.
        $this->assertSame(1, $attention['cheque_checking']['count']);
        // Nothing pending → not listed at all.
        $this->assertArrayNotHasKey('update_requests', $attention->all());
        $this->assertArrayNotHasKey('acic_signoff', $attention->all());

        // The admin's recent activity: newest first, labelled by action.
        $recent = $response->json('data.recent');
        $this->assertNotEmpty($recent);
        $this->assertSame('registered_lddap', $recent[0]['action']);
        $this->assertLessThanOrEqual(8, count($recent));

        // So do Super Admins.
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.attention'))->keyBy('key');
        $this->assertSame(1, $attention['cheque_checking']['count']);
        $this->assertSame('/cheques?tab=for_checking', $attention['cheque_checking']['to']);
    }

    public function test_staff_see_only_what_was_returned_to_them(): void
    {
        $admin = $this->admin();
        $me = $this->staff();
        $other = $this->staff();
        $service = app(LddapService::class);

        // One RTS'd record of mine, one of someone else's; one of mine still to forward.
        $service->rts($admin, $this->returned($me, 1), ['received_on' => '2026-09-23', 'received_by' => 'M', 'unit_name' => $this->unit(), 'rts_date' => '2026-09-24', 'note' => 'Fix the OBJ code.']);
        $service->rts($admin, $this->returned($other, 2), ['received_on' => '2026-09-23', 'received_by' => 'M', 'unit_name' => $this->unit(), 'rts_date' => '2026-09-24', 'note' => 'Fix the payee.']);
        $service->register($me, $this->details(3));
        // A cheque of mine, and one of the other staff member's.
        app(ChequeService::class)->addRange($admin, 100, 101);
        $cheques = app(ChequeService::class);
        $cheques->useNext($me, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        $cheques->useNext($other, 101, ['payee_name' => 'B', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        // Both drafts come back For Compliance: one mine, one the other staff member's.
        Cheque::whereIn('cheque_number', [100, 101])->update(['status' => ChequeStatus::ForCompliance]);

        Sanctum::actingAs($me);

        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, $attention['my_rts']['count']);
        $this->assertSame('/lddaps?status=rts', $attention['my_rts']['to']);
        $this->assertSame(1, $attention['cheques_compliance']['count']);
        $this->assertSame('/cheques?tab=for_compliance', $attention['cheques_compliance']['to']);
        // The one still For Signature, waiting for an ACIC.
        $this->assertSame(1, $attention['awaiting_acic']['count']);
        $this->assertSame('/lddaps?status=for_signature', $attention['awaiting_acic']['to']);
        $this->assertArrayNotHasKey('lddap_for_signature', $attention->all());
    }

    public function test_a_teller_sees_what_is_waiting_to_be_received(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        app(ChequeService::class)->addRange($admin, 100, 101);
        $cheque = app(ChequeService::class)->useNext($staff, 100, ['payee_name' => 'A', 'amount' => 10, 'cheque_date' => '2026-09-22']);
        // Carry it through to an ACIC and forward that to the tellers.
        $cheque->update(['status' => ChequeStatus::ForSignature]);
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$cheque->id]);
        app(AcicTellerService::class)->forwardToTeller($admin, $acic);

        Sanctum::actingAs(User::factory()->teller()->create());

        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, $attention['acics_to_accept']['count']);
        $this->assertSame('/acics?status=pending', $attention['acics_to_accept']['to']);
        $this->assertArrayNotHasKey('acics_to_deposit', $attention->all());
    }

    public function test_nothing_waiting_is_an_empty_list(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.attention', []);
    }
}

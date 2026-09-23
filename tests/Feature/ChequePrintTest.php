<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use App\Services\AcicService;
use App\Services\ChequeService;
use App\Support\AmountInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The cheque view: available only once approved, and printing exactly the cheque's data. */
class ChequePrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcicSeries(1, 10);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** A used cheque #1 for ₱185,369.86 to ACME, in whatever status is asked for. */
    private function cheque(ChequeStatus $status = ChequeStatus::Approved): Cheque
    {
        $cheques = app(ChequeService::class);
        $admin = $this->admin();
        $cheques->addRange($admin, 1, 5);
        $cheques->useNext($admin, 1, [
            'payee_name' => 'ACME SUPPLIES INC.',
            'amount' => 185369.86,
            'cheque_date' => '2026-09-22',
        ]);

        $cheque = Cheque::where('cheque_number', 1)->first();
        $cheque->update(['status' => $status]);

        return $cheque->fresh();
    }

    // ------------------------------------------------------------- amount in words

    /** The cheque wording: Title Case, centavos as a fraction, closed with "Only". */
    public function test_amounts_are_spelled_the_way_a_cheque_is_written(): void
    {
        $this->assertSame(
            'One Hundred Eighty-Five Thousand Three Hundred Sixty-Nine Pesos and 86/100 Only',
            AmountInWords::cheque(185369.86),
        );
        $this->assertSame('One Thousand Pesos Only', AmountInWords::cheque(1000));
        $this->assertSame('One Peso Only', AmountInWords::cheque(1));
        $this->assertSame('Twelve Pesos and 50/100 Only', AmountInWords::cheque(12.5));
        $this->assertSame('Zero Pesos and 05/100 Only', AmountInWords::cheque(0.05));
        $this->assertSame(
            'Two Million Five Hundred Thousand Pesos and 99/100 Only',
            AmountInWords::cheque('2500000.99'),
        );

        // The ACIC form's wording is unchanged by the cheque's.
        $this->assertSame(
            'THREE MILLION FORTY-TWO THOUSAND EIGHT HUNDRED EIGHTY-SIX PESOS AND THIRTY-EIGHT CENTAVOS',
            AmountInWords::pesos(3042886.38),
        );
    }

    // ------------------------------------------------------------- the view

    /** The view carries every field the cheque prints, formatted as the cheque shows them. */
    public function test_the_view_carries_the_cheques_data(): void
    {
        // For ACIC first, so putting it on one is the step that approves it.
        $cheque = $this->cheque(ChequeStatus::ForAcic);
        $admin = $this->admin();
        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [$cheque->id]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/cheques/{$cheque->id}/print")
            ->assertOk()
            ->assertJsonPath('data.cheque_number', 1)
            ->assertJsonPath('data.cheque_date', '2026-09-22')
            ->assertJsonPath('data.payee_name', 'ACME SUPPLIES INC.')
            ->assertJsonPath('data.amount_figures', '₱185,369.86')
            ->assertJsonPath(
                'data.amount_in_words',
                'One Hundred Eighty-Five Thousand Three Hundred Sixty-Nine Pesos and 86/100 Only',
            )
            ->assertJsonPath('data.account_no', config('acic.account_no'))
            ->assertJsonPath('data.bank_name', config('acic.bank.name'))
            ->assertJsonPath('data.acic_number', $acic->acic_number)
            ->assertJsonPath('data.lddap_no', null);
    }

    /** Only an approved cheque has a view; every other status is refused. */
    public function test_the_view_exists_only_for_an_approved_cheque(): void
    {
        Sanctum::actingAs($this->admin());

        foreach ([ChequeStatus::Registered, ChequeStatus::OutForSignature, ChequeStatus::ForAcic, ChequeStatus::Cancelled] as $status) {
            $cheque = $this->cheque($status);
            $this->getJson("/api/v1/cheques/{$cheque->id}/print")
                ->assertStatus(422)
                ->assertJsonValidationErrors('cheque');
            $cheque->delete();
            Cheque::query()->delete();
        }

        $approved = $this->cheque(ChequeStatus::Approved);
        $this->getJson("/api/v1/cheques/{$approved->id}/print")->assertOk();
    }

    public function test_any_signed_in_user_may_view_an_approved_cheque(): void
    {
        $cheque = $this->cheque();

        Sanctum::actingAs(User::factory()->teller()->create());
        $this->getJson("/api/v1/cheques/{$cheque->id}/print")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/cheques/{$cheque->id}/print")->assertOk();
    }

    /** The row and the endpoint agree: the Print button appears exactly where print works. */
    public function test_can_print_matches_where_the_endpoint_allows_it(): void
    {
        $cheque = $this->cheque();
        Sanctum::actingAs($this->admin());

        // An enum cannot be an array key, so the pairs are listed instead.
        foreach ([
            [ChequeStatus::Registered, false],
            [ChequeStatus::OutForSignature, false],
            [ChequeStatus::ForAcic, false],
            [ChequeStatus::Approved, true],
            [ChequeStatus::ForwardedToTeller, true],
            [ChequeStatus::AcceptedByTeller, true],
            [ChequeStatus::Completed, true],
            [ChequeStatus::ReleasedToPayee, true],
            [ChequeStatus::Cancelled, false],
        ] as [$status, $printable]) {
            $cheque->forceFill(['status' => $status])->save();

            $row = $this->getJson("/api/v1/cheques?search={$cheque->cheque_number}")->assertOk()->json('data.0');
            $this->assertSame($printable, $row['can_print'], $status->value);

            $this->getJson("/api/v1/cheques/{$cheque->id}/print")
                ->assertStatus($printable ? 200 : 422);
        }
    }
}

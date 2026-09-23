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

/** The printed ACIC form: the numbers it states, and how they are spelled out. */
class AcicFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ACIC numbers are a registered series; give the suite a block to draw on.
        $this->seedAcicSeries(1, 50);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * The wording has to match the sheet the bank accepts: hyphenated compound tens, "PESOS AND
     * ... CENTAVOS", no "and" between the hundreds and the tens.
     */
    public function test_amounts_are_spelled_the_way_the_form_requires(): void
    {
        $this->assertSame(
            'THREE MILLION FORTY-TWO THOUSAND EIGHT HUNDRED EIGHTY-SIX PESOS AND THIRTY-EIGHT CENTAVOS',
            AmountInWords::pesos(3042886.38),
        );

        // A whole amount carries no centavos clause.
        $this->assertSame(
            'ONE MILLION FIVE HUNDRED NINETY-ONE THOUSAND FORTY-NINE PESOS',
            AmountInWords::pesos(1591049.00),
        );

        $this->assertSame('ONE HUNDRED FIFTEEN PESOS AND FIVE CENTAVOS', AmountInWords::pesos(115.05));
        $this->assertSame('ZERO PESOS', AmountInWords::pesos(0));
        $this->assertSame('ZERO PESOS AND NINETY-NINE CENTAVOS', AmountInWords::pesos(0.99));
        $this->assertSame('TEN THOUSAND PESOS', AmountInWords::pesos(10000));
    }

    public function test_the_detail_view_carries_the_printed_form_block(): void
    {
        $cheques = app(ChequeService::class);
        $admin = $this->admin();
        $cheques->addRange($admin, 1, 3);
        $cheques->useNext($admin, 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1250.50,
            'cheque_date' => '2026-08-30',
        ]);
        Cheque::where('cheque_number', 1)->update(['status' => ChequeStatus::ForAcic]);

        $acic = app(AcicService::class)->create($admin);
        app(AcicService::class)->assignCheques($admin, $acic, [Cheque::where('cheque_number', 1)->value('id')]);

        Sanctum::actingAs($admin);
        $form = $this->getJson("/api/v1/acics/{$acic->id}")->assertOk()->json('data.form');

        $this->assertSame('1,250.50', $form['total_amount']);
        $this->assertSame(1, $form['total_checks']);
        $this->assertSame(
            'ONE THOUSAND TWO HUNDRED FIFTY PESOS AND FIFTY CENTAVOS',
            $form['amount_in_words'],
        );

        // The form's own number format is YY-MM-SEQ, not the bare sequence.
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2}-1$/', $form['acic_no']);
        $this->assertSame(config('acic.account_no'), $form['account_no']);
        $this->assertStringEndsWith('.txt', $form['filename']);
    }

    /** The block is computed from membership, so it only rides on the detail view. */
    public function test_the_listing_does_not_carry_the_form_block(): void
    {
        app(AcicService::class)->create($this->admin());
        Sanctum::actingAs($this->admin());

        $row = $this->getJson('/api/v1/acics')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('form', $row);
    }
}

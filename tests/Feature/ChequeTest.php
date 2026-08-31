<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChequeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff]);
    }

    private function teller(): User
    {
        return User::factory()->teller()->create();
    }

    private function seedCheques(int $count, int $startAt = 1): void
    {
        app(ChequeService::class)->addRange($this->admin(), $startAt, $startAt + $count - 1);
    }

    /**
     * A valid "use cheque" payload with the required detail fields.
     *
     * @return array<string, mixed>
     */
    private function usePayload(int $number): array
    {
        return [
            'cheque_number' => $number,
            'payee_name' => 'Acme Co',
            'amount' => 1500.50,
            'cheque_date' => '2026-07-19',
        ];
    }

    public function test_guests_cannot_list_cheques(): void
    {
        $this->getJson('/api/v1/cheques')->assertUnauthorized();
    }

    public function test_next_returns_lowest_available_number(): void
    {
        $this->seedCheques(5, 100); // 100..104

        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/cheques/next')
            ->assertOk()
            ->assertJsonPath('data.cheque_number', 100);
    }

    public function test_staff_can_use_the_next_cheque_in_sequence(): void
    {
        $this->seedCheques(5, 100);
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/cheques/use', $this->usePayload(100))
            ->assertOk()
            ->assertJsonPath('data.status', 'used')
            ->assertJsonPath('data.payee_name', 'Acme Co');

        $cheque = Cheque::where('cheque_number', 100)->first();
        $this->assertSame(ChequeStatus::Used, $cheque->status);
        $this->assertSame($staff->id, $cheque->used_by);
        $this->assertNotNull($cheque->used_at);
        $this->assertSame('Acme Co', $cheque->payee_name);
        $this->assertSame('1500.50', (string) $cheque->amount);

        $this->assertDatabaseHas('cheque_logs', [
            'user_id' => $staff->id,
            'action' => 'used_cheque',
            'cheque_number' => 100,
        ]);
    }

    public function test_cheque_numbers_cannot_be_skipped(): void
    {
        $this->seedCheques(5, 100); // next is 100
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/use', $this->usePayload(103))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_number');

        // Nothing was consumed.
        $this->assertSame(5, Cheque::where('status', ChequeStatus::Available)->count());
    }

    public function test_using_consumes_numbers_strictly_in_order(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();
        // The next one is now 2 — asking for 1 again must fail.
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertStatus(422);
        $this->postJson('/api/v1/cheques/use', $this->usePayload(2))->assertOk();
    }

    public function test_cannot_use_when_no_cheques_are_available(): void
    {
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_number');
    }

    public function test_use_requires_payee_amount_and_date(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/use', ['cheque_number' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payee_name', 'amount', 'cheque_date']);
    }

    public function test_a_teller_can_confirm_a_used_cheque_as_received(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();

        $cheque = Cheque::where('cheque_number', 1)->first();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")
            ->assertOk()
            ->assertJsonPath('data.is_received', true)
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('cheque_logs', ['action' => 'received_cheque', 'cheque_number' => 1]);
    }

    public function test_a_non_teller_cannot_confirm_receipt(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();
        $cheque = Cheque::where('cheque_number', 1)->first();

        // Staff is still acting; confirming receipt is teller-only.
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertForbidden();

        // Admin cannot either.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertForbidden();
    }

    public function test_an_available_cheque_cannot_be_confirmed_as_received(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->teller());

        $cheque = Cheque::where('cheque_number', 1)->first(); // still available

        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertStatus(422);
    }

    public function test_a_cheque_cannot_be_confirmed_as_received_twice(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();
        $cheque = Cheque::where('cheque_number', 1)->first();

        Sanctum::actingAs($this->teller());
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertOk();
        $this->postJson("/api/v1/cheques/{$cheque->id}/receive")->assertStatus(422);
    }

    public function test_staff_cannot_add_a_range(): void
    {
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 1, 'end_at' => 10])
            ->assertForbidden();
    }

    public function test_admin_registers_a_book_by_its_first_and_last_serial(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 10001, 'end_at' => 10200])
            ->assertCreated()
            ->assertJsonPath('data.from', 10001)
            ->assertJsonPath('data.to', 10200)
            ->assertJsonPath('data.count', 200);

        $this->assertSame(200, Cheque::count());
        $this->assertSame(10001, Cheque::min('cheque_number'));
        $this->assertSame(10200, Cheque::max('cheque_number'));
    }

    /** A new physical book carries its own bank-assigned serials, which need not adjoin the last. */
    public function test_a_new_book_may_start_above_the_last_existing_number(): void
    {
        $this->seedCheques(500, 1); // 1..500
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 10001, 'end_at' => 10200])
            ->assertCreated()
            ->assertJsonPath('data.from', 10001);

        $this->assertSame(700, Cheque::count());
        // The numbers between the books simply never existed.
        $this->assertSame(0, Cheque::whereBetween('cheque_number', [501, 10000])->count());
    }

    public function test_a_range_overlapping_existing_serials_is_rejected(): void
    {
        $this->seedCheques(500, 1); // 1..500
        Sanctum::actingAs($this->admin());

        // Fully inside the existing book.
        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 100, 'end_at' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        // Straddling the end of it.
        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 400, 'end_at' => 600])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        // A single already-registered serial.
        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 500, 'end_at' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        // Nothing was added by any of the rejected attempts.
        $this->assertSame(500, Cheque::count());
    }

    public function test_the_last_serial_cannot_be_below_the_first(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 200, 'end_at' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_at');
    }

    public function test_both_serials_are_required(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_at', 'end_at']);
    }

    public function test_an_absurdly_large_range_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 1, 'end_at' => 5_000_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_at');

        $this->assertSame(0, Cheque::count());
    }

    public function test_a_single_cheque_range_is_allowed(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 77, 'end_at' => 77])
            ->assertCreated()
            ->assertJsonPath('data.count', 1);
    }

    public function test_logs_are_admin_only(): void
    {
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/logs')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/logs')->assertOk();
    }

    public function test_add_range_is_recorded_in_the_audit_log(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/cheques/add-range', ['start_at' => 1, 'end_at' => 10]);

        $this->assertDatabaseHas('cheque_logs', [
            'user_id' => $admin->id,
            'action' => 'added_cheque_range',
        ]);
    }
}

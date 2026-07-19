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

    private function seedCheques(int $count, int $startAt = 1): void
    {
        app(ChequeService::class)->addRange($this->admin(), $count, $startAt);
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

    public function test_a_used_cheque_can_be_cashed(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();

        $cheque = Cheque::where('cheque_number', 1)->first();

        $this->postJson("/api/v1/cheques/{$cheque->id}/cash", [
            'teller_name' => 'Jane Teller',
            'cashed_at' => '2026-07-20',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_cashed', true)
            ->assertJsonPath('data.teller_name', 'Jane Teller');

        $this->assertDatabaseHas('cheque_logs', ['action' => 'cashed_cheque', 'cheque_number' => 1]);
    }

    public function test_an_available_cheque_cannot_be_cashed(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());

        $cheque = Cheque::where('cheque_number', 1)->first(); // still available

        $this->postJson("/api/v1/cheques/{$cheque->id}/cash", [
            'teller_name' => 'Jane Teller',
            'cashed_at' => '2026-07-20',
        ])->assertStatus(422);
    }

    public function test_a_cheque_cannot_be_cashed_twice(): void
    {
        $this->seedCheques(3, 1);
        Sanctum::actingAs($this->staff());
        $this->postJson('/api/v1/cheques/use', $this->usePayload(1))->assertOk();
        $cheque = Cheque::where('cheque_number', 1)->first();

        $payload = ['teller_name' => 'Jane Teller', 'cashed_at' => '2026-07-20'];
        $this->postJson("/api/v1/cheques/{$cheque->id}/cash", $payload)->assertOk();
        $this->postJson("/api/v1/cheques/{$cheque->id}/cash", $payload)->assertStatus(422);
    }

    public function test_staff_cannot_add_a_range(): void
    {
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/cheques/add-range', ['count' => 10])
            ->assertForbidden();
    }

    public function test_admin_add_range_continues_from_the_last_number(): void
    {
        $this->seedCheques(500, 1); // 1..500
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['count' => 100])
            ->assertCreated()
            ->assertJsonPath('data.from', 501)
            ->assertJsonPath('data.to', 600);

        $this->assertSame(600, Cheque::max('cheque_number'));
        $this->assertSame(600, Cheque::count()); // no gaps, no duplicates
    }

    public function test_first_range_respects_start_at(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/cheques/add-range', ['count' => 10, 'start_at' => 1001])
            ->assertCreated()
            ->assertJsonPath('data.from', 1001)
            ->assertJsonPath('data.to', 1010);
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

        $this->postJson('/api/v1/cheques/add-range', ['count' => 10, 'start_at' => 1]);

        $this->assertDatabaseHas('cheque_logs', [
            'user_id' => $admin->id,
            'action' => 'added_cheque_range',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChequeSearchTest extends TestCase
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

    /**
     * Register 10001–10005, use the first three, then approve two of them and put them on
     * ACIC #7 so the search has both a cheque number and an ACIC number to match.
     */
    private function seedCheques(): void
    {
        $cheques = app(ChequeService::class);
        $cheques->addRange($this->admin(), 10001, 10005);

        $staff = $this->staff();
        foreach (range(0, 2) as $i) {
            $cheques->useNext($staff, 10001 + $i, [
                'payee_name' => 'Payee '.$i,
                'amount' => 100,
                'cheque_date' => '2026-08-30',
            ]);
        }

        // Approve #10001 and #10002 and put them on ACIC #7.
        Cheque::whereIn('cheque_number', [10001, 10002])->update(['status' => ChequeStatus::Approved]);
        $acic = Acic::create(['acic_number' => 7, 'status' => 'used']);
        Cheque::whereIn('cheque_number', [10001, 10002])->update(['acic_id' => $acic->id]);
    }

    /** @return list<int> */
    private function search(string $term, string $status = 'all'): array
    {
        $response = $this->getJson('/api/v1/cheques?status='.$status.'&search='.urlencode($term))
            ->assertOk();

        return array_column($response->json('data'), 'cheque_number');
    }

    public function test_a_full_cheque_number_finds_that_cheque(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->assertSame([10003], $this->search('10003'));
    }

    public function test_a_partial_cheque_number_matches_every_number_containing_it(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->assertSame([10001, 10002, 10003, 10004, 10005], $this->search('1000'));
        $this->assertSame([10005], $this->search('005'));
    }

    public function test_an_acic_number_finds_the_cheques_on_it(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        // ACIC #7 carries #10001 and #10002.
        $this->assertSame([10001, 10002], $this->search('7'));
    }

    public function test_search_combines_with_the_status_filter(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        // 10001–10002 were approved and put on ACIC #7; 10003 is still used;
        // 10004–10005 are still available.
        $this->assertSame([10001, 10002], $this->search('1000', 'approved'));
        $this->assertSame([10003], $this->search('1000', 'used'));
        $this->assertSame([10004, 10005], $this->search('1000', 'available'));
    }

    public function test_no_match_returns_an_empty_page(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->assertSame([], $this->search('NOPE-1234'));
        $this->assertSame([], $this->search('99999'));
        // No ACIC #4 exists, so nothing is matched through the link either.
        $this->assertSame([], $this->search('ACIC'));
    }

    public function test_a_blank_search_returns_everything(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->assertCount(5, $this->search(''));
        $this->assertCount(5, $this->search('   '));
    }

    /** A literal wildcard must be matched as text, not treated as "match anything". */
    public function test_like_wildcards_in_the_term_are_escaped(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->assertSame([], $this->search('%'));
        $this->assertSame([], $this->search('_'));
        $this->assertSame([], $this->search('100_'));

        $this->assertSame([], $this->search('%_%'));
    }

    public function test_an_overlong_search_term_is_rejected(): void
    {
        $this->seedCheques();
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/cheques?search='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');
    }

    public function test_guests_cannot_search(): void
    {
        $this->seedCheques();

        $this->getJson('/api/v1/cheques?search=10001')->assertUnauthorized();
    }
}

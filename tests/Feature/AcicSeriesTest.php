<?php

namespace Tests\Feature;

use App\Enums\AcicNumberStatus;
use App\Models\AcicNumber;
use App\Models\User;
use App\Services\AcicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The ACIC number series: registered in blocks, handed out lowest-unused-first.
 *
 * Deliberately does NOT seed a series in setUp — these tests are about registration itself.
 */
class AcicSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    // ------------------------------------------------------------- registering

    public function test_an_admin_can_register_a_block(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/acics/add-range', ['start_at' => 1, 'end_at' => 10])
            ->assertCreated()
            ->assertJsonPath('data.count', 10);

        $this->assertSame(10, AcicNumber::count());
        $this->assertDatabaseHas('cheque_logs', ['action' => 'added_acic_range']);
    }

    public function test_only_an_admin_can_register_a_block(): void
    {
        Sanctum::actingAs($this->staff());

        $this->postJson('/api/v1/acics/add-range', ['start_at' => 1, 'end_at' => 10])
            ->assertForbidden();
    }

    /** No number is ever registered twice. */
    public function test_a_number_already_registered_is_rejected(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/acics/add-range', ['start_at' => 10, 'end_at' => 20])->assertCreated();

        // Straddling, fully inside, and a single-number overlap are all refused.
        foreach ([[15, 25], [12, 18], [20, 20]] as [$from, $to]) {
            $this->postJson('/api/v1/acics/add-range', ['start_at' => $from, 'end_at' => $to])
                ->assertStatus(422)
                ->assertJsonValidationErrors('start_at');
        }

        // Nothing was added by the refused attempts.
        $this->assertSame(11, AcicNumber::count());
    }

    public function test_a_backwards_range_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/acics/add-range', ['start_at' => 20, 'end_at' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_at');
    }

    // ------------------------------------------------------- handing them out

    public function test_the_lowest_unused_number_is_taken_next(): void
    {
        $admin = $this->admin();
        app(AcicService::class)->addRange($admin, 100, 105);

        $this->assertSame(100, app(AcicService::class)->nextNumber());

        $first = app(AcicService::class)->create($admin);
        $this->assertSame(100, $first->acic_number);
        $this->assertSame(101, app(AcicService::class)->nextNumber());

        $second = app(AcicService::class)->create($admin);
        $this->assertSame(101, $second->acic_number);

        // The claimed numbers leave the pool.
        $this->assertSame(
            2,
            AcicNumber::where('status', AcicNumberStatus::Used)->count(),
        );
    }

    /**
     * The point of a registered series: a block added *below* numbers already in use is drawn on
     * first, rather than the sequence marching on from the highest number.
     */
    public function test_a_block_registered_below_numbers_in_use_is_used_first(): void
    {
        $admin = $this->admin();
        $acics = app(AcicService::class);

        $acics->addRange($admin, 500, 505);
        $this->assertSame(500, $acics->create($admin)->acic_number);

        // A lower block arrives afterwards.
        $acics->addRange($admin, 10, 12);

        $this->assertSame(10, $acics->nextNumber());
        $this->assertSame(10, $acics->create($admin)->acic_number);
        $this->assertSame(11, $acics->create($admin)->acic_number);
        $this->assertSame(12, $acics->create($admin)->acic_number);

        // Only once the lower block is spent does it go back to the higher one.
        $this->assertSame(501, $acics->create($admin)->acic_number);
    }

    /**
     * A block that adjoins the one on file simply continues it — the numbers are rows, so there
     * is no second "series" to create. 10–12 then 13–15 hands out 10, 11, 12, 13, … unbroken.
     */
    public function test_an_adjoining_block_continues_the_existing_run(): void
    {
        $admin = $this->admin();
        $acics = app(AcicService::class);

        $acics->addRange($admin, 10, 12);
        $acics->addRange($admin, 13, 15);

        $this->assertSame(6, AcicNumber::count());

        $taken = collect(range(1, 6))->map(fn () => $acics->create($admin)->acic_number)->all();
        $this->assertSame([10, 11, 12, 13, 14, 15], $taken);
    }

    /** Gaps between blocks were never registered, so they are never handed out. */
    public function test_numbers_between_two_blocks_are_never_issued(): void
    {
        $admin = $this->admin();
        $acics = app(AcicService::class);

        $acics->addRange($admin, 1, 2);
        $acics->addRange($admin, 9, 10);

        $taken = collect(range(1, 4))->map(fn () => $acics->create($admin)->acic_number)->all();
        $this->assertSame([1, 2, 9, 10], $taken);
    }

    public function test_opening_an_acic_is_refused_when_the_series_is_exhausted(): void
    {
        $admin = $this->admin();
        app(AcicService::class)->addRange($admin, 1, 1);
        app(AcicService::class)->create($admin);

        $this->assertNull(app(AcicService::class)->nextNumber());

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/acics')->assertStatus(422);
    }

    public function test_the_series_endpoint_reports_the_counts(): void
    {
        $admin = $this->admin();
        app(AcicService::class)->addRange($admin, 1, 5);
        app(AcicService::class)->create($admin);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/acics/series')
            ->assertOk()
            ->assertJsonPath('data.registered', 5)
            ->assertJsonPath('data.used', 1)
            ->assertJsonPath('data.available', 4);
    }
}

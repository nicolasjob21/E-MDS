<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChequeFlowService;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function seedRange(User $admin, int $count = 3): void
    {
        app(ChequeService::class)->addRange($admin, 1, $count);
    }

    public function test_using_a_cheque_notifies_admins_but_not_the_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);

        app(ChequeService::class)->useNext($staff, 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-07-19',
        ]);

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame('used', $admin->notifications()->first()->data['kind']);
        $this->assertSame(0, $staff->fresh()->unreadNotifications()->count());
    }

    public function test_an_admin_actor_is_not_notified_of_their_own_cheque_use(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedRange($admin);

        app(ChequeService::class)->useNext($admin, 1, [
            'payee_name' => 'Acme Co',
            'amount' => 1000,
            'cheque_date' => '2026-07-19',
        ]);

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_a_draft_goes_to_the_admins_and_the_verdict_back_to_the_preparer(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);

        $cheque = app(ChequeService::class)->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);

        // Clear the "used" notifications so we assert only on the draft.
        $admin->notifications()->delete();
        $super->notifications()->delete();

        $flow = app(ChequeFlowService::class);
        $flow->printDraft($staff, $cheque);

        // Every admin in charge — Administrator and Super Admin — is asked to check it.
        $this->assertSame('request', $super->notifications()->first()->data['kind']);
        $this->assertSame('request', $admin->notifications()->first()->data['kind']);
        $this->assertSame(0, $staff->notifications()->count());

        $flow->approveDraft($super, $cheque->fresh(), 'Looks right.');

        // The preparer hears the verdict.
        $this->assertSame('approved', $staff->notifications()->first()->data['kind']);
    }

    public function test_a_returned_draft_tells_the_preparer_what_to_change(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);

        $cheque = app(ChequeService::class)->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);
        $flow = app(ChequeFlowService::class);
        $flow->printDraft($staff, $cheque);
        $flow->returnDraft($super, $cheque->fresh(), 'Amount in words is wrong.');

        $note = $staff->notifications()->latest()->first()->data;
        $this->assertSame('rejected', $note['kind']);
        $this->assertStringContainsString('Amount in words is wrong.', $note['message']);
    }

    public function test_the_feed_endpoint_returns_notifications_and_unread_count(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);
        app(ChequeService::class)->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'used');
    }

    public function test_mark_all_read_clears_the_unread_count(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);
        app(ChequeService::class)->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }
}

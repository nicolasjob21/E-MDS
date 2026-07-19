<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\ChequeUpdateRequest;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\UpdateRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function seedRange(User $admin, int $count = 3): void
    {
        app(ChequeService::class)->addRange($admin, $count, 1);
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

    public function test_the_update_request_lifecycle_notifies_the_right_people(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);

        $cheques = app(ChequeService::class);
        $cheques->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);
        $cheque = Cheque::where('cheque_number', 1)->first();

        // Clear the "used" notification so we assert only on the request lifecycle.
        $admin->notifications()->delete();

        $requests = app(UpdateRequestService::class);
        $request = $requests->create($staff, $cheque, [
            'payee_name' => 'Acme Corporation',
            'amount' => 1250.50,
            'cheque_date' => '2026-07-20',
        ], 'Payee misspelled.');

        // Admin is notified of the pending request.
        $this->assertSame('request', $admin->notifications()->first()->data['kind']);
        $this->assertSame(0, $staff->fresh()->unreadNotifications()->count());

        $requests->approve($admin, ChequeUpdateRequest::find($request->id), 'Looks right.');

        // The requester is notified of the approval.
        $this->assertSame('approved', $staff->notifications()->first()->data['kind']);
    }

    public function test_a_rejection_notifies_the_requester(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->seedRange($admin);

        $cheques = app(ChequeService::class);
        $cheques->useNext($staff, 1, ['payee_name' => 'Acme Co', 'amount' => 1000, 'cheque_date' => '2026-07-19']);
        $cheque = Cheque::where('cheque_number', 1)->first();

        $requests = app(UpdateRequestService::class);
        $request = $requests->create($staff, $cheque, [
            'payee_name' => 'Acme Corporation',
            'amount' => 1250.50,
            'cheque_date' => '2026-07-20',
        ], 'Payee misspelled.');

        $requests->reject($admin, ChequeUpdateRequest::find($request->id), 'Not enough detail.');

        $this->assertSame('rejected', $staff->notifications()->latest()->first()->data['kind']);
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

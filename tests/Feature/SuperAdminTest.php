<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Super Admin — the admin in charge of checking cheque drafts. Has every admin power, and
 * only a Super Admin can grant, change or remove the role.
 */
class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $role): array
    {
        return ['name' => 'New Person', 'username' => 'newbie', 'password' => 'Secret-pass-123', 'role' => $role];
    }

    public function test_a_super_admin_has_admin_powers(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson('/api/v1/logs')->assertOk();
    }

    public function test_only_a_super_admin_can_grant_the_role(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/users', $this->payload('super_admin'))
            ->assertUnprocessable()->assertJsonValidationErrors(['role' => 'Only a Super Admin']);

        $staff = User::factory()->create();
        $this->putJson("/api/v1/users/{$staff->id}", ['role' => 'super_admin'])->assertUnprocessable();
        $this->assertSame(UserRole::Staff, $staff->fresh()->role);

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->postJson('/api/v1/users', $this->payload('super_admin'))->assertCreated();
        $this->assertSame(UserRole::SuperAdmin, User::where('username', 'newbie')->value('role'));
    }

    public function test_an_admin_cannot_change_or_delete_a_super_admin(): void
    {
        $super = User::factory()->superAdmin()->create();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/v1/users/{$super->id}", ['role' => 'staff'])
            ->assertUnprocessable()->assertJsonValidationErrors(['user' => 'Only a Super Admin']);
        $this->deleteJson("/api/v1/users/{$super->id}")->assertUnprocessable();

        $this->assertSame(UserRole::SuperAdmin, $super->fresh()->role);
    }

    public function test_the_first_super_admin_is_made_from_the_command_line(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'mark']);

        $this->artisan('users:make-super-admin', ['username' => 'mark'])->assertSuccessful();
        $this->assertSame(UserRole::SuperAdmin, $admin->fresh()->role);

        $this->artisan('users:make-super-admin', ['username' => 'nobody'])->assertFailed();
    }
}

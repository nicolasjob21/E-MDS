<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requests must look like they come from the SPA frontend for Sanctum to
     * treat them as stateful (session-backed).
     *
     * @param  array<string, mixed>  $data
     */
    private function frontendPost(string $uri, array $data)
    {
        return $this->withHeaders(['Origin' => 'http://localhost'])->postJson($uri, $data);
    }

    public function test_valid_credentials_log_in(): void
    {
        User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-password',
        ]);

        $this->frontendPost('/api/v1/login', [
            'username' => 'jdoe',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'jdoe');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('cheque_logs', ['username' => 'jdoe', 'action' => 'login']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe', 'password' => 'secret-password']);

        $this->frontendPost('/api/v1/login', [
            'username' => 'jdoe',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_log_in(): void
    {
        User::factory()->create([
            'username' => 'inactive',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->frontendPost('/api/v1/login', [
            'username' => 'inactive',
            'password' => 'secret-password',
        ])->assertStatus(422);

        $this->assertGuest();
    }
}

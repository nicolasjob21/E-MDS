<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The user menu's pages — Profile and Change Password — and the Sign Out it still carries.
 * Every request is made as the SPA makes it: same-origin, so Sanctum treats it as
 * session-backed.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = ['Origin' => 'http://localhost'];

    /** Sign in through the real login endpoint, as the SPA does, so a session exists. */
    private function signIn(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['username' => 'jdoe', 'password' => 'secret-password']);

        $this->withHeaders(self::ORIGIN)
            ->postJson('/api/v1/login', ['username' => 'jdoe', 'password' => 'secret-password'])
            ->assertOk();

        return $user;
    }

    /**
     * Make the next request resolve its user from the session again, as a fresh request from
     * the browser would. The test client keeps the guards (and the user they cached) between
     * requests, which would hide a session that no longer signs the user in.
     */
    private function nextRequestFromTheBrowser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** What the session keeps for a password hash: the guard's HMAC of it. */
    private function sessionHashOf(string $passwordHash): string
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $guard->hashPasswordForCookie($passwordHash);
    }

    // ---------------------------------------------------------------- the menu's data

    public function test_me_carries_what_the_menu_and_profile_page_show(): void
    {
        $this->signIn(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.username', 'jdoe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.role', 'staff');
    }

    // ---------------------------------------------------------------- sign out

    /** Sign Out is still the POST it always was — the menu item submits it, it is not a link. */
    public function test_sign_out_is_a_post_and_ends_the_session(): void
    {
        $this->signIn();
        $this->assertAuthenticated();

        // A plain link (GET) does not sign out.
        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/logout')->assertStatus(405);
        $this->nextRequestFromTheBrowser();
        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/me')->assertOk();

        $this->withHeaders(self::ORIGIN)->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Signed out.');

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertDatabaseHas('cheque_logs', ['username' => 'jdoe', 'action' => 'logout']);

        $this->nextRequestFromTheBrowser();
        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/me')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- profile

    public function test_a_user_can_edit_their_full_name_and_email(): void
    {
        $user = $this->signIn(['name' => 'Jane Doe', 'email' => null]);

        $this->withHeaders(self::ORIGIN)
            ->putJson('/api/v1/me', ['name' => 'Jane A. Doe', 'email' => 'jane.doe@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane A. Doe')
            ->assertJsonPath('data.email', 'jane.doe@example.com')
            // Read-only on the page, untouched by the save.
            ->assertJsonPath('data.username', 'jdoe')
            ->assertJsonPath('data.role', 'staff');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Jane A. Doe', 'email' => 'jane.doe@example.com']);
        $this->assertDatabaseHas('cheque_logs', ['username' => 'jdoe', 'action' => 'updated_profile']);
    }

    public function test_the_profile_never_changes_username_or_role(): void
    {
        $user = $this->signIn();

        $this->withHeaders(self::ORIGIN)
            ->putJson('/api/v1/me', ['name' => 'Jane', 'email' => null, 'username' => 'root', 'role' => 'admin'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('jdoe', $user->username);
        $this->assertSame('staff', $user->role->value);
        $this->assertSame('Jane', $user->name);
        $this->assertNull($user->email);
    }

    public function test_the_profile_is_validated(): void
    {
        $this->signIn();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me', ['name' => '', 'email' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me', ['name' => 'Jane', 'email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'already used']);
    }

    public function test_both_pages_require_login(): void
    {
        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me', ['name' => 'Anyone'])->assertUnauthorized();
        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'x', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- change password

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $user = $this->signIn();

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'not-my-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password' => 'The current password is incorrect.']);

        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));
        $this->assertDatabaseMissing('cheque_logs', ['action' => 'changed_password']);
    }

    /** The new password must be confirmed, pass the app's password rules, and differ from the old. */
    public function test_the_new_password_is_validated(): void
    {
        $this->signIn();

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'secret-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors(['password' => 'do not match']);

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'secret-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'secret-password',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['password' => 'different']);
    }

    public function test_a_user_stays_signed_in_after_changing_their_password(): void
    {
        $user = $this->signIn();
        // The first signed-in request stores the password hash the session middleware then
        // checks every request against; a mismatch signs the session out.
        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->sessionHashOf($user->password), session('password_hash_web'));

        $this->withHeaders(self::ORIGIN)->putJson('/api/v1/me/password', [
            'current_password' => 'secret-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Your password has been changed.');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertSame($this->sessionHashOf($user->password), session('password_hash_web'));

        // Still signed in: the same session goes on working, no re-login.
        $this->nextRequestFromTheBrowser();
        $this->withHeaders(self::ORIGIN)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.username', 'jdoe');
        $this->assertDatabaseHas('cheque_logs', ['username' => 'jdoe', 'action' => 'changed_password']);

        // And from now on only the new password opens the account.
        Auth::guard('web')->logout();
        $this->withHeaders(self::ORIGIN)->postJson('/api/v1/login', ['username' => 'jdoe', 'password' => 'secret-password'])->assertStatus(422);
        $this->withHeaders(self::ORIGIN)->postJson('/api/v1/login', ['username' => 'jdoe', 'password' => 'brand-new-password'])->assertOk();
    }
}

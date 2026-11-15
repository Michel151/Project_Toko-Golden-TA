<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UserAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $role = 'owner'): User
    {
        return User::create(['name' => $name, 'email' => $name.'@example.test', 'password' => 'password', 'role' => $role]);
    }

    public function test_owner_can_deactivate_and_reactivate_an_account_with_login_history(): void
    {
        $owner = $this->user('owner');
        $target = $this->user('target');
        $credentials = ['email' => $target->email, 'password' => 'password'];
        $index = route('master.users.index');
        $status = route('master.users.status', $target);

        $login = $this->post(route('login'), $credentials + ['remember' => 'on'])->assertRedirect('/dashboard');
        $cookieName = Auth::guard()->getRecallerName();
        $oldCookie = $login->getCookie($cookieName)->getValue();

        $this->actingAs($owner)->get($index)->assertOk()->assertSee('Nonaktifkan');
        $this->patch($status, ['active' => '0'])->assertRedirect($index);
        $this->get($index)->assertOk()->assertSee('Aktifkan Kembali');
        $this->assertFalse($target->fresh()->isActive());
        $this->assertDatabaseHas('audit_logs', ['user_id' => $target->id, 'action' => 'login']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'auditable_id' => $target->id,
            'description' => 'Menonaktifkan Pengguna '.$target->name,
        ]);

        $this->actingAs($target)->withSession(['auth.password_version.'.$target->id => 0])
            ->getJson(route('dashboard'))->assertUnauthorized()->assertJson(['message' => 'Akun Anda telah dinonaktifkan.']);
        $this->assertGuest();
        $this->post(route('login'), $credentials)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($owner)->patch($status, ['active' => '1'])->assertRedirect($index);
        $this->assertTrue($target->fresh()->isActive());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'auditable_id' => $target->id,
            'description' => 'Mengaktifkan kembali Pengguna '.$target->name,
        ]);

        $this->actingAs($target->fresh())->withSession(['auth.password_version.'.$target->id => 0])
            ->getJson(route('dashboard'))->assertUnauthorized();
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        Auth::forgetGuards();
        $this->withCookie($cookieName, $oldCookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login'), $credentials)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($target);
    }

    public function test_status_changes_require_an_active_owner_and_cannot_disable_the_acting_owner(): void
    {
        $owner = $this->user('owner');
        $cashier = $this->user('cashier', 'kasir');
        $inactiveOwner = $this->user('inactive');
        $inactiveOwner->forceFill(['deactivated_at' => now()])->save();
        $status = route('master.users.status', $owner);

        $this->patch($status, ['active' => '0'])->assertRedirect(route('login'));
        $this->actingAs($cashier)->patch($status, ['active' => '0'])->assertForbidden();
        $this->actingAs($inactiveOwner)->patchJson($status, ['active' => '0'])->assertUnauthorized();
        $this->actingAs($owner)->patch($status, ['active' => '0'])
            ->assertSessionHas('error', 'Anda tidak bisa menonaktifkan akun sendiri.');
        $this->assertTrue($owner->fresh()->isActive());
    }
}

<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Crypt, Hash, Validator};
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'kasir'): User
    {
        return User::create(['name'=>'Pengguna '.$role,'email'=>uniqid().'@test.com','password'=>'OldSecure12!','role'=>$role]);
    }

    private function newPassword(string $current = 'OldSecure12!'): array
    {
        return ['current_password'=>$current,'password'=>'NewSecure34!','password_confirmation'=>'NewSecure34!'];
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_ACCOUNT_PASSWORD_UI') !== '1') return;
        $directory = storage_path('framework/testing/account-password');
        if (!is_dir($directory)) mkdir($directory,0777,true);
        file_put_contents($directory.'/'.$name.'.html',$response->getContent());
    }

    public function test_all_roles_can_change_their_own_password_and_remain_logged_in(): void
    {
        foreach (['owner','kasir','staf_gudang'] as $role) {
            $user = $this->user($role);
            $page = $this->actingAs($user)->get(route('account.password.edit'))->assertOk()->assertSee('Password Saat Ini');
            if ($role === 'kasir') $this->capture('change',$page);
            $this->put(route('account.password.update'),$this->newPassword())->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
            $this->assertTrue(Hash::check('NewSecure34!',$user->fresh()->password));
            $this->assertFalse($user->fresh()->must_change_password);
            $this->assertSame(1,$user->fresh()->password_version);
            $this->get(route('dashboard'))->assertOk()->assertSee('Ubah Password');
        }
    }

    public function test_bad_current_password_weak_new_password_and_mismatch_are_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->putJson(route('account.password.update'),$this->newPassword('wrong'))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson(route('account.password.update'),['current_password'=>'OldSecure12!','password'=>'weak','password_confirmation'=>'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $data = $this->newPassword();
        $data['password_confirmation'] = 'different';
        $this->putJson(route('account.password.update'),$data)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson(route('account.password.update'),['current_password'=>'OldSecure12!','password'=>'OldSecure12!','password_confirmation'=>'OldSecure12!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('OldSecure12!',$user->fresh()->password));
        $this->assertSame(0,$user->fresh()->password_version);
    }

    public function test_account_update_cannot_target_another_user(): void
    {
        $user = $this->user();
        $other = $this->user('owner');
        $this->actingAs($user)->put(route('account.password.update'),$this->newPassword()+['user_id'=>$other->id,'id'=>$other->id,'role'=>'owner'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewSecure34!',$user->fresh()->password));
        $this->assertTrue(Hash::check('OldSecure12!',$other->fresh()->password));
        $this->assertSame('kasir',$user->fresh()->role);
    }

    public function test_owner_reset_creates_strong_temporary_password_and_displays_it_only_once(): void
    {
        $owner = $this->user('owner');
        $target = $this->user();
        $original = $target->password;
        $this->actingAs($owner)->post(route('master.users.reset-password',$target))->assertRedirect(route('master.users.reset-password-result'));
        $temporary = Crypt::decryptString(session('temporary_password'));
        $this->assertNotSame($temporary, session('temporary_password'));
        $this->assertTrue(Validator::make(['password'=>$temporary,'password_confirmation'=>$temporary],['password'=>PasswordPolicy::rules()])->passes());
        $this->assertTrue(Hash::check($temporary,$target->fresh()->password));
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertSame(1,$target->fresh()->password_version);
        $page = $this->get(route('master.users.reset-password-result'))->assertOk()->assertSee(e($temporary),false)
            ->assertHeader('Cache-Control','no-store, private');
        $this->capture('reset-result',$page);
        $this->get(route('master.users.reset-password-result'))->assertRedirect(route('master.users.index'));
        $this->assertStringNotContainsString($temporary,AuditLog::all()->toJson());
        $this->assertStringNotContainsString($target->fresh()->password,AuditLog::all()->toJson());
        $this->assertNotSame($original,$target->fresh()->password);
        $this->capture('users',$this->get(route('master.users.index'))->assertOk()->assertSee('Wajib ganti password'));
    }

    public function test_temporary_login_requires_change_before_reading_or_writing_other_menus(): void
    {
        $owner = $this->user('owner');
        $target = $this->user();
        $this->actingAs($owner)->post(route('master.users.reset-password',$target));
        $temporary = Crypt::decryptString(session('temporary_password'));
        $this->post(route('logout'));
        $this->post(route('login'),['email'=>$target->email,'password'=>$temporary])->assertRedirect(route('account.password.edit'));
        $this->get(route('dashboard'))->assertRedirect(route('account.password.edit'));
        $this->get(route('inventory.stock.index'))->assertRedirect(route('account.password.edit'));
        $this->postJson(route('sale.sales.store'),[])->assertForbidden()->assertJson(['password_change_required'=>true]);
        $page = $this->get(route('account.password.edit'))->assertOk()->assertSee('Ganti Password Sementara');
        $this->capture('forced',$page);
        $this->put(route('account.password.update'),$this->newPassword($temporary))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertFalse($target->fresh()->must_change_password);
        $this->assertFalse(Hash::check($temporary,$target->fresh()->password));
    }

    public function test_reset_revokes_existing_sessions_and_old_login_credentials(): void
    {
        $owner = $this->user('owner');
        $target = $this->user();
        $this->actingAs($owner)->post(route('master.users.reset-password',$target));
        $this->actingAs($target)->withSession(['auth.password_version.'.$target->id=>0])
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login'),['email'=>$target->email,'password'=>'OldSecure12!'])->assertSessionHasErrors('email');
    }

    public function test_self_change_revokes_old_sessions_and_remember_cookie(): void
    {
        $user = $this->user();
        $response = $this->post(route('login'),['email'=>$user->email,'password'=>'OldSecure12!','remember'=>'on'])->assertRedirect('/dashboard');
        $name = Auth::guard()->getRecallerName();
        $cookie = $response->getCookie($name);
        $this->put(route('account.password.update'),$this->newPassword())->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertOk();
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        Auth::forgetGuards();
        $this->withCookie($name,$cookie->getValue())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($user->fresh())->withSession(['auth.password_version.'.$user->id=>0])->getJson(route('account.password.edit'))->assertUnauthorized();
    }

    public function test_non_owners_cannot_reset_other_accounts_or_view_reset_result(): void
    {
        $target = $this->user('owner');
        foreach (['kasir','staf_gudang'] as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor)->post(route('master.users.reset-password',$target))->assertForbidden();
            $this->get(route('master.users.reset-password-result'))->assertForbidden();
        }
        $this->assertTrue(Hash::check('OldSecure12!',$target->fresh()->password));
    }

    public function test_owner_uses_personal_change_instead_of_self_reset_and_legacy_admin_edit_cannot_skip_forced_change(): void
    {
        $owner = $this->user('owner');
        $target = $this->user();
        $this->actingAs($owner)->post(route('master.users.reset-password',$owner))->assertUnprocessable();
        $this->put(route('master.users.update',$target),['name'=>$target->name,'email'=>$target->email,'role'=>'kasir',
            'password'=>'AdminReset12!','password_confirmation'=>'AdminReset12!'])->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertSame(1,$target->fresh()->password_version);
    }

    public function test_guests_cannot_use_password_routes(): void
    {
        $target = $this->user();
        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'),$this->newPassword())->assertRedirect(route('login'));
        $this->post(route('master.users.reset-password',$target))->assertRedirect(route('login'));
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($i=0;$i<6;$i++) $this->putJson(route('account.password.update'),$this->newPassword('wrong'))->assertUnprocessable();
        $this->putJson(route('account.password.update'),$this->newPassword('wrong'))->assertStatus(429);
        $this->assertTrue(Hash::check('OldSecure12!',$user->fresh()->password));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        User::create(['name'=>'Kasir Uji','email'=>'kasir-test@example.com','password'=>'password','role'=>'kasir']);
    }

    private function attempt(string $password = 'salah', string $email = 'kasir-test@example.com')
    {
        return $this->from('/login')->post('/login', compact('email','password'));
    }

    private function block(): void
    {
        for ($i=0;$i<5;$i++) $response=$this->attempt();
        $response->assertSessionHas('login_retry_until', now()->timestamp + 15);
        $this->assertGuest();
    }

    public function test_first_four_failures_are_immediate_and_fifth_starts_fifteen_seconds(): void
    {
        for ($i=0;$i<4;$i++) {
            $this->attempt()->assertSessionHasErrors(['email'=>'Email atau password salah.'])
                ->assertSessionMissing('login_retry_until');
        }
        $this->attempt()->assertSessionHas('login_retry_until', now()->timestamp + 15)
            ->assertSessionMissing('_old_input.password');
        $this->attempt('password')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cooldown_escalates_to_thirty_then_sixty_seconds_and_is_capped(): void
    {
        $this->block();
        foreach ([16=>30,31=>60,61=>60] as $wait=>$delay) {
            $this->travel($wait)->seconds();
            $this->attempt()->assertSessionHas('login_retry_until', now()->timestamp + $delay);
        }
    }

    public function test_blocked_requests_do_not_extend_the_wait(): void
    {
        $this->block();
        $until=now()->timestamp + 15;
        $this->travel(5)->seconds();
        $this->attempt()->assertSessionHas('login_retry_until', $until);
        $this->travel(11)->seconds();
        $this->attempt('password')->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_successful_login_clears_the_escalation(): void
    {
        $this->block();
        $this->travel(16)->seconds();
        $this->attempt('password')->assertRedirect('/dashboard');
        Auth::logout();
        for ($i=0;$i<4;$i++) $this->attempt()->assertSessionHasErrors(['email'=>'Email atau password salah.']);
        $this->attempt()->assertSessionHas('login_retry_until', now()->timestamp + 15);
    }

    public function test_another_account_on_the_same_network_can_login(): void
    {
        User::create(['name'=>'Kasir Lain','email'=>'lain@example.com','password'=>'password','role'=>'kasir']);
        $this->block();
        $this->attempt('password','lain@example.com')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::where('email','lain@example.com')->first());
    }

    public function test_same_account_from_another_ip_is_not_locked(): void
    {
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.1']);
        $this->block();
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.2']);
        $this->attempt('password')->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_failure_window_and_escalation_expire(): void
    {
        for ($i=0;$i<4;$i++) $this->attempt();
        $this->travel(61)->seconds();
        $this->attempt()->assertSessionHasErrors(['email'=>'Email atau password salah.']);
        for ($i=0;$i<4;$i++) $this->attempt();
        $this->travel(901)->seconds();
        $this->attempt()->assertSessionHasErrors(['email'=>'Email atau password salah.']);
    }

    public function test_email_casing_cannot_bypass_the_limit_and_unknown_accounts_are_also_limited(): void
    {
        for ($i=0;$i<4;$i++) $this->attempt('salah','unknown@example.com');
        $this->attempt('salah','UNKNOWN@example.com')->assertSessionHas('login_retry_until', now()->timestamp + 15);
    }

    public function test_login_form_has_accessible_password_toggle_and_never_repopulates_password(): void
    {
        $page=$this->get('/login')->assertOk()->assertSee('Tampilkan password')->assertSee('autocomplete="current-password"',false);
        if (getenv('CAPTURE_LOGIN_UI')==='1') {
            $directory=storage_path('framework/testing/login');
            if (!is_dir($directory)) mkdir($directory,0777,true);
            file_put_contents($directory.'/login.html',$page->getContent());
        }
        $this->block();
        $page=$this->get('/login')->assertOk()->assertSee('data-retry-until',false)->assertDontSee('value="salah"',false);
        if (getenv('CAPTURE_LOGIN_UI')==='1') file_put_contents($directory.'/cooldown.html',$page->getContent());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->get('/_test/remember', fn () => response()->json([
            'id' => Auth::id(),
            'remembered' => Auth::viaRemember(),
        ]));
    }

    private function login(bool $remember)
    {
        $user = User::create([
            'name' => 'Kasir Uji',
            'email' => 'remember-test@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            ...($remember ? ['remember' => 'on'] : []),
        ])->assertRedirect('/dashboard');

        return [$user, $response, Auth::guard()->getRecallerName()];
    }

    private function discardSession(): void
    {
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        Auth::forgetGuards();
    }

    public function test_checked_remember_restores_login_without_the_original_session(): void
    {
        [$user, $response, $name] = $this->login(true);
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGreaterThan(now()->addDay()->timestamp, $cookie->getExpiresTime());
        $this->assertNotEmpty($user->fresh()->remember_token);

        $this->discardSession();
        $this->withCookie($name, $cookie->getValue())->get('/_test/remember')
            ->assertOk()->assertJson(['id' => $user->id, 'remembered' => true]);
    }

    public function test_returning_remembered_user_is_redirected_from_login_to_dashboard(): void
    {
        [$user, $response, $name] = $this->login(true);
        $cookie = $response->getCookie($name);
        $this->discardSession();
        $this->withCookie($name, $cookie->getValue())->get('/login')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::viaRemember());
    }

    public function test_unchecked_remember_does_not_restore_login_after_session_is_lost(): void
    {
        [$user, $response, $name] = $this->login(false);
        $response->assertCookieMissing($name);
        $this->assertNull($user->fresh()->remember_token);
        $this->discardSession();
        $this->get('/_test/remember')->assertRedirect('/login');
    }

    public function test_logout_expires_remember_cookie_and_invalidates_its_old_token(): void
    {
        [$user, $response, $name] = $this->login(true);
        $cookie = $response->getCookie($name);
        $token = $user->fresh()->remember_token;
        $this->withCookie($name, $cookie->getValue())->post('/logout')
            ->assertRedirect('/login')->assertCookieExpired($name);
        $this->assertNotSame($token, $user->fresh()->remember_token);

        $this->discardSession();
        $this->withCookie($name, $cookie->getValue())->get('/_test/remember')
            ->assertRedirect('/login');
    }
}

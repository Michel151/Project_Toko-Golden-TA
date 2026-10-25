<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name'=>'Owner','email'=>'password-owner@test.com','password'=>'legacy','role'=>'owner']));
    }

    private function payload(string $password, string $confirmation = null): array
    {
        return ['name'=>'Pengguna','email'=>'password-user@test.com','role'=>'kasir',
            'password'=>$password,'password_confirmation'=>$confirmation ?? $password];
    }

    public function test_new_accounts_reject_each_missing_password_requirement(): void
    {
        foreach (['', 'Aa1!abc', 'abcdef12!', 'ABCDEF12!', 'Abcdefgh!', 'Abcdefg12'] as $password) {
            $this->postJson(route('master.users.store'),$this->payload($password))->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->assertDatabaseMissing('users',['email'=>'password-user@test.com']);
        }
    }

    public function test_strong_password_is_hashed_and_confirmation_is_required(): void
    {
        $this->postJson(route('master.users.store'),$this->payload('Secure12!','different'))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->post(route('master.users.store'),$this->payload('Secure12!'))->assertSessionHasNoErrors()->assertRedirect(route('master.users.index'));
        $user = User::where('email','password-user@test.com')->firstOrFail();
        $this->assertNotSame('Secure12!',$user->password);
        $this->assertTrue(Hash::check('Secure12!',$user->password));
    }

    public function test_password_changes_enforce_the_same_requirements_without_mutating_failed_updates(): void
    {
        $user = User::create(['name'=>'Pengguna','email'=>'password-user@test.com','password'=>'legacy','role'=>'kasir']);
        $original = $user->password;
        foreach (['Aa1!abc', 'abcdef12!', 'ABCDEF12!', 'Abcdefgh!', 'Abcdefg12'] as $password) {
            $this->putJson(route('master.users.update',$user),$this->payload($password))->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->assertSame($original,$user->fresh()->password);
        }
        $this->put(route('master.users.update',$user),$this->payload('NewSecure12!'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewSecure12!',$user->fresh()->password));
    }

    public function test_edit_without_a_new_password_preserves_the_existing_hash(): void
    {
        $user = User::create(['name'=>'Pengguna','email'=>'password-user@test.com','password'=>'legacy','role'=>'kasir']);
        $original = $user->password;
        $this->put(route('master.users.update',$user),$this->payload(''))->assertSessionHasNoErrors();
        $this->assertSame($original,$user->fresh()->password);
    }

    public function test_validation_explains_missing_requirements_in_indonesian(): void
    {
        $response = $this->postJson(route('master.users.store'),$this->payload('abcdefgh'));
        $response->assertUnprocessable();
        $this->assertContains('Password harus mengandung angka.',$response->json('errors.password'));
        $this->assertContains('Password harus mengandung huruf besar dan huruf kecil.',$response->json('errors.password'));
        $this->assertContains('Password harus mengandung simbol, misalnya !, @, atau #.',$response->json('errors.password'));
    }

    public function test_existing_accounts_can_still_login_with_their_current_password(): void
    {
        $this->post(route('logout'));
        $this->post(route('login'),['email'=>'password-owner@test.com','password'=>'legacy'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }
}

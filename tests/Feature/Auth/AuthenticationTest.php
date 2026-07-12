<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    /**
     * Login gagal SELALU menampilkan pesan jelas (bukan blank / bukan key mentah
     * "auth.failed"). Kredensial salah = password salah ATAU email tak terdaftar
     * (termasuk email yang sudah diganti lalu dipakai email lama).
     */
    public function test_failed_login_shows_clear_indonesian_message(): void
    {
        $user = User::factory()->create();

        // Password salah pada email yang ada.
        $wrongPw = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'salah-banget');
        $wrongPw->call('login');
        $this->assertStringContainsString('Email atau password salah', $wrongPw->errors()->first('form.email'));

        // Email tak terdaftar (simulasi email lama setelah diganti).
        $noEmail = Volt::test('pages.auth.login')
            ->set('form.email', 'email-lama-sudah-diganti@example.com')
            ->set('form.password', 'password');
        $noEmail->call('login');
        $this->assertStringContainsString('Email atau password salah', $noEmail->errors()->first('form.email'));

        $this->assertGuest();
    }

    /**
     * Akun nonaktif dengan kredensial BENAR: ditolak dengan pesan jelas,
     * tidak dibiarkan masuk (cegah blank/redirect gagal di halaman ter-proteksi).
     */
    public function test_inactive_account_is_rejected_with_clear_message(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login')->assertNoRedirect();

        $this->assertStringContainsString('dinonaktifkan', $component->errors()->first('form.email'));
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('layout.navigation');

        $component->call('logout');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
    }
}

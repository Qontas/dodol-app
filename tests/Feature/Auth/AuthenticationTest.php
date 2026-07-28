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

    /**
     * Spasi depan/belakang di email (umum dari autocomplete/keyboard HP) TAK BOLEH
     * bikin login gagal. Sebelum fix: " owner@x.id " → GAGAL. Sesudah: BERHASIL.
     */
    public function test_users_can_authenticate_with_surrounding_whitespace_in_email(): void
    {
        $user = User::factory()->create(['email' => 'operator.lapangan@example.com']);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', '  operator.lapangan@example.com  ')
            ->set('form.password', 'password');

        $component->call('login');

        $component->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    /**
     * Kapitalisasi email tak berpengaruh (collation _ci + normalisasi input). "OWNER@..."
     * tetap masuk. Termasuk kombinasi dengan spasi.
     */
    public function test_users_can_authenticate_with_different_email_capitalization(): void
    {
        $user = User::factory()->create(['email' => 'owner.bisnis@example.com']);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', '  OWNER.Bisnis@Example.COM ')
            ->set('form.password', 'password');

        $component->call('login');

        $component->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    /**
     * Password TETAP case-sensitive & apa adanya — normalisasi HANYA menyentuh email.
     * Password kapital-beda ditolak; password dengan spasi disengaja diterima persis.
     */
    public function test_password_remains_case_sensitive_and_is_not_trimmed(): void
    {
        // Password sengaja mengandung huruf besar dan spasi tepi.
        $user = User::factory()->create([
            'email' => 'kasir@example.com',
            'password' => bcrypt(' Rahasia Kuat '),
        ]);

        // (a) Kapitalisasi password beda → DITOLAK.
        $wrongCase = Volt::test('pages.auth.login')
            ->set('form.email', 'kasir@example.com')
            ->set('form.password', ' rahasia kuat ');
        $wrongCase->call('login')->assertHasErrors();
        $this->assertGuest();

        // (b) Password di-trim (spasi tepi hilang) → DITOLAK (spasi bagian dari password).
        $trimmed = Volt::test('pages.auth.login')
            ->set('form.email', 'kasir@example.com')
            ->set('form.password', 'Rahasia Kuat');
        $trimmed->call('login')->assertHasErrors();
        $this->assertGuest();

        // (c) Password persis (termasuk spasi & kapital) → BERHASIL.
        $exact = Volt::test('pages.auth.login')
            ->set('form.email', 'kasir@example.com')
            ->set('form.password', ' Rahasia Kuat ');
        $exact->call('login')->assertHasNoErrors();
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

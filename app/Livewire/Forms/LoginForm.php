<?php

namespace App\Livewire\Forms;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    // Default true: app internal operator → login persist (tak logout tiap tutup
    // app). Checkbox "Ingat Saya" tetap ada agar user bisa uncheck di perangkat publik.
    #[Validate('boolean')]
    public bool $remember = true;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Kredensial salah = email TAK TERDAFTAR (termasuk email yang sudah diganti,
        // mis. operator@ → madan@ lalu login pakai email lama) ATAU password salah.
        // Sengaja satu pesan untuk keduanya (tak membocorkan email mana yang ada).
        if (! Auth::attempt($this->only(['email', 'password']), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => 'Email atau password salah.',
            ]);
        }

        // Kredensial benar tapi akun dinonaktifkan → tolak dengan pesan jelas
        // (jangan biarkan masuk lalu blank/redirect gagal di halaman ter-proteksi).
        if (! Auth::user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'form.email' => 'Akun Anda dinonaktifkan. Hubungi admin.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => 'Terlalu banyak percobaan login. Coba lagi dalam '.$seconds.' detik.',
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}

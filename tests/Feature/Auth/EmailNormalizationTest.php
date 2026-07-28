<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Email SELALU tersimpan ternormalisasi (trim + lowercase) lewat mutator User::email,
 * apa pun jalur tulisnya. Ini menjaga login (yang juga menormalkan input) selalu ketemu,
 * dan mencegah email berspasi/berkapital ganjil di DB.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_trimmed_and_lowercased_on_create(): void
    {
        $user = User::factory()->create(['email' => '  Madan@X.id  ']);

        $this->assertSame('madan@x.id', $user->fresh()->email);
    }

    public function test_email_is_normalized_on_update(): void
    {
        $user = User::factory()->create(['email' => 'awal@example.com']);

        $user->update(['email' => ' BARU@Example.Com ']);

        $this->assertSame('baru@example.com', $user->fresh()->email);
    }

    /**
     * Unique constraint (_ci) tetap mencegah akun kembar beda-kapital — normalisasi
     * memperkuat, bukan menggantikan, proteksi ini.
     */
    public function test_case_only_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'kembar@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'KEMBAR@Example.com']);
    }
}

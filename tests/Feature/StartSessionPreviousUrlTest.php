<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "KEMBALI MENAMPILKAN sw.js" — regresi. Akar bug: tombol "Kembali ke
 * Riwayat Trip" (dulu url()->previous()) kadang mendarat di endpoint teknis non-halaman
 * (polling /csrf-token oleh pwa-token-refresh.blade.php, <img> foto kios) karena
 * StartSession bawaan Laravel menimpa session('_previous.url') untuk SETIAP GET,
 * termasuk GET non-halaman itu. /sw.js SENDIRI tak pernah bisa jadi sebabnya (file
 * statis, tak lewat Laravel sama sekali) — tapi tetap dites di sini sbg jaring pengaman
 * kalau suatu saat ada proxy/rewrite yang membuatnya lewat app.
 *
 * Fix: App\Http\Middleware\StartSession (dibind lewat AppServiceProvider::register())
 * skip storeCurrentUrl() untuk pola path tertentu — lihat EXCLUDED_PATTERNS di sana.
 */
class StartSessionPreviousUrlTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    public function test_csrf_token_poll_does_not_pollute_previous_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/trips')->assertOk();
        $this->assertSame('http://localhost/owner/trips', session('_previous.url'));

        // Dipanggil pwa-token-refresh.blade.php setiap pageshow/visibilitychange.
        $this->actingAs($owner)->getJson('/csrf-token')->assertOk();

        $this->assertSame(
            'http://localhost/owner/trips',
            session('_previous.url'),
            'polling /csrf-token tak boleh menimpa _previous.url (akar bug "Kembali" salah arah)'
        );
    }

    public function test_kiosk_photo_fetch_does_not_pollute_previous_url(): void
    {
        $owner = $this->owner();
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $this->actingAs($owner)->get('/owner/trips')->assertOk();
        $this->assertSame('http://localhost/owner/trips', session('_previous.url'));

        // Dimuat lewat <img> app-wide — tak bisa kirim header custom (ajax-marker),
        // jadi HARUS dikecualikan di level path, bukan header.
        $this->actingAs($owner)->get("/kiosks/{$kiosk->id}/photo");

        $this->assertSame(
            'http://localhost/owner/trips',
            session('_previous.url'),
            '<img> foto kios tak boleh menimpa _previous.url'
        );
    }

    public function test_sw_js_and_manifest_do_not_pollute_previous_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/trips')->assertOk();
        $this->assertSame('http://localhost/owner/trips', session('_previous.url'));

        $this->get('/sw.js');
        $this->get('/manifest.webmanifest');

        $this->assertSame('http://localhost/owner/trips', session('_previous.url'));
    }

    public function test_normal_page_navigation_still_updates_previous_url(): void
    {
        // Jaring pengaman: fix TIDAK membekukan _previous.url selamanya — navigasi
        // antar HALAMAN sungguhan tetap ter-update seperti perilaku bawaan Laravel.
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/trips')->assertOk();
        $this->assertSame('http://localhost/owner/trips', session('_previous.url'));

        $this->actingAs($owner)->get('/owner/dashboard')->assertOk();

        $this->assertSame('http://localhost/owner/dashboard', session('_previous.url'));
    }
}

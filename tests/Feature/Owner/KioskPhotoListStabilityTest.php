<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\ListKiosks;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bukti fix "foto kios kadang muncul kadang tidak" di list owner.
 *
 * Ronde 1: akarnya Tables\Columns\ImageColumn default checkFileExistence(true)
 * menembak live HeadObject ke R2 PER BARIS PER RENDER. Ronde 2: URL R2 langsung
 * (pub-*.r2.dev) kena net::ERR_CERT_COMMON_NAME_INVALID transien di level
 * koneksi browser (bukan app/server — dikonfirmasi curl 100% bersih & direproduksi
 * di banyak environment). FIX FINAL: proxy same-origin (KioskPhotoController)
 * lewat SATU accessor Kiosk::photo_url dipakai owner (ImageColumn::getStateUsing)
 * DAN operator (active-trip.blade.php) — tak ada lagi dua jalur beda, dan
 * browser tak pernah lagi konek langsung ke r2.dev.
 */
class KioskPhotoListStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_list_renders_proxy_route_url_regardless_of_disk_state(): void
    {
        // Fake disk, file di path ini SENGAJA TIDAK dibuat. List owner TIDAK
        // PERNAH cek keberadaan file saat render (getStateUsing cuma bangun URL
        // proxy dari accessor, tanpa exists()-check apa pun) — beda dari
        // KioskPhotoController yang BARU cek file saat foto BENERAN diminta
        // browser (async, tak memblokir render list).
        Storage::fake('s3');

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area Foto', 'is_active' => true, 'owner_id' => $owner->id]);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => 'kiosks/tidak-ada-di-disk.jpg',
        ]);

        $this->assertFalse(Storage::disk('s3')->exists('kiosks/tidak-ada-di-disk.jpg'));

        $this->actingAs($owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('owner'));

        // URL proxy same-origin ter-render, BUKAN URL R2 apa pun, BUKAN raw
        // photo_path (path internal storage tak lagi bocor ke HTML sama sekali).
        Livewire::test(ListKiosks::class)
            ->assertOk()
            ->assertSee(route('kiosks.photo', $kiosk), escape: false)
            ->assertDontSee('r2.dev', escape: false)
            ->assertDontSee('tidak-ada-di-disk.jpg', escape: false);
    }

    public function test_kiosk_without_photo_shows_placeholder_not_blank(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area Tanpa Foto', 'is_active' => true, 'owner_id' => $owner->id]);

        Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => null,
        ]);

        $this->actingAs($owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('owner'));

        Livewire::test(ListKiosks::class)
            ->assertOk()
            ->assertSee('data:image/svg+xml', escape: false);
    }
}

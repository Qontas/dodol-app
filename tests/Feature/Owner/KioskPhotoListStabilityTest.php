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
 * Bukti fix "foto kios kadang muncul kadang tidak" di list owner: akarnya
 * Tables\Columns\ImageColumn default checkFileExistence(true) menembak live
 * HeadObject ke R2 PER BARIS PER RENDER — gagal transient (jaringan Railway<->R2)
 * bikin baris kosong utk render itu saja. Operator TAK kena (jalur accessor
 * Kiosk::photo_url murni ->url(), 0 API call) — lihat KioskResource.php.
 *
 * checkFileExistence(false) menghilangkan panggilan itu SAMA SEKALI. Test ini
 * membuktikan itu langsung (bukan cuma baca kode): render list kios TIDAK
 * PERNAH memanggil Storage::exists(), walau kios punya photo_path.
 */
class KioskPhotoListStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_list_renders_photo_url_even_when_object_does_not_exist_on_disk(): void
    {
        // Fake disk, TAPI file di path ini SENGAJA TIDAK dibuat. Kalau
        // checkFileExistence masih aktif (default Filament), ImageColumn akan
        // panggil Storage::exists() -> false -> gambar disembunyikan (null).
        // Kalau fix checkFileExistence(false) benar-benar aktif, exists() tidak
        // pernah dicek -> URL tetap dirender walau file "tidak ada" di disk fake.
        // Ini bukti PERILAKU langsung (bukan cuma baca kode) bahwa live-check
        // sudah hilang dari jalur ini.
        Storage::fake('s3');

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area Foto', 'is_active' => true, 'owner_id' => $owner->id]);

        Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => 'kiosks/tidak-ada-di-disk.jpg',
        ]);

        $this->assertFalse(Storage::disk('s3')->exists('kiosks/tidak-ada-di-disk.jpg'));

        $this->actingAs($owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('owner'));

        Livewire::test(ListKiosks::class)
            ->assertOk()
            ->assertSee('kiosks/tidak-ada-di-disk.jpg', escape: false);
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

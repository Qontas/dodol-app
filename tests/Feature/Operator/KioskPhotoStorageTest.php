<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\CreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class KioskPhotoStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_url_accessor_uses_proxy_route_and_is_null_without_photo(): void
    {
        // Akar net::ERR_CERT_COMMON_NAME_INVALID transien ke R2 langsung (lihat
        // NEXT_SESSION.md) -> photo_url sekarang proxy same-origin
        // (KioskPhotoController), BUKAN URL disk/R2 langsung lagi.
        config(['app.media_disk' => 'public']);

        $kiosk = Kiosk::factory()->create(['photo_path' => null]);
        $this->assertNull($kiosk->photo_url);

        $kiosk->update(['photo_path' => 'kiosks/contoh.jpg']);
        $kiosk = $kiosk->fresh();
        $this->assertSame(
            route('kiosks.photo', $kiosk).'?v='.$kiosk->updated_at->timestamp,
            $kiosk->photo_url
        );
    }

    public function test_upload_stores_to_configured_media_disk(): void
    {
        // Disk media diarahkan ke 'public' (default lokal) — tidak crash tanpa env R2.
        config(['app.media_disk' => 'public']);
        Storage::fake('public');

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Foto', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Foto Disk')
            ->set('namaPemilik', 'Pak Sis')
            ->set('clusterId', $cluster->id)
            ->set('defaultQtyMika', 2)
            ->set('foto', UploadedFile::fake()->image('kios.jpg', 1600, 1200))
            ->call('saveKiosk');

        $kiosk = Kiosk::where('name', 'Kios Foto Disk')->firstOrFail();
        $this->assertNotNull($kiosk->photo_path);
        Storage::disk('public')->assertExists($kiosk->photo_path);
        // Accessor membentuk URL proxy same-origin, bukan URL disk langsung.
        $this->assertSame(
            route('kiosks.photo', $kiosk).'?v='.$kiosk->updated_at->timestamp,
            $kiosk->photo_url
        );
    }

    public function test_app_does_not_crash_when_media_disk_points_to_unconfigured_s3(): void
    {
        // Simulasi env R2 belum diisi: disk s3 ada di config tapi kredensial kosong.
        // store() ke fake s3 tetap jalan; ImageResizer kini jalan juga di disk cloud
        // (baca/tulis via Storage) — tidak boleh crash.
        config(['app.media_disk' => 's3']);
        Storage::fake('s3');

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster S3', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios S3')
            ->set('namaPemilik', 'Bu R2')
            ->set('clusterId', $cluster->id)
            ->set('defaultQtyMika', 2)
            ->set('foto', UploadedFile::fake()->image('kios.jpg', 1600, 1200))
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kios S3')->firstOrFail();
        $this->assertNotNull($kiosk->photo_path);
        Storage::disk('s3')->assertExists($kiosk->photo_path);
    }
}

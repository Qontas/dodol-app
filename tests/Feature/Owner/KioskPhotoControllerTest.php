<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * KioskPhotoController — proxy same-origin foto kios (R2 -> app -> browser).
 *
 * Menutup net::ERR_CERT_COMMON_NAME_INVALID transien ke r2.dev langsung
 * (dikonfirmasi bukan app/server, direproduksi di banyak environment browser)
 * DAN sekaligus menutup celah bocor: sebelumnya URL foto R2 (pub-*.r2.dev)
 * PUBLIC, siapa pun yang tahu URL-nya (walau bukan owner kios itu) bisa akses.
 * Sekarang route model binding Kiosk kena OwnerScope global otomatis —
 * isolasi tenant utk foto, bukan cuma utk data.
 */
class KioskPhotoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function kioskWithPhoto(User $owner, Cluster $cluster, string $name = 'Kios Foto'): Kiosk
    {
        Storage::fake('public');
        Storage::disk('public')->put('kiosks/contoh.jpg', UploadedFile::fake()->image('c.jpg')->get());

        return Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'name' => $name,
            'photo_path' => 'kiosks/contoh.jpg',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area', 'is_active' => true, 'owner_id' => $owner->id]);
        $kiosk = $this->kioskWithPhoto($owner, $cluster);

        $this->get(route('kiosks.photo', $kiosk))
            ->assertRedirect(route('login'));
    }

    public function test_owner_cannot_access_photo_of_another_owners_kiosk(): void
    {
        // 🔒 Tenant isolation: OwnerScope global pada Kiosk membuat route model
        // binding gagal menemukan record milik owner lain -> 404, bukan 403
        // (record dianggap tak pernah ada bagi user yang tak berhak, konsisten
        // dgn pola isolasi tenant lain di app ini).
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $clusterB = Cluster::create(['name' => 'Area B', 'is_active' => true, 'owner_id' => $ownerB->id]);
        $kioskB = $this->kioskWithPhoto($ownerB, $clusterB, 'Kios Milik B');

        $this->actingAs($ownerA);

        $this->get(route('kiosks.photo', $kioskB))
            ->assertNotFound();
    }

    public function test_operator_can_access_photo_of_their_own_tenant_kiosk(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area', 'is_active' => true, 'owner_id' => $owner->id]);
        $kiosk = $this->kioskWithPhoto($owner, $cluster);

        $this->actingAs($operator);

        $response = $this->get(route('kiosks.photo', $kiosk));

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_operator_cannot_access_photo_of_another_owners_kiosk(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $clusterB = Cluster::create(['name' => 'Area B', 'is_active' => true, 'owner_id' => $ownerB->id]);
        $kioskB = $this->kioskWithPhoto($ownerB, $clusterB, 'Kios Milik B');

        $this->actingAs($operatorA);

        $this->get(route('kiosks.photo', $kioskB))
            ->assertNotFound();
    }

    public function test_super_admin_bypasses_owner_scope_and_can_access_any_kiosk_photo(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area', 'is_active' => true, 'owner_id' => $owner->id]);
        $kiosk = $this->kioskWithPhoto($owner, $cluster);

        $this->actingAs($superAdmin);

        $this->get(route('kiosks.photo', $kiosk))->assertOk();
    }

    public function test_kiosk_without_photo_returns_404(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area', 'is_active' => true, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'photo_path' => null]);

        $this->actingAs($owner);

        $this->get(route('kiosks.photo', $kiosk))->assertNotFound();
    }

    public function test_returns_404_when_photo_path_set_but_file_missing_from_disk(): void
    {
        // Data lama/terhapus manual dari bucket: photo_path terisi tapi objeknya
        // tak ada. Harus 404 bersih (ditangkap try/catch), bukan 500.
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Area', 'is_active' => true, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => 'kiosks/tidak-pernah-ada.jpg',
        ]);

        $this->actingAs($owner);

        $this->get(route('kiosks.photo', $kiosk))->assertNotFound();
    }
}

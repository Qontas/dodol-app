<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator tambah/ganti foto kios dari lapangan (modal kunjungan).
 * Fokus: keberhasilan + jejak audit, GATE keamanan multi-tenant, kompres server.
 */
class ActiveTripPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** Buat owner + operator + cluster milik owner + trip aktif operator. */
    private function seedOperatorWithTrip(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster A', 'owner_id' => $owner->id]);
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        return [$owner, $operator, $cluster, $trip];
    }

    public function test_operator_can_add_photo_to_own_kiosk_with_audit_trail(): void
    {
        config(['app.media_disk' => 'public']);
        Storage::fake('public');

        [$owner, $operator, $cluster] = $this->seedOperatorWithTrip();
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => null,
            'notes' => null,
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('kioskPhoto', UploadedFile::fake()->image('kios.jpg', 800, 600))
            ->call('saveKioskPhoto')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $this->assertNotNull($kiosk->photo_path);
        Storage::disk('public')->assertExists($kiosk->photo_path);
        // Jejak audit: menyebut aksi "ditambah" + nama operator.
        $this->assertStringContainsString('Foto ditambah operator', $kiosk->notes);
        $this->assertStringContainsString($operator->name, $kiosk->notes);
    }

    public function test_operator_can_replace_existing_photo_and_audit_says_diganti(): void
    {
        config(['app.media_disk' => 'public']);
        Storage::fake('public');

        [$owner, $operator, $cluster] = $this->seedOperatorWithTrip();
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'photo_path' => 'kiosks/lama.jpg',
            'notes' => 'Catatan awal',
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('kioskPhoto', UploadedFile::fake()->image('baru.jpg', 800, 600))
            ->call('saveKioskPhoto')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $this->assertNotSame('kiosks/lama.jpg', $kiosk->photo_path);
        $this->assertNotNull($kiosk->photo_path);
        // Audit "diganti" ditambahkan tanpa menghapus catatan lama.
        $this->assertStringContainsString('Catatan awal', $kiosk->notes);
        $this->assertStringContainsString('Foto diganti operator', $kiosk->notes);
    }

    /**
     * 🔒 GATE KEAMANAN KRITIS: operator TIDAK boleh mengganti foto kios owner LAIN,
     * walau selectedKiosk di-set paksa ke kios lintas-owner.
     */
    public function test_operator_cannot_change_photo_of_other_owners_kiosk(): void
    {
        config(['app.media_disk' => 'public']);
        Storage::fake('public');

        [$owner, $operator] = $this->seedOperatorWithTrip();

        // Kios milik OWNER LAIN.
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherCluster = Cluster::create(['name' => 'Cluster Lain', 'owner_id' => $otherOwner->id]);
        $otherKiosk = Kiosk::factory()->create([
            'cluster_id' => $otherCluster->id,
            'photo_path' => 'kiosks/milik-owner-lain.jpg',
            'notes' => 'Punya orang lain',
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $otherKiosk->id) // set paksa selectedKiosk lintas-owner
            ->set('kioskPhoto', UploadedFile::fake()->image('serang.jpg', 800, 600))
            ->call('saveKioskPhoto')
            ->assertHasErrors('kioskPhoto');

        // Foto & catatan kios owner lain TIDAK berubah sama sekali.
        $otherKiosk->refresh();
        $this->assertSame('kiosks/milik-owner-lain.jpg', $otherKiosk->photo_path);
        $this->assertSame('Punya orang lain', $otherKiosk->notes);
    }

    /**
     * Kompres server (ImageResizer) memperkecil foto besar walau kompres browser tak
     * jalan (test = tanpa JS). Foto 2400x1800 → sisi terpanjang <= 1280.
     */
    public function test_server_side_resize_shrinks_large_photo(): void
    {
        config(['app.media_disk' => 'public']);
        Storage::fake('public');

        [$owner, $operator, $cluster] = $this->seedOperatorWithTrip();
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'photo_path' => null]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('kioskPhoto', UploadedFile::fake()->image('besar.jpg', 2400, 1800))
            ->call('saveKioskPhoto')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $bytes = Storage::disk('public')->get($kiosk->photo_path);
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info, 'File tersimpan harus gambar valid.');
        $this->assertLessThanOrEqual(1280, $info[0], 'Lebar harus <= 1280 setelah resize server.');
        $this->assertLessThanOrEqual(1280, $info[1], 'Tinggi harus <= 1280 setelah resize server.');
    }
}

<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\CreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CreateKioskDuringTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_kiosk_redirects_to_active_trip_if_exists(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $owner = User::find($operator->owner_id) ?: User::factory()->create(['role' => 'owner']);
        $operator->update(['owner_id' => $owner->id]);

        $cluster = Cluster::create(['name' => 'Cluster Test', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'ended_at' => null,
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->actingAs($operator);

        // Mount create kiosk page
        $this->get(route('operator.kiosks.create'))
            ->assertStatus(200)
            ->assertSee('Kembali ke Trip Aktif')
            ->assertDontSee('Kembali ke Dashboard');

        // Test save kiosk redirects back to active trip
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Baru Test')
            ->set('namaPemilik', 'Pak Joko')
            ->set('clusterId', $cluster->id)
            ->set('defaultQtyMika', 2)
            ->set('latitude', -6.200000)
            ->set('longitude', 106.816666)
            ->call('saveKiosk')
            ->assertRedirect(route('operator.trip.active', $trip->id));
    }

    public function test_create_kiosk_redirects_to_dashboard_if_no_active_trip(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $owner = User::find($operator->owner_id) ?: User::factory()->create(['role' => 'owner']);
        $operator->update(['owner_id' => $owner->id]);

        $cluster = Cluster::create(['name' => 'Cluster Test', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        // Mount create kiosk page
        $this->get(route('operator.kiosks.create'))
            ->assertStatus(200)
            ->assertSee('Kembali ke Dashboard')
            ->assertDontSee('Kembali ke Trip Aktif');

        // Test save kiosk redirects back to dashboard
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Baru Test 2')
            ->set('namaPemilik', 'Pak Budi')
            ->set('clusterId', $cluster->id)
            ->set('defaultQtyMika', 3)
            ->set('latitude', -6.200000)
            ->set('longitude', 106.816666)
            ->call('saveKiosk')
            ->assertRedirect(route('operator.dashboard'));
    }

    public function test_create_kiosk_stores_and_resizes_uploaded_photo(): void
    {
        Storage::fake('public');

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $owner = User::find($operator->owner_id) ?: User::factory()->create(['role' => 'owner']);
        $operator->update(['owner_id' => $owner->id]);

        $cluster = Cluster::create(['name' => 'Cluster Test', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        // Foto besar (1600x1200) harus diperkecil ke dalam 800x600.
        $foto = UploadedFile::fake()->image('kios.jpg', 1600, 1200);

        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Berfoto')
            ->set('namaPemilik', 'Bu Ani')
            ->set('clusterId', $cluster->id)
            ->set('defaultQtyMika', 2)
            ->set('latitude', -6.200000)
            ->set('longitude', 106.816666)
            ->set('foto', $foto)
            ->call('saveKiosk');

        $kiosk = Kiosk::where('name', 'Kios Berfoto')->firstOrFail();
        $this->assertNotNull($kiosk->photo_path);
        Storage::disk('public')->assertExists($kiosk->photo_path);

        // Resize GD: dimensi akhir tidak melebihi 800x600.
        [$w, $h] = getimagesize(Storage::disk('public')->path($kiosk->photo_path));
        $this->assertLessThanOrEqual(800, $w);
        $this->assertLessThanOrEqual(600, $h);
    }
}

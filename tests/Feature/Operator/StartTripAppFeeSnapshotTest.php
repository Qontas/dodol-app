<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TUGAS 4a/4c (BRIEF 23 Okt 2026) — PENGAMAN UTAMA pembekuan trips.app_fee_per_pack_
 * snapshot, pola SAMA PERSIS StartTripKomisiSnapshotTest tapi untuk fee aplikasi.
 * Beda dari komisi: tak ada varian "khusus operator" — satu tarif per owner, operator
 * selalu ikut tarif owner-nya (getEffectiveAppFeePerPackValue()).
 */
class StartTripAppFeeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_trip_freezes_owner_app_fee_rate_as_snapshot(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $this->actingAs($operator);
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 50)
            ->call('startTrip');

        $trip = Trip::where('operator_id', $operator->id)->latest('id')->first();
        $this->assertNotNull($trip);
        $this->assertEquals(300, $trip->app_fee_per_pack_snapshot);

        // Owner menaikkan tarif SETELAH trip dimulai — trip yang sudah berjalan
        // TIDAK boleh ikut berubah.
        $owner->update(['app_fee_per_pack' => 9999]);
        $trip->refresh();
        $this->assertEquals(300, (float) $trip->app_fee_per_pack_snapshot, 'Snapshot trip tidak ikut tarif baru owner.');

        unset($cluster);
    }

    public function test_owner_with_null_app_fee_snapshots_zero(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => null]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $this->actingAs($operator);
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 20)
            ->call('startTrip');

        $trip = Trip::where('operator_id', $operator->id)->latest('id')->first();
        $this->assertEquals(0.0, (float) $trip->app_fee_per_pack_snapshot, 'NULL tarif owner = Rp 0, bukan NULL ikut dipaksa 0 di snapshot.');
    }

    public function test_operator_always_follows_owner_rate_no_khusus_variant(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $this->actingAs($operatorA);
        Livewire::test(StartTrip::class)->set('tripBebas', true)->set('qtyCarried', 20)->call('startTrip');
        $tripA = Trip::where('operator_id', $operatorA->id)->latest('id')->first();

        $this->actingAs($operatorB);
        Livewire::test(StartTrip::class)->set('tripBebas', true)->set('qtyCarried', 20)->call('startTrip');
        $tripB = Trip::where('operator_id', $operatorB->id)->latest('id')->first();

        // Kedua operator owner yang sama → tarif fee aplikasi IDENTIK, tidak ada
        // "app_fee_per_pack_khusus" per operator.
        $this->assertEquals(300, $tripA->app_fee_per_pack_snapshot);
        $this->assertEquals(300, $tripB->app_fee_per_pack_snapshot);
    }
}

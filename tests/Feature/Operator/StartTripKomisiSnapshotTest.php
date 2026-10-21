<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF 2 Section 2+3 — PENGAMAN UTAMA "kode hitung". Membuktikan:
 * (a) trip baru membekukan tarif EFEKTIF operator saat DIMULAI (trips.komisi_
 *     per_mika_snapshot), bukan menunda ke tarif owner/operator yang berlaku
 *     nanti saat dilihat;
 * (b) sekali dibekukan, mengubah tarif owner SETELAHNYA tidak menggeser
 *     komisi_rian trip yang sudah dimulai (fix untuk bug yang dibuktikan di
 *     laporan investigasi poin 1 — sebelum fix ini test yang sama akan GAGAL);
 * (c) operator dengan tarif khusus vs operator yang ikut tarif default owner,
 *     di owner yang SAMA, menghasilkan komisi berbeda dengan angka konkret;
 * (d) TripAggregator (batch dashboard/laporan) membaca snapshot per-trip yang
 *     sama, bukan tarif live — jadi batch & accessor tidak bisa diam-diam
 *     menyimpang satu sama lain.
 */
class StartTripKomisiSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_trip_freezes_operator_effective_rate_as_snapshot(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id,
            'komisi_per_mika_khusus' => null, // ikut default owner
        ]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $this->actingAs($operator);
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 50)
            ->call('startTrip');

        $trip = Trip::where('operator_id', $operator->id)->latest('id')->first();
        $this->assertNotNull($trip);
        $this->assertEquals(1000, $trip->komisi_per_mika_snapshot);

        // Owner menaikkan tarif SETELAH trip dimulai — trip yang sudah berjalan
        // TIDAK boleh ikut berubah.
        $owner->update(['komisi_per_mika' => 5000]);
        $trip->refresh();
        $this->assertEquals(1000, (float) $trip->komisi_per_mika_snapshot, 'Snapshot trip tidak ikut tarif baru owner.');

        unset($cluster); // dipakai hanya agar owner punya minimal 1 cluster aktif (bukan dites langsung).
    }

    public function test_old_trip_commission_does_not_shift_when_owner_rate_changes_later(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 500]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        // Trip BULAN LALU, dibuat lewat kode produksi (StartTrip) supaya snapshot
        // benar-benar terisi tarif yang berlaku SAAT ITU (Rp500), bukan ditulis manual.
        $this->actingAs($operator);
        $this->travelTo(now()->subMonth());
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 20)
            ->call('startTrip');
        $trip = Trip::where('operator_id', $operator->id)->latest('id')->first();
        $this->assertEquals(500, $trip->komisi_per_mika_snapshot);

        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'qty_delivered' => 5, 'delivery_type' => 'consignment',
        ]);
        $trip->update(['ended_at' => now()]);
        $this->travelBack();

        $komisiSebelum = $trip->refresh()->komisi_rian;
        $this->assertEqualsWithDelta(2500, $komisiSebelum, 0.0001, '5 mika × Rp500 = Rp2.500.');

        // HARI INI owner menaikkan tarif ke Rp5.000 — bug lama (sebelum BRIEF 2) akan
        // membuat komisi trip bulan lalu ikut melonjak ke Rp25.000. Dengan snapshot,
        // seharusnya TETAP Rp2.500.
        $owner->update(['komisi_per_mika' => 5000]);

        $trip->refresh();
        $this->assertEqualsWithDelta(2500, $trip->komisi_rian, 0.0001, 'Komisi trip lama TIDAK BOLEH bergeser setelah tarif owner diganti.');

        // Batch (dashboard/laporan) harus sepakat dengan accessor — baca snapshot yang sama.
        $batch = TripAggregator::for([$trip->id], $owner->getKomisiPerMikaValue());
        $this->assertEqualsWithDelta(2500, $batch[$trip->id]['komisi'], 0.0001, 'TripAggregator ikut baca snapshot, bukan tarif live owner.');
    }

    public function test_operator_khusus_rate_vs_operator_default_rate_same_owner_concrete_numbers(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);

        $operatorKhusus = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id,
            'komisi_per_mika_khusus' => 800,
        ]);
        $operatorDefault = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id,
            'komisi_per_mika_khusus' => null,
        ]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kioskA = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $tripKhusus = $this->startAndFetchTrip($operatorKhusus);
        Delivery::factory()->create(['kiosk_id' => $kioskA->id, 'trip_id' => $tripKhusus->id, 'qty_delivered' => 5, 'delivery_type' => 'consignment']);
        $tripKhusus->update(['ended_at' => now()]);

        $tripDefault = $this->startAndFetchTrip($operatorDefault);
        Delivery::factory()->create(['kiosk_id' => $kioskB->id, 'trip_id' => $tripDefault->id, 'qty_delivered' => 5, 'delivery_type' => 'consignment']);
        $tripDefault->update(['ended_at' => now()]);

        $this->assertEquals(800, $tripKhusus->komisi_per_mika_snapshot);
        $this->assertEquals(1000, $tripDefault->komisi_per_mika_snapshot);

        // 5 mika × Rp800 (khusus) = Rp4.000; 5 mika × Rp1.000 (ikut owner) = Rp5.000.
        $this->assertEqualsWithDelta(4000, $tripKhusus->refresh()->komisi_rian, 0.0001);
        $this->assertEqualsWithDelta(5000, $tripDefault->refresh()->komisi_rian, 0.0001);
    }

    public function test_other_owners_unaffected_by_one_owners_rate_or_override_changes(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);

        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id]);

        $clusterA = Cluster::create(['name' => 'A', 'owner_id' => $ownerA->id]);
        $clusterB = Cluster::create(['name' => 'B', 'owner_id' => $ownerB->id]);
        $kioskA = Kiosk::factory()->create(['cluster_id' => $clusterA->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);

        $tripA = $this->startAndFetchTrip($operatorA);
        Delivery::factory()->create(['kiosk_id' => $kioskA->id, 'trip_id' => $tripA->id, 'qty_delivered' => 5, 'delivery_type' => 'consignment']);
        $tripA->update(['ended_at' => now()]);

        $tripB = $this->startAndFetchTrip($operatorB);
        Delivery::factory()->create(['kiosk_id' => $kioskB->id, 'trip_id' => $tripB->id, 'qty_delivered' => 5, 'delivery_type' => 'consignment']);
        $tripB->update(['ended_at' => now()]);

        // Owner A naik tarif + beri operator A tarif khusus — owner B & operator B
        // tidak tersentuh sama sekali (keduanya baca kolom milik user/trip masing-masing).
        $ownerA->update(['komisi_per_mika' => 9999]);
        $operatorA->update(['komisi_per_mika_khusus' => 1]);

        $this->assertEqualsWithDelta(5000, $tripB->refresh()->komisi_rian, 0.0001, 'Owner B tidak terpengaruh perubahan owner A.');
    }

    private function startAndFetchTrip(User $operator): Trip
    {
        $this->actingAs($operator);
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 20)
            ->call('startTrip');

        return Trip::where('operator_id', $operator->id)->latest('id')->first();
    }
}

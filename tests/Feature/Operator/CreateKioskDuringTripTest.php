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

    public function test_operator_cannot_save_kiosk_without_cluster(): void
    {
        // Area WAJIB: kios tanpa area = invisible di list owner (di-filter cluster.owner_id).
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        // 2 area → mount tidak auto-pilih → clusterId tetap null (operator lupa pilih).
        Cluster::create(['name' => 'Area Satu', 'owner_id' => $owner->id]);
        Cluster::create(['name' => 'Area Dua', 'owner_id' => $owner->id]);

        $this->actingAs($operator);
        Livewire::actingAs($operator);
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Tanpa Area')
            ->set('namaPemilik', 'Pak X')
            ->set('defaultQtyMika', 1)
            ->call('saveKiosk')
            ->assertHasErrors(['clusterId' => 'required']);

        $this->assertDatabaseMissing('kiosks', ['name' => 'Kios Tanpa Area']);
    }

    public function test_operator_cluster_dropdown_only_shows_own_owner_clusters(): void
    {
        // 🔒 Dropdown area operator TIDAK boleh bocor area owner lain.
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);

        $clusterA = Cluster::create(['name' => 'Area A', 'owner_id' => $ownerA->id]);
        $clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => $ownerB->id]);

        $this->actingAs($operatorA);
        Livewire::actingAs($operatorA);
        $ids = Livewire::test(CreateKiosk::class)->instance()->clusters->pluck('id');

        $this->assertTrue($ids->contains($clusterA->id), 'Operator harus lihat area owner-nya');
        $this->assertFalse($ids->contains($clusterB->id), 'Operator TIDAK boleh lihat area owner lain');
    }

    public function test_operator_cannot_assign_other_owners_cluster(): void
    {
        // Defense-in-depth: walau clusterId owner lain dipaksa dari klien, exists di-scope
        // owner → ditolak (bukan kesimpen ke tenant owner lain).
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => $ownerB->id]);

        $this->actingAs($operatorA);
        Livewire::actingAs($operatorA);
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Curang')
            ->set('namaPemilik', 'Pak Y')
            ->set('clusterId', $clusterB->id)
            ->set('defaultQtyMika', 1)
            ->call('saveKiosk')
            ->assertHasErrors(['clusterId']);

        $this->assertDatabaseMissing('kiosks', ['name' => 'Kios Curang']);
    }

    public function test_konsinyasi_dalam_trip_titipan_nempel_ke_trip_aktif(): void
    {
        // 🔴 TUTUP CELAH: operator daftar kedai konsinyasi DALAM trip aktif → titipan dicatat
        // ke TRIP AKTIF (bukan trip migrasi sentinel), sehingga stok trip berkurang & komisi
        // operator terhitung. Dulu titipan nempel ke trip migrasi 2000 (komisi 0, stok tak turun).
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area Konsinyasi', 'owner_id' => $owner->id]);
        $product = \App\Models\Product::factory()->create(['owner_id' => $owner->id]);
        \App\Models\ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id, 'qty_carried_total' => 50,
            'started_at' => now(), 'ended_at' => null, 'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->actingAs($operator);
        Livewire::actingAs($operator);
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Konsinyasi')
            ->set('namaPemilik', 'Bu Titip')
            ->set('clusterId', $cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 4)
            ->set('storeNote', 'Biasa titip 4 mika')
            ->call('saveKiosk');

        $kiosk = Kiosk::where('name', 'Kedai Konsinyasi')->firstOrFail();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertSame(4, (int) $kiosk->default_qty_mika);
        $this->assertSame('Biasa titip 4 mika', $kiosk->store_note);

        // Titipan 4 mika NEMPEL KE TRIP AKTIF (bukan trip migrasi).
        $pending = \App\Models\Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first();
        $this->assertNotNull($pending, 'Kedai konsinyasi harus punya titipan berjalan langsung.');
        $this->assertSame(4, (int) $pending->qty_delivered);
        $this->assertSame($trip->id, (int) $pending->trip_id, 'Titipan harus nempel ke TRIP AKTIF.');
        $this->assertSame('consignment', $pending->delivery_type);

        // Stok trip berkurang & komisi operator terhitung (4 × Rp1.000).
        $trip->refresh();
        $this->assertSame(4, $trip->getTotalDropReal());
        $this->assertEqualsWithDelta(4000.0, (float) $trip->komisi_rian, 0.01);
    }

    public function test_konsinyasi_tanpa_trip_aktif_jadi_booking_tanpa_titipan(): void
    {
        // Poin 3: tanpa trip aktif, operator TIDAK boleh menaruh dodol. Pilihan konsinyasi
        // jatuh ke BOOKING (identitas saja) → tak ada titipan, jatah NULL, bukan cash-only.
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area Tanpa Trip', 'owner_id' => $owner->id]);
        $product = \App\Models\Product::factory()->create(['owner_id' => $owner->id]);
        \App\Models\ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);

        $this->actingAs($operator);
        Livewire::actingAs($operator);

        // Tanpa trip → field jatah tersembunyi + arahan "Mulai trip dulu".
        Livewire::test(CreateKiosk::class)
            ->assertSet('hasActiveTrip', false)
            ->assertSee('Mulai trip dulu')
            ->set('namaKios', 'Kedai Booking Tanpa Trip')
            ->set('namaPemilik', 'Pak Nanti')
            ->set('clusterId', $cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 4)
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kedai Booking Tanpa Trip')->firstOrFail();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika, 'Tanpa trip → tak ada jatah (booking).');
        $this->assertTrue($kiosk->isBooking());
        $this->assertSame(0, \App\Models\Delivery::where('kiosk_id', $kiosk->id)->count(), 'Tanpa trip → tak ada titipan.');
    }

    public function test_booking_jenis_creates_identity_only(): void
    {
        // Jenis booking eksplisit (walau ADA trip): tetap identitas saja, tak ada titipan.
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area Booking', 'owner_id' => $owner->id]);
        $product = \App\Models\Product::factory()->create(['owner_id' => $owner->id]);
        \App\Models\ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id, 'qty_carried_total' => 50,
            'started_at' => now(), 'ended_at' => null, 'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->actingAs($operator);
        Livewire::actingAs($operator);
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Booking Eksplisit')
            ->set('namaPemilik', 'Bu Booking')
            ->set('clusterId', $cluster->id)
            ->set('jenisKedai', 'booking')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kedai Booking Eksplisit')->firstOrFail();
        $this->assertTrue($kiosk->isBooking());
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertSame(0, \App\Models\Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    public function test_cash_only_kiosk_has_no_titipan_and_no_jatah(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area Cash', 'owner_id' => $owner->id]);

        $this->actingAs($operator);
        Livewire::actingAs($operator);
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Cash')
            ->set('namaPemilik', 'Pak Tunai')
            ->set('clusterId', $cluster->id)
            ->set('jenisKedai', 'cash_only')
            ->set('storeNote', 'Cash-only, minta 5 konsisten')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kedai Cash')->firstOrFail();
        $this->assertTrue((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertSame('Cash-only, minta 5 konsisten', $kiosk->store_note);
        $this->assertSame(0, \App\Models\Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    public function test_create_kiosk_stores_and_resizes_uploaded_photo(): void
    {
        Storage::fake('public');

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $owner = User::find($operator->owner_id) ?: User::factory()->create(['role' => 'owner']);
        $operator->update(['owner_id' => $owner->id]);

        $cluster = Cluster::create(['name' => 'Cluster Test', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        // Foto besar (2400x1800) harus diperkecil ke dalam KioskPhoto::MAKS_SISI
        // (jaring pengaman server-side; kompres utama di browser). Dimensi dinaikkan
        // dari 2000x1500 karena batasnya kini 1600 — dengan 2000px foto masih akan
        // dikecilkan, tapi 2400 membuat tes ini tetap menguji hal yang sama tegasnya.
        $foto = UploadedFile::fake()->image('kios.jpg', 2400, 1800);

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

        // Resize GD: dimensi akhir tidak melebihi plafon (dari konstanta, bukan angka
        // mentah yang gampang menyimpang dari kompres browser & form owner).
        $maks = \App\Support\KioskPhoto::MAKS_SISI;
        [$w, $h] = getimagesize(Storage::disk('public')->path($kiosk->photo_path));
        $this->assertLessThanOrEqual($maks, $w);
        $this->assertLessThanOrEqual($maks, $h);
        $this->assertLessThan(2400, $w, 'Foto 2400px harus benar-benar diperkecil.');
    }
}

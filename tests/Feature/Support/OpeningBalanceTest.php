<?php

namespace Tests\Feature\Support;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use App\Support\OpeningBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Helper App\Support\OpeningBalance — dipakai jalur BUAT KEDAI (owner Filament radio
 * Konsinyasi + "titipan berjalan", operator create-kiosk di trip). Tombol Filament
 * "Set Saldo Awal" sudah DIHAPUS (13 Juli 2026, redundan), tapi helper-nya tetap
 * jantung penetapan titipan awal → test ini mengunci perilakunya, termasuk REGRESI
 * bug 500 (trip migrasi bentrok unique per-owner).
 */
class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area Saldo', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_creates_pending_consignment_delivery(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        $delivery = OpeningBalance::create($kiosk, 4);

        $this->assertNotNull($delivery);
        $this->assertSame('consignment', $delivery->delivery_type);
        $this->assertSame(4, (int) $delivery->qty_delivered);
        $this->assertNotNull(Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first());
    }

    public function test_is_idempotent_when_kiosk_already_has_pending(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->assertNotNull(OpeningBalance::create($kiosk, 3));
        $this->assertNull(OpeningBalance::create($kiosk, 5)); // tak menumpuk
        $this->assertSame(1, Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    /**
     * REGRESI 500: dulu trip migrasi memakai tanggal KEMARIN + number 1 → bentrok
     * dengan trip operasional owner #1 kemarin (unique owner_id,trip_date,number) →
     * UniqueConstraintViolation. Sekarang trip migrasi pakai tanggal sentinel masa
     * lampau (2000-01-01), tak pernah bentrok.
     */
    public function test_does_not_collide_when_owner_has_real_trip_yesterday(): void
    {
        $operator = User::factory()->create([
            'role' => 'operator', 'owner_id' => $this->owner->id, 'is_active' => true,
        ]);

        // Trip operasional NYATA kemarin, nomor 1 — kondisi produksi.
        Trip::create([
            'owner_id' => $this->owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today()->subDay()->toDateString(),
            'trip_number_of_day' => 1,
            'started_at' => today()->subDay(),
            'ended_at' => today()->subDay(),
            'qty_carried_total' => 10,
        ]);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        $delivery = OpeningBalance::create($kiosk, 4);

        $this->assertNotNull($delivery, 'OpeningBalance harus sukses walau ada trip nyata kemarin (tak 500).');
        $this->assertNotNull(Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first());
    }
}

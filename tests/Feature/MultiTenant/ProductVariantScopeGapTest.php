<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Scopes\OwnerScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 FIX (6 Juli 2026) — temuan residual audit isolasi: ProductVariant kini punya
 * OwnerScope global, sama seperti Kiosk (keduanya Level-2, owner lewat satu relasi
 * induk). Didaftarkan di ProductVariant::booted() (pola identik Kiosk::booted()),
 * dengan cabang TERPISAH di OwnerScope::apply() (instanceof ProductVariant →
 * whereHas('product', owner_id)) — TIDAK mengubah cabang Kiosk yang sudah ada.
 *
 * Sebelumnya (lihat riwayat file ini / commit sebelum fix): ProductVariant::find()/
 * ::all() TANPA scope manual BOCOR lintas-owner. Sekarang aman secure-by-default,
 * sama seperti 5 model Level-1 + Kiosk. Call site yang sebelumnya scope manual
 * (ProductVariantResource, ActiveTrip::resolveActiveVariant) DIBIARKAN (redundan
 * tapi aman — sama pola "dobel aman" yang dipertahankan di gerbang ActiveTrip Kiosk).
 */
class ProductVariantScopeGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_variant_now_globally_scoped_like_kiosk(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id, 'is_active' => true]);

        $clusterB = Cluster::create(['name' => 'B', 'owner_id' => $ownerB->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);

        $this->actingAs($ownerA);

        // Kiosk (Level-2 via cluster): sudah lama aman by-default.
        $this->assertNull(Kiosk::find($kioskB->id));

        // ProductVariant (Level-2 via product): SEKARANG SAMA amannya — query polos
        // TANPA scope manual TIDAK LAGI bocor lintas-owner.
        $this->assertNull(ProductVariant::find($variantB->id), 'ProductVariant::find() polos harus null utk owner lain sekarang.');
        $this->assertSame(0, ProductVariant::count(), 'ProductVariant::all() polos tidak boleh ikut hitung varian owner lain.');
    }

    public function test_super_admin_still_bypasses_product_variant_scope(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $productA = Product::factory()->create(['owner_id' => $ownerA->id]);
        $variantA = ProductVariant::factory()->create(['product_id' => $productA->id]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);

        $this->actingAs($super);

        // Scope baru TIDAK BOLEH nge-block super_admin — harus tetap lihat semua.
        $this->assertEqualsCanonicalizing(
            [$variantA->id, $variantB->id],
            ProductVariant::pluck('id')->all()
        );
    }

    public function test_unauthenticated_bypass_for_seeder_cli_still_works_for_product_variant(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);

        // Tanpa actingAs → konteks CLI/seeder/queue → bypass, sama seperti model lain.
        $this->assertCount(2, ProductVariant::all());
    }

    public function test_without_global_scope_escape_hatch_for_product_variant(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);

        $this->actingAs($ownerA);

        $this->assertNotNull(
            ProductVariant::withoutGlobalScope(OwnerScope::class)->find($variantB->id),
            'Escape hatch withoutGlobalScope harus tetap bisa menjangkau lintas-owner kalau disengaja.'
        );
    }

    public function test_product_variant_resource_edit_page_still_safe(): void
    {
        // Sekarang dilindungi GANDA: OwnerScope global BARU + getEloquentQuery()
        // manual (dibiarkan, redundan tapi aman) — tetap 404 utk owner lain.
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);

        $this->actingAs($ownerA)
            ->get("/owner-panel/product-variants/{$variantB->id}/edit")
            ->assertNotFound();
    }

    public function test_resolve_active_variant_in_active_trip_still_scoped_correctly(): void
    {
        // B1 (audit Langkah 2 residual) — pastikan fix call-site spesifik ini
        // masih berdiri walau sekarang dilindungi ganda oleh global scope juga.
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $clusterA = Cluster::create(['name' => 'A', 'owner_id' => $ownerA->id]);
        \App\Models\Trip::factory()->create([
            'operator_id' => $operatorA->id, 'owner_id' => $ownerA->id,
            'started_at' => now(), 'ended_at' => null, 'trip_date' => today()->format('Y-m-d'),
            'starting_cluster_id' => $clusterA->id, 'qty_carried_total' => 50,
        ]);
        $productA = Product::factory()->create(['owner_id' => $ownerA->id]);
        $variantA = ProductVariant::factory()->create(['product_id' => $productA->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);

        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        ProductVariant::factory()->create(['product_id' => $productB->id, 'is_active' => true, 'sale_price_per_pack' => 9999]);

        $cashKiosk = Kiosk::factory()->create(['cluster_id' => $clusterA->id, 'is_cash_only' => true, 'default_qty_mika' => 5]);

        $this->actingAs($operatorA);
        \Livewire\Livewire::test(\App\Livewire\Operator\ActiveTrip::class)
            ->call('openVisitModal', $cashKiosk->id)
            ->set('dropBaru', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $delivery = \App\Models\Delivery::where('kiosk_id', $cashKiosk->id)->first();
        $this->assertSame($variantA->id, $delivery->product_variant_id);
    }
}

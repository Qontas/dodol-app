<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔍 TEMUAN AUDIT ISOLASI (bukan regresi, residual arsitektur) — ProductVariant
 * TIDAK punya OwnerScope global, tidak seperti Kiosk (juga Level-2, owner lewat
 * satu relasi induk) yang SUDAH di-daftarkan di Kiosk::booted(). ProductVariant
 * cuma punya kolom product_id (owner lewat product.owner_id, 2 lompatan), dan
 * TIDAK di-treat khusus di OwnerScope::apply() (hanya `instanceof Kiosk` yang
 * di-handle, lihat app/Models/Scopes/OwnerScope.php:40).
 *
 * Akibatnya: `ProductVariant::find($id)` / `::all()` TANPA scope manual akan
 * BOCOR lintas-owner (dibuktikan test pertama di bawah) — beda dari Kiosk yang
 * aman secure-by-default. Call site YANG ADA SEKARANG semua sudah scope manual
 * dengan benar (dibuktikan test ke-2 & ke-3) sehingga TIDAK ADA lubang aktif di
 * UI produksi saat ini — tapi risikonya laten: developer baru yang lupa scope
 * manual di titik BARU akan bocor tanpa sadar (persis pola yang Langkah 2
 * seharusnya hilangkan).
 *
 * REKOMENDASI (belum dieksekusi, perlu sesi terpisah + review OwnerScope.php
 * yang security-sensitive): generalisasi OwnerScope::apply() menerima daftar
 * "Level-2 via single relation" (Kiosk→cluster, ProductVariant→product) alih-alih
 * hardcode instanceof Kiosk, ATAU daftarkan scope khusus di ProductVariant::booted()
 * meniru pola Kiosk.
 */
class ProductVariantScopeGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_variant_lacks_global_scope_unlike_kiosk(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id, 'is_active' => true]);

        $clusterB = \App\Models\Cluster::create(['name' => 'B', 'owner_id' => $ownerB->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);

        $this->actingAs($ownerA);

        // Kiosk (Level-2 via cluster): global scope aktif → NULL, aman by-default.
        $this->assertNull(Kiosk::find($kioskB->id));

        // ProductVariant (Level-2 via product, TANPA global scope): BOCOR kalau
        // di-query polos tanpa whereHas manual. Ini bukti temuan, bukan ekspektasi
        // yang diinginkan — kalau assertion ini berubah jadi assertNull di masa
        // depan berarti gap ini SUDAH ditutup (perbarui komentar test).
        $this->assertNotNull(ProductVariant::find($variantB->id), 'TEMUAN: ProductVariant::find() polos tembus lintas-owner (tak ada global scope).');
        $this->assertSame(1, ProductVariant::count(), 'TEMUAN: ProductVariant::all() polos ikut hitung varian owner lain.');
    }

    public function test_product_variant_resource_edit_page_still_safe_via_manual_scope(): void
    {
        // Walau model tak di-global-scope, ProductVariantResource::getEloquentQuery()
        // (manual whereHas('product', owner_id)) tetap menutup jalur HTTP nyata —
        // dibuktikan juga di OwnerWriteCrossTenantTest::test_owner_cannot_view_edit_page_of_other_owners_product_variant.
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);

        $this->actingAs($ownerA)
            ->get("/owner-panel/product-variants/{$variantB->id}/edit")
            ->assertNotFound();
    }

    public function test_resolve_active_variant_in_active_trip_still_scoped_manually(): void
    {
        // B1 (audit Langkah 2 residual) — pastikan fix call-site spesifik ini
        // masih berdiri: cash-sale operator A tak boleh pakai varian owner B.
        // (Duplikat ringan dari ResidualScopingTest::test_cash_sale_uses_only_own_owner_variant,
        // ditaruh di sini juga sebagai bukti kontras langsung dgn temuan gap di atas.)
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $clusterA = \App\Models\Cluster::create(['name' => 'A', 'owner_id' => $ownerA->id]);
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

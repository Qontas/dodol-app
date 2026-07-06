<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\KioskResource\Pages\EditKiosk;
use App\Filament\Resources\KioskResource\Pages\ListKiosks;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProcurementBatch;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 🔒 AUDIT ISOLASI — SKENARIO 2: WRITE cross-tenant di panel owner (Filament CRUD).
 *
 * Beda dari OwnerScopeTest (baca Eloquent polos): di sini kita buktikan boundary di
 * lapisan HTTP/Filament nyata — GET edit page by-ID langsung (simulasi attacker
 * ketik ID di address bar) dan mount Livewire EditRecord dengan ID paksa (simulasi
 * request di-tamper). Filament resolve record lewat getEloquentQuery() masing-masing
 * resource (scoped) + OwnerScope model-level (Kiosk) → record owner lain TIDAK
 * DITEMUKAN (404 / ModelNotFoundException), bukan 403 — intruder bahkan tak tahu
 * record itu ada. Pola sama dengan TripDeleteTest (custom controller route).
 */
class OwnerWriteCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Cluster $clusterB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => $this->ownerB->id]);
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_kiosk(): void
    {
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->clusterB->id]);

        $this->actingAs($this->ownerA)
            ->get("/owner-panel/kiosks/{$kioskB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_cluster(): void
    {
        $this->actingAs($this->ownerA)
            ->get("/owner-panel/clusters/{$this->clusterB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_supplier(): void
    {
        $supplierB = Supplier::factory()->create(['owner_id' => $this->ownerB->id]);

        $this->actingAs($this->ownerA)
            ->get("/owner-panel/suppliers/{$supplierB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_product(): void
    {
        $productB = Product::factory()->create(['owner_id' => $this->ownerB->id]);

        $this->actingAs($this->ownerA)
            ->get("/owner-panel/products/{$productB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_procurement_batch(): void
    {
        $supplierB = Supplier::factory()->create(['owner_id' => $this->ownerB->id]);
        $productB = Product::factory()->create(['owner_id' => $this->ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);
        $batchB = ProcurementBatch::factory()->create([
            'owner_id' => $this->ownerB->id,
            'supplier_id' => $supplierB->id,
            'product_variant_id' => $variantB->id,
        ]);

        $this->actingAs($this->ownerA)
            ->get("/owner-panel/procurement-batches/{$batchB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_view_edit_page_of_other_owners_product_variant(): void
    {
        // ProductVariant TIDAK punya OwnerScope global (lihat ProductVariantScopeGapTest) —
        // proteksi di sini murni dari ProductVariantResource::getEloquentQuery() manual scope.
        $productB = Product::factory()->create(['owner_id' => $this->ownerB->id]);
        $variantB = ProductVariant::factory()->create(['product_id' => $productB->id]);

        $this->actingAs($this->ownerA)
            ->get("/owner-panel/product-variants/{$variantB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_cannot_mount_edit_kiosk_with_forced_foreign_id(): void
    {
        // Simulasi request Livewire di-tamper langsung ke record owner B (bypass route
        // model binding normal) — mount() EditRecord tetap resolve lewat scoped query.
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->clusterB->id]);

        $this->actingAs($this->ownerA);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(EditKiosk::class, ['record' => $kioskB->id]);
    }

    public function test_owner_kiosk_list_never_contains_other_owners_kiosk(): void
    {
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->clusterB->id]);
        $clusterA = Cluster::create(['name' => 'Area A', 'owner_id' => $this->ownerA->id]);
        $kioskA = Kiosk::factory()->create(['cluster_id' => $clusterA->id]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($this->ownerA);

        Livewire::test(ListKiosks::class)
            ->assertCanSeeTableRecords([$kioskA])
            ->assertCanNotSeeTableRecords([$kioskB]);
    }

    public function test_owner_delete_table_action_rejected_for_other_owners_kiosk(): void
    {
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->clusterB->id]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($this->ownerA);

        // Record owner B tak ada di query tabel yang di-scope → Filament menolak
        // aksi delete untuk record itu (tak pernah "visible" bagi Livewire).
        $rejected = false;
        try {
            Livewire::test(ListKiosks::class)->callTableAction('delete', $kioskB->id);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'Delete action utk kios owner lain harus ditolak Filament (tak visible).');
        $this->assertDatabaseHas('kiosks', ['id' => $kioskB->id]);
    }

    public function test_super_admin_is_forbidden_from_owner_panel_entirely(): void
    {
        // Panel owner-panel authMiddleware HANYA izinkan role 'owner' (OwnerPanelProvider.php)
        // — super_admin punya panel/alur SENDIRI (/admin), bukan bypass ke Filament CRUD
        // owner-panel. "Super admin lihat semua" berlaku di dashboard widget admin panel
        // (query Eloquent langsung), BUKAN rute Filament resource owner-panel ini.
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->clusterB->id]);
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($super)
            ->get("/owner-panel/kiosks/{$kioskB->id}/edit")
            ->assertForbidden();
    }
}

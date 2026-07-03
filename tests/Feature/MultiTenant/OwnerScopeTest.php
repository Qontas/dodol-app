<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\ProcurementBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Scopes\OwnerScope;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔒 LANGKAH 2 — global read scope secure-by-default (OwnerScope).
 *
 * Bukti inti: query TANPA scope manual otomatis ter-filter ke owner aktif —
 * "lupa nambah where owner_id" = data ke-FILTER (aman), BUKAN bocor. Diverifikasi
 * per role (owner / operator / super_admin / tanpa-auth) + escape hatch + orphan
 * + no N+1 pada whereHas Kiosk.
 */
class OwnerScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Satu "dunia" tenant lengkap milik satu owner. */
    private function world(string $tag): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => "Area $tag", 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => "Kios $tag"]);
        $supplier = Supplier::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $batch = ProcurementBatch::factory()->create([
            'owner_id' => $owner->id, 'supplier_id' => $supplier->id, 'product_variant_id' => $variant->id,
        ]);
        $trip = Trip::factory()->create(['operator_id' => $operator->id, 'owner_id' => $owner->id]);

        return compact('owner', 'operator', 'cluster', 'kiosk', 'supplier', 'product', 'variant', 'batch', 'trip');
    }

    public function test_owner_sees_only_own_data_without_manual_scope(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');

        $this->actingAs($a['owner']);

        // Query POLOS (tanpa where owner_id) → hanya data owner A. Level-1:
        $this->assertEquals([$a['cluster']->id], Cluster::pluck('id')->all());
        $this->assertEquals([$a['supplier']->id], Supplier::pluck('id')->all());
        $this->assertEquals([$a['product']->id], Product::pluck('id')->all());
        $this->assertEquals([$a['batch']->id], ProcurementBatch::pluck('id')->all());
        $this->assertEquals([$a['trip']->id], Trip::pluck('id')->all());
        // Kiosk (Level-2 via cluster):
        $this->assertEquals([$a['kiosk']->id], Kiosk::pluck('id')->all());

        // INTI secure-by-default: akses id owner lain → NULL (bukan bocor).
        $this->assertNull(Kiosk::find($b['kiosk']->id));
        $this->assertNull(Cluster::find($b['cluster']->id));
        $this->assertNull(Trip::find($b['trip']->id));
        $this->assertNull(Supplier::find($b['supplier']->id));
    }

    public function test_operator_sees_its_owner_data(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');

        $this->actingAs($a['operator']); // operator owner A

        $this->assertEquals([$a['cluster']->id], Cluster::pluck('id')->all());
        $this->assertEquals([$a['kiosk']->id], Kiosk::pluck('id')->all());
        $this->assertEquals([$a['trip']->id], Trip::pluck('id')->all());
        $this->assertNull(Kiosk::find($b['kiosk']->id));
        $this->assertNull(Trip::find($b['trip']->id));
    }

    public function test_super_admin_bypass_sees_all(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($super);

        $this->assertEqualsCanonicalizing([$a['cluster']->id, $b['cluster']->id], Cluster::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a['kiosk']->id, $b['kiosk']->id], Kiosk::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a['trip']->id, $b['trip']->id], Trip::pluck('id')->all());
        $this->assertNotNull(Kiosk::find($b['kiosk']->id));
    }

    public function test_unauthenticated_bypass_for_seeder_cli_queue(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');

        // Tanpa actingAs → auth()->check() false → bypass → lihat semua (seeder/CLI/queue).
        $this->assertEqualsCanonicalizing([$a['cluster']->id, $b['cluster']->id], Cluster::pluck('id')->all());
        $this->assertCount(2, Kiosk::all());
        $this->assertCount(2, Trip::all());
    }

    public function test_without_global_scope_escape_hatch(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');

        $this->actingAs($a['owner']);

        // Pengecualian lintas-owner yang DISENGAJA (mis. tooling khusus).
        $this->assertNotNull(Kiosk::withoutGlobalScope(OwnerScope::class)->find($b['kiosk']->id));
        $this->assertCount(2, Cluster::withoutGlobalScope(OwnerScope::class)->get());
        $this->assertCount(2, Trip::withoutGlobalScope(OwnerScope::class)->get());
    }

    public function test_orphan_kiosk_without_cluster_is_filtered_when_authed(): void
    {
        $a = $this->world('A');
        $orphan = Kiosk::factory()->create(['cluster_id' => null, 'name' => 'Orphan']);

        // Tanpa auth → orphan kelihatan (data ada).
        $this->assertTrue(Kiosk::withoutGlobalScope(OwnerScope::class)->whereKey($orphan->id)->exists());

        // Sebagai owner A → orphan (tanpa cluster) TER-FILTER: whereHas('cluster') gagal.
        $this->actingAs($a['owner']);
        $this->assertNull(Kiosk::find($orphan->id));
        $this->assertNotContains($orphan->id, Kiosk::pluck('id')->all());
    }

    public function test_kiosk_scope_is_single_subquery_not_n_plus_1(): void
    {
        $a = $this->world('A');
        Kiosk::factory()->count(20)->create(['cluster_id' => $a['cluster']->id]);

        $this->actingAs($a['owner']);

        DB::enableQueryLog();
        $kiosks = Kiosk::get();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(21, $kiosks); // 1 dari world() + 20
        // whereHas = EXISTS inline dalam 1 statement → 1 query, BUKAN 1+N.
        $this->assertCount(1, $log, 'Scope Kiosk harus 1 query (EXISTS inline), bukan N+1.');
        $this->assertStringContainsStringIgnoringCase('exists', $log[0]['query']);
    }
}

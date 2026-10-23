<?php

namespace Tests\Feature\Admin;

use App\Filament\Widgets\AppFeeWidget;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TUGAS 4e+4f (BRIEF 23 Okt 2026) — AppFeeWidget HANYA boleh dilihat super admin
 * (4b/4f: owner/operator tidak pernah boleh lihat fee aplikasi), dan query-nya
 * harus KONSTAN berapa pun banyak owner (pola OwnerPerformanceTableQueryCountTest).
 */
class AppFeeWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_view_widget(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        $this->assertFalse(AppFeeWidget::canView());
    }

    public function test_operator_cannot_view_widget(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator);

        $this->assertFalse(AppFeeWidget::canView());
    }

    public function test_super_admin_can_view_widget(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin);

        $this->assertTrue(AppFeeWidget::canView());
    }

    public function test_widget_renders_concrete_numbers_and_total_row(): void
    {
        $owner = $this->seedOwnerWithTodayCashSale(rate: 300, qty: 5);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($superAdmin)->test(AppFeeWidget::class)
            ->assertOk()
            ->assertSee($owner->name)
            ->assertSee('Rp 300')   // tarif
            ->assertSee('Rp 1.500') // 5 mika × Rp300
            ->assertSee('Total');   // baris summary
    }

    public function test_query_count_for_one_vs_five_owners_stays_constant(): void
    {
        $this->seedOwnerWithTodayCashSale(rate: 300, qty: 5);
        $countOne = $this->renderAndCountQueries();

        for ($i = 0; $i < 4; $i++) {
            $this->seedOwnerWithTodayCashSale(rate: 300, qty: 5);
        }
        $countFive = $this->renderAndCountQueries();

        $this->assertLessThan(15, $countOne, "1 owner: {$countOne} query.");
        $this->assertLessThan(15, $countFive, "5 owner: {$countFive} query — indikasi N+1 per-baris owner.");
    }

    private function renderAndCountQueries(): int
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($superAdmin)->test(AppFeeWidget::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function seedOwnerWithTodayCashSale(float $rate, int $qty): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => $rate]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => $rate,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => $qty,
        ]);

        return $owner;
    }
}

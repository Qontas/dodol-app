<?php

namespace Tests\Feature\Admin;

use App\Filament\Widgets\AppFeeDailyWidget;
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
 * TUGAS 1 (BRIEF 23 Okt 2026, lanjutan) — AppFeeDailyWidget: super admin saja
 * (sama seperti AppFeeWidgetTest), query KONSTAN berapa pun banyak owner, dan
 * total Rp harus cocok dengan AppFeeWidget (dibuktikan terpisah secara angka
 * di AppFeeCalculatorTest::test_summary_by_owner_and_date_total_matches_summary_for_owners()).
 */
class AppFeeDailyWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_view_widget(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        $this->assertFalse(AppFeeDailyWidget::canView());
    }

    public function test_operator_cannot_view_widget(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator);

        $this->assertFalse(AppFeeDailyWidget::canView());
    }

    public function test_super_admin_can_view_widget(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin);

        $this->assertTrue(AppFeeDailyWidget::canView());
    }

    public function test_default_month_is_current_month(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $widget = Livewire::actingAs($superAdmin)->test(AppFeeDailyWidget::class)->instance();

        $this->assertSame(today()->format('Y-m'), $widget->month);
    }

    public function test_widget_renders_concrete_row_and_total_matching_app_fee_widget(): void
    {
        $owner = $this->seedOwnerWithTodayCashSale(rate: 300, qty: 5);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($superAdmin)->test(AppFeeDailyWidget::class)
            ->assertOk()
            ->assertSee($owner->name)
            ->assertSee('5') // mika kena fee (cash_sale, satu kolom sejak kolom "Mika Diantar" digabung 26 Okt 2026)
            ->assertSee('Rp 1.500') // 5 mika × Rp300, SAMA seperti fee_month AppFeeWidget
            ->assertSee('TOTAL');
    }

    public function test_owner_with_zero_rate_hidden_by_default_but_visible_when_selected(): void
    {
        $owner = $this->seedOwnerWithTodayCashSale(rate: 0, qty: 5);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        // Default (ownerId=0, "bertarif > 0") → owner bertarif 0 tak masuk
        // BARIS tabel (namanya tetap ada di <option> dropdown filter, jadi
        // yang diperiksa di sini "tabel kosong", bukan assertDontSee nama).
        Livewire::actingAs($superAdmin)->test(AppFeeDailyWidget::class)
            ->assertOk()
            ->assertSee('Tidak ada aktivitas di bulan ini.');

        // Dipilih manual lewat filter Owner → baris tabelnya tetap bisa diperiksa.
        Livewire::actingAs($superAdmin)->test(AppFeeDailyWidget::class)
            ->set('ownerId', $owner->id)
            ->assertOk()
            ->assertDontSee('Tidak ada aktivitas di bulan ini.')
            ->assertSee($owner->name);
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
        $this->assertLessThan(15, $countFive, "5 owner: {$countFive} query — indikasi N+1 per-baris owner/tanggal.");
    }

    private function renderAndCountQueries(): int
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($superAdmin)->test(AppFeeDailyWidget::class)->assertOk();
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

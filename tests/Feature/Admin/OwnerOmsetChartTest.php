<?php

namespace Tests\Feature\Admin;

use App\Filament\Widgets\OwnerOmsetChart;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF (23 Okt 2026) TUGAS 3 — "Tren Omset 30 Hari — Top 3 Owner" line → bar
 * chart. Angka TIDAK BOLEH berubah (query SUM(amount_paid) identik versi line
 * chart lama) — test ini mengunci itu, plus perilaku baru (state kosong,
 * label tanggal format Indonesia, akses super-admin-only tak berubah).
 */
class OwnerOmsetChartTest extends TestCase
{
    use RefreshDatabase;

    private function seedOwnerWithOmsetToday(float $amountPaid = 120000): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id, 'trip_date' => today()->format('Y-m-d'),
            'trip_number_of_day' => 1, 'started_at' => now(), 'ended_at' => now(),
        ]);

        $delivery = Delivery::factory()->create(['kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'qty_delivered' => 10, 'product_variant_id' => $variant->id, 'delivery_type' => 'consignment']);
        Settlement::create([
            // qty_sold 150 = 10 mika × 15 biji/mika (lihat Delivery::qty_biji_delivered)
            // — SettlementObserver menolak settlement yang biji-nya tak balance.
            'delivery_id' => $delivery->id, 'visit_date' => today()->format('Y-m-d'),
            'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => $amountPaid, 'amount_paid' => $amountPaid, 'status' => 'paid',
        ]);

        return $owner;
    }

    public function test_hidden_for_non_super_admin(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        $this->assertFalse(OwnerOmsetChart::canView());
    }

    public function test_bar_chart_type_and_omset_value_unchanged(): void
    {
        $owner = $this->seedOwnerWithOmsetToday(120000);
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $component = Livewire::actingAs($super)->test(OwnerOmsetChart::class);
        $component->assertOk();

        /** @var OwnerOmsetChart $widget */
        $widget = $component->instance();

        $this->assertSame('bar', $this->callProtected($widget, 'getType'));

        $data = $this->callProtected($widget, 'getData');
        $this->assertCount(1, $data['datasets']); // 1 owner berdata = 1 dataset
        $this->assertSame($owner->name, $data['datasets'][0]['label']);
        $this->assertSame(120000.0, end($data['datasets'][0]['data'])); // hari ini = elemen terakhir
        $this->assertCount(30, $data['labels']);
        $this->assertCount(30, $data['fullDates']);
        $this->assertFalse($widget->isEmpty());
    }

    public function test_label_tanggal_format_indonesia_tanpa_rotasi_perlu(): void
    {
        $this->seedOwnerWithOmsetToday();
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $widget = Livewire::actingAs($super)->test(OwnerOmsetChart::class)->instance();
        $data = $this->callProtected($widget, 'getData');

        // Label singkat "15 Okt" (bukan "15/10" versi lama) — label hari ini.
        $expectedShort = (int) today()->day.' '.['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][today()->month - 1];
        $this->assertSame($expectedShort, end($data['labels']));
    }

    public function test_state_kosong_saat_30_hari_nihil(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $widget = Livewire::actingAs($super)->test(OwnerOmsetChart::class)->instance();

        $this->assertTrue($widget->isEmpty());
    }

    /**
     * REGRESSION — bug nyata yang ketemu waktu verifikasi browser sungguhan
     * (BUKAN kelihatan dari test PHPUnit manapun, termasuk Livewire::test()
     * ->assertSee(): view kustom widget ini sempat punya <style> SEBELUM
     * root element <x-filament-widgets::widget>. Livewire butuh SATU root
     * element per komponen dan menempelkan wire:id/wire:snapshot ke elemen
     * PERTAMA yang ditemui di HTML hasil kompilasi Blade — waktu itu <style>,
     * widget-nya sendiri jadi "bukan root" di mata Livewire. Di PHPUnit ini
     * tak kelihatan sama sekali (html() tetap berisi seluruh markup apa adanya,
     * assertSee tetap lolos) — baru pecah di BROWSER SUNGGUHAN waktu Livewire
     * JS mem-morph konten lazy-load (x-intersect) ke elemen ber-wire:id itu:
     * targetnya <style>, bukan <div> widget, jadi section/canvas/judul hilang
     * total dari DOM. Dibuktikan & diperbaiki via verifikasi browser (screenshot
     * admin-widget-*.png) — lihat laporan akhir. Test ini pakai jaminan paling
     * murah & deterministik yang BISA dicek PHPUnit: <style> TIDAK BOLEH
     * muncul sebelum root <x-filament-widgets::widget> di source Blade.
     */
    public function test_style_tag_tidak_mendahului_root_element_livewire(): void
    {
        $view = file_get_contents(resource_path('views/filament/widgets/omset-bar-chart.blade.php'));
        // Buang komentar Blade {{-- --}} dulu — view ini SENGAJA menyebut kata
        // "<style>" di dalam teks komentar penjelasan bug, bukan tag sungguhan.
        $view = preg_replace('/\{\{--.*?--\}\}/s', '', $view);

        $rootPos = strpos($view, '<x-filament-widgets::widget');
        $stylePos = strpos($view, '<style');

        $this->assertNotFalse($rootPos, 'Root element <x-filament-widgets::widget> tidak ditemukan.');
        $this->assertNotFalse($stylePos, '<style> tidak ditemukan.');
        $this->assertGreaterThan(
            $rootPos,
            $stylePos,
            '<style> harus berada SETELAH (di dalam) root <x-filament-widgets::widget>, bukan sebelumnya — lihat komentar di atas test ini.'
        );
    }

    private function callProtected(object $obj, string $method): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invoke($obj);
    }
}

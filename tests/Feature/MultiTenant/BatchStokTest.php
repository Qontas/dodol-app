<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\ProcurementBatchResource\Pages\ListProcurementBatches;
use App\Models\Delivery;
use App\Models\ProcurementBatch;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BatchStokTest extends TestCase
{
    use RefreshDatabase;

    public function test_stok_tersisa_and_status_accessors(): void
    {
        $batch = ProcurementBatch::factory()->create(['qty_packs' => 100]);

        // Belum ada drop → stok penuh.
        $this->assertSame(100, $batch->stok_tersisa);
        $this->assertFalse($batch->is_habis);
        $this->assertFalse($batch->is_hampis_habis);

        // Drop 95 mika → sisa 10 → hampir habis.
        Delivery::factory()->create(['procurement_batch_id' => $batch->id, 'qty_delivered' => 90]);
        $this->assertSame(10, $batch->stok_tersisa);
        $this->assertTrue($batch->is_hampis_habis);
        $this->assertFalse($batch->is_habis);

        // Drop sampai 100 → habis.
        Delivery::factory()->create(['procurement_batch_id' => $batch->id, 'qty_delivered' => 10]);
        $this->assertSame(0, $batch->stok_tersisa);
        $this->assertTrue($batch->is_habis);
        $this->assertFalse($batch->is_hampis_habis);
    }

    public function test_unlinked_delivery_not_counted(): void
    {
        $batch = ProcurementBatch::factory()->create(['qty_packs' => 50]);
        // Delivery tanpa link batch (procurement_batch_id null) tidak dihitung.
        Delivery::factory()->create(['procurement_batch_id' => null, 'qty_delivered' => 30]);

        $this->assertSame(50, $batch->stok_tersisa);
    }

    public function test_owner_dashboard_shows_scoped_batch_stok(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $b1 = ProcurementBatch::factory()->create(['owner_id' => $owner->id, 'qty_packs' => 100]);
        $b2 = ProcurementBatch::factory()->create(['owner_id' => $owner->id, 'qty_packs' => 50]);
        Delivery::factory()->create(['procurement_batch_id' => $b2->id, 'qty_delivered' => 50]); // b2 habis

        // Batch owner lain — tidak boleh ikut.
        $other = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ProcurementBatch::factory()->create(['owner_id' => $other->id, 'qty_packs' => 30]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $this->assertCount(2, $response->viewData('batchStok'));
        $this->assertSame(100, $response->viewData('totalStokTersisa')); // 100 + 0
    }

    public function test_procurement_batch_list_page_renders_with_summary(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ProcurementBatch::factory()->create(['owner_id' => $owner->id, 'qty_packs' => 72]);

        // ProcurementBatchResource hidup di owner panel; set konteks panel agar
        // URL tabel ter-resolve ke route owner.
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($owner);
        Livewire::test(ListProcurementBatches::class)
            ->assertOk();
    }
}

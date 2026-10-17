<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator TIDAK BOLEH menerima info komisi/untung owner lewat payload Livewire
 * $tripSummary. Lihat plan §5-6: 6 key (hpp_estimasi, untung_kotor, komisi_reguler,
 * komisi_kios_baru, komisi_rian, untung_bersih_owner) sengaja dihapus dari
 * ActiveTrip::openEndTripModal() — ini mengunci regresinya.
 */
class TripSummaryHidesCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_summary_does_not_leak_owner_commission_fields()
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $operator = User::factory()->create([
            'role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true,
        ]);
        $cluster = Cluster::create(['name' => 'Cluster A', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 10,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openEndTripModal');

        $tripSummary = $component->get('tripSummary');

        foreach ([
            'hpp_estimasi',
            'untung_kotor',
            'komisi_reguler',
            'komisi_kios_baru',
            'komisi_rian',
            'untung_bersih_owner',
        ] as $leakedKey) {
            $this->assertArrayNotHasKey(
                $leakedKey,
                $tripSummary,
                "tripSummary tidak boleh memuat key '{$leakedKey}' (info komisi/untung owner)."
            );
        }

        // Key yang memang boleh dilihat operator tetap ada (bukan sekadar array kosong).
        $this->assertArrayHasKey('total_uang_diterima', $tripSummary);
        $this->assertArrayHasKey('total_mika_drop', $tripSummary);
    }
}

<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HppPerOwnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bangun trip owned by $owner dengan mika_terjual = 10
     * (1 delivery 10 mika di-settle penuh: qty_sold 150 biji / 15).
     */
    private function tripWithTenMikaSold(User $owner, User $operator): Trip
    {
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
        ]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 10,
        ]);

        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => 150,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 120000,
            'amount_paid' => 120000,
            'status' => 'paid',
        ]);

        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return $trip->fresh();
    }

    public function test_hpp_helper_defaults_to_9500(): void
    {
        $owner = User::factory()->make(['hpp_per_mika' => null]);
        $this->assertSame(9500.0, $owner->getHppPerMikaValue());

        $custom = User::factory()->make(['hpp_per_mika' => 8000]);
        $this->assertSame(8000.0, $custom->getHppPerMikaValue());
    }

    public function test_trip_report_uses_default_hpp(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $trip = $this->tripWithTenMikaSold($owner, $operator);

        $this->assertSame(10.0, $trip->mika_terjual);
        $this->assertSame(95000.0, $trip->hpp_estimasi);     // 10 * 9500
        $this->assertSame(25000.0, $trip->untung_kotor);     // 10 * (12000-9500)
        $this->assertSame(5000.0, $trip->komisi_reguler);    // 10 * (2500*0.2)
    }

    public function test_trip_report_uses_owner_custom_hpp(): void
    {
        $owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
            'hpp_per_mika' => 10000,
            'komisi_per_mika' => 400,
            'komisi_kios_baru_per_mika' => 800,
        ]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $trip = $this->tripWithTenMikaSold($owner, $operator);

        $this->assertSame(100000.0, $trip->hpp_estimasi);    // 10 * 10000
        $this->assertSame(20000.0, $trip->untung_kotor);     // 10 * (12000-10000)
        $this->assertSame(4000.0, $trip->komisi_reguler);    // 10 * 400
    }

    public function test_owner_can_update_own_hpp_via_settings(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500]);

        $this->actingAs($owner)->get('/owner/settings')->assertOk();

        $this->actingAs($owner)
            ->put('/owner/settings', [
                'hpp_per_mika' => 8800,
                'harga_mika' => 250,
                'komisi_per_mika' => 600,
                'komisi_kios_baru_per_mika' => 1200,
            ])
            ->assertRedirect(route('owner.settings'));

        $this->assertSame('8800.00', $owner->fresh()->hpp_per_mika);
        $this->assertSame('250.00', $owner->fresh()->harga_mika);
        $this->assertSame('600.00', $owner->fresh()->komisi_per_mika);
        $this->assertSame('1200.00', $owner->fresh()->komisi_kios_baru_per_mika);
    }

    public function test_operator_cannot_access_owner_settings(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator)->get('/owner/settings')->assertForbidden();
    }
}

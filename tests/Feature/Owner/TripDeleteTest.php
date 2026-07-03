<?php

namespace Tests\Feature\Owner;

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

class TripDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function tripWithData(User $owner): array
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'ended_at' => now(),
        ]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 2,
        ]);
        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => 30,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 24000,
            'amount_paid' => 24000,
        ]);
        $visit = KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return compact('trip', 'delivery', 'settlement', 'visit');
    }

    public function test_owner_can_delete_own_trip_and_cascades(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ['trip' => $trip, 'delivery' => $delivery, 'settlement' => $settlement, 'visit' => $visit] = $this->tripWithData($owner);

        $this->actingAs($owner)
            ->delete(route('owner.trips.destroy', $trip))
            ->assertRedirect();

        $this->assertDatabaseMissing('trips', ['id' => $trip->id]);
        $this->assertDatabaseMissing('deliveries', ['id' => $delivery->id]);
        $this->assertDatabaseMissing('settlements', ['id' => $settlement->id]);
        $this->assertDatabaseMissing('kiosk_visits', ['id' => $visit->id]);
    }

    public function test_owner_cannot_delete_other_owners_trip(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ['trip' => $trip] = $this->tripWithData($owner);

        $intruder = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // Secure-by-default (global OwnerScope): route-model binding tak menemukan
        // trip owner lain → 404 (bukan 403) — intruder bahkan tak tahu trip itu ada.
        // Guard controller abort(403) tetap ada sebagai defense-in-depth.
        $this->actingAs($intruder)
            ->delete(route('owner.trips.destroy', $trip))
            ->assertNotFound();

        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
    }

    public function test_super_admin_can_delete_any_trip(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ['trip' => $trip] = $this->tripWithData($owner);

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->delete(route('owner.trips.destroy', $trip))
            ->assertRedirect();

        $this->assertDatabaseMissing('trips', ['id' => $trip->id]);
    }

    public function test_operator_cannot_access_delete_route(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        ['trip' => $trip] = $this->tripWithData($owner);

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operator)
            ->delete(route('owner.trips.destroy', $trip))
            ->assertForbidden();

        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
    }
}

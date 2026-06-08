<?php

namespace Tests\Feature\MultiTenant;

use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TripNumberingTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusiness(string $opName): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'name' => $opName,
            'owner_id' => $owner->id,
        ]);
        $cluster = Cluster::create(['name' => "Cluster {$opName}", 'owner_id' => $owner->id]);

        return [$owner, $operator, $cluster];
    }

    private function startTrip(User $operator, Cluster $cluster): void
    {
        Livewire::actingAs($operator);
        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $cluster->id)
            ->set('qtyCarried', 60)
            ->call('startTrip');
    }

    public function test_two_owners_can_have_trip_number_one_same_day(): void
    {
        [$ownerA, $operatorA, $clusterA] = $this->makeBusiness('Andi');
        [$ownerB, $operatorB, $clusterB] = $this->makeBusiness('Budi');

        $this->startTrip($operatorA, $clusterA);
        $this->startTrip($operatorB, $clusterB);

        // Dua owner berbeda boleh sama-sama punya trip #1 di tanggal sama.
        $this->assertDatabaseHas('trips', [
            'owner_id' => $ownerA->id,
            'operator_id' => $operatorA->id,
            'trip_number_of_day' => 1,
        ]);
        $this->assertDatabaseHas('trips', [
            'owner_id' => $ownerB->id,
            'operator_id' => $operatorB->id,
            'trip_number_of_day' => 1,
        ]);
    }

    public function test_trip_number_increments_per_owner_across_operators(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator1 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $operator2 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Shared', 'owner_id' => $owner->id]);

        $this->startTrip($operator1, $cluster);
        $this->startTrip($operator2, $cluster);

        // Numbering per-owner: operator kedua dapat #2 (bukan #1 yang bentrok).
        $this->assertSame(1, (int) Trip::where('operator_id', $operator1->id)->value('trip_number_of_day'));
        $this->assertSame(2, (int) Trip::where('operator_id', $operator2->id)->value('trip_number_of_day'));
        $this->assertSame(2, Trip::where('owner_id', $owner->id)->count());
    }
}

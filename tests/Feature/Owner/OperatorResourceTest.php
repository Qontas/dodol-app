<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Filament\Resources\OperatorResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_only_see_their_own_operators_in_operator_resource()
    {
        $owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        $myOperator = User::factory()->create([
            'role' => 'operator',
            'owner_id' => $owner->id,
            'is_active' => true,
            'name' => 'Operator Saya',
        ]);

        $otherOwner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        $otherOperator = User::factory()->create([
            'role' => 'operator',
            'owner_id' => $otherOwner->id,
            'is_active' => true,
            'name' => 'Operator Lain',
        ]);

        $this->actingAs($owner);

        // Check if the getEloquentQuery filters properly
        $query = OperatorResource::getEloquentQuery();

        $this->assertTrue($query->where('id', $myOperator->id)->exists());
        
        // Refresh query
        $query2 = OperatorResource::getEloquentQuery();
        $this->assertFalse($query2->where('id', $otherOperator->id)->exists());
    }

    public function test_creating_operator_sets_role_and_owner_id_automatically()
    {
        $owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        Livewire::test(OperatorResource\Pages\ManageOperators::class)
            ->callAction('create', [
                'name' => 'Operator Baru',
                'email' => 'baru@cemilanqontas.id',
                'password' => 'password123',
                'commission_rate' => 0.25,
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'Operator Baru',
            'email' => 'baru@cemilanqontas.id',
            'role' => 'operator',
            'owner_id' => $owner->id,
            'commission_rate' => 0.25,
        ]);
    }
}

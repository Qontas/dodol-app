<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF 2 Section 3+4 — form "Komisi Khusus Operator" di UserResource (owner
 * membuat/edit operatornya DAN super admin via Edit User) + tampilan kolom
 * Kios/Operator/Komisi di daftar user.
 */
class OperatorKhususKomisiFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_operator_khusus_rate_via_create_form(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rian Khusus',
                'email' => 'rian.khusus@test.id',
                'password' => 'password123',
                'komisi_per_mika_khusus' => 800,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $operator = User::where('email', 'rian.khusus@test.id')->first();
        $this->assertNotNull($operator);
        $this->assertEquals('operator', $operator->role);
        $this->assertEquals($owner->id, $operator->owner_id);
        $this->assertEquals(800, $operator->komisi_per_mika_khusus);
        $this->assertEquals(800.0, $operator->getEffectiveKomisiPerMikaValue());
    }

    public function test_owner_can_leave_khusus_empty_to_follow_owner_default(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rian Default',
                'email' => 'rian.default@test.id',
                'password' => 'password123',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $operator = User::where('email', 'rian.default@test.id')->first();
        $this->assertNull($operator->komisi_per_mika_khusus);
        $this->assertEquals(1000.0, $operator->getEffectiveKomisiPerMikaValue());
    }

    public function test_super_admin_can_edit_operator_khusus_rate(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($super);

        Livewire::test(EditUser::class, ['record' => $operator->getKey()])
            ->fillForm(['komisi_per_mika_khusus' => 750])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(750, $operator->refresh()->komisi_per_mika_khusus);
    }

    public function test_user_list_shows_kios_operator_and_commission_suffix_columns(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'name' => 'Owner Satu', 'komisi_per_mika' => 1000]);
        $operatorKhusus = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Operator Khusus', 'owner_id' => $owner->id, 'komisi_per_mika_khusus' => 800]);
        $operatorDefault = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Operator Default', 'owner_id' => $owner->id, 'komisi_per_mika_khusus' => null]);
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($super);

        // Label baris owner "Bayar Rp X" diganti jadi "Default Rp X" (BRIEF Kolom Komisi &
        // Fee, 23 Okt 2026, lihat UserResourceKomisiFeeColumnTest) — angkanya
        // SAMA, cuma teksnya diluruskan (owner tak "membayar" dirinya sendiri).
        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertSee('Default Rp 1.000')         // owner row
            ->assertSee('Terima Rp 800 (khusus)')   // operator khusus row
            ->assertSee('Terima Rp 1.000 (ikut owner)'); // operator default row

        unset($operatorKhusus, $operatorDefault);
    }
}

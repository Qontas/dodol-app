<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 🔒 AUDIT ISOLASI — SKENARIO 1: super_admin bikin owner baru.
 *
 * Alur asli: panel /admin (App\Filament\Resources\UserResource, hanya di-discover
 * di AdminPanelProvider — lihat komentar "Super admin cukup mengelola akun (buat
 * owner baru, reset password)"), Pages\CreateUser. Role 'owner'/'super_admin' HANYA
 * muncul di dropdown kalau auth()->user()->isSuperAdmin() (UserResource.php:70-76).
 *
 * Owner baru HARUS mulai dengan data operasional KOSONG (0 kios/cluster/operator) —
 * bukan warisan data owner lain — karena OwnerScope memfilter berdasar id user yang
 * login, dan owner baru otomatis dapat id baru yang belum terhubung ke record apa pun.
 */
class SuperAdminOwnerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function seedForeignOwnerData(): User
    {
        $existingOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Milik Existing', 'owner_id' => $existingOwner->id]);
        Kiosk::factory()->count(5)->create(['cluster_id' => $cluster->id]);
        User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $existingOwner->id]);

        return $existingOwner;
    }

    public function test_super_admin_can_create_new_owner_via_user_resource(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->seedForeignOwnerData();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($super);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Owner Baru',
                'email' => 'ownerbaru@test.id',
                'password' => 'password123',
                'role' => 'owner',
                'hpp_per_mika' => 9500,
                'harga_mika' => 200,
                'komisi_per_mika' => 500,
                'komisi_kios_baru_per_mika' => 1000,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $newOwner = User::where('email', 'ownerbaru@test.id')->first();
        $this->assertNotNull($newOwner, 'Owner baru harus tercipta.');
        $this->assertSame('owner', $newOwner->role);
        $this->assertNull($newOwner->owner_id, 'Owner adalah root tenant, tidak terikat owner_id lain.');
    }

    public function test_new_owner_starts_with_zero_operational_data_not_leaking_existing_owner(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->seedForeignOwnerData(); // owner lain sudah punya 5 kios, 1 cluster, 1 operator.

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($super);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Owner Baru',
                'email' => 'ownerbaru2@test.id',
                'password' => 'password123',
                'role' => 'owner',
                'hpp_per_mika' => 9500,
                'harga_mika' => 200,
                'komisi_per_mika' => 500,
                'komisi_kios_baru_per_mika' => 1000,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $newOwner = User::where('email', 'ownerbaru2@test.id')->first();

        $this->actingAs($newOwner);
        $this->assertSame(0, Kiosk::count(), 'Owner baru TIDAK boleh lihat kios owner lain.');
        $this->assertSame(0, Cluster::count(), 'Owner baru TIDAK boleh lihat cluster owner lain.');
        $this->assertSame(0, User::where('role', 'operator')->where('owner_id', $newOwner->id)->count());

        // Dashboard owner baru pun harus render normal dengan angka nol (bukan error/crash).
        $this->get('/owner/dashboard')->assertOk();
    }

    public function test_owner_role_option_not_available_for_non_super_admin_in_create_user_form(): void
    {
        // Owner login TIDAK bisa membuat sesama owner via UserResource — mutateFormDataBeforeCreate
        // (CreateUser.php) paksa role=operator + owner_id=diri sendiri walau payload di-tamper.
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($ownerA);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Owner Tandingan',
                'email' => 'ownertandingan@test.id',
                'password' => 'password123',
                'role' => 'owner', // dipaksa lewat payload, bukan dari dropdown (opsi tak tersedia utk owner)
            ])
            ->call('create');

        $created = User::where('email', 'ownertandingan@test.id')->first();
        $this->assertNotNull($created);
        $this->assertSame('operator', $created->role, 'Owner tak boleh eskalasi diri/orang lain jadi owner baru.');
        $this->assertSame($ownerA->id, $created->owner_id);
    }

    public function test_operator_cannot_access_admin_panel_to_create_owner(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operator)->get('/admin/users/create')->assertForbidden();
    }
}

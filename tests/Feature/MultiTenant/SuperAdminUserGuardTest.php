<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔒 GUARD PANEL SUPERADMIN — cegah lockout, cegah hapus data, kunci akun sistem.
 */
class SuperAdminUserGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingSuper(): User
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($super);

        return $super;
    }

    private function ownerWithData(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Cluster A', 'owner_id' => $owner->id]);
        Kiosk::factory()->count(3)->create(['cluster_id' => $cluster->id]);

        return $owner;
    }

    // ===================== deletionBlockReason (unit) =====================

    public function test_deletion_block_reason_covers_superadmin_owner_operator(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->assertStringContainsString('Super Admin', (string) $super->deletionBlockReason());

        $owner = $this->ownerWithData();
        $this->assertStringContainsString('kios', (string) $owner->deletionBlockReason());

        // Operator dengan trip → diblokir.
        $opWithTrip = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $opWithTrip->id]);
        $this->assertStringContainsString('trip', (string) $opWithTrip->fresh()->deletionBlockReason());

        // Operator bersih → boleh dihapus (null).
        $cleanOp = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $this->assertNull($cleanOp->deletionBlockReason());
    }

    // ===================== Guard delete (lockout) =====================

    public function test_super_admin_cannot_bulk_delete_self(): void
    {
        $super = $this->actingSuper();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$super->getKey()]);

        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_super_admin_cannot_bulk_delete_another_super_admin(): void
    {
        $this->actingSuper();
        $otherSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$otherSuper->getKey()]);

        $this->assertDatabaseHas('users', ['id' => $otherSuper->id]);
    }

    // ===================== Tombol Delete disembunyikan/disabled di UI =====================

    public function test_delete_button_hidden_for_self_and_super_admin(): void
    {
        $super = $this->actingSuper();
        $otherSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $super)       // akun sendiri → tombol hilang
            ->assertTableActionHidden('delete', $otherSuper); // super lain → tombol hilang
    }

    public function test_delete_button_disabled_for_owner_and_operator_with_data(): void
    {
        $this->actingSuper();
        $owner = $this->ownerWithData();
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('delete', $owner)
            ->assertTableActionDisabled('delete', $owner)
            ->assertTableActionVisible('delete', $operator)
            ->assertTableActionDisabled('delete', $operator);

        // Guard server-side tetap menahan andai UI di-bypass (bulk).
        Livewire::test(ListUsers::class)->callTableBulkAction('delete', [$owner->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_delete_button_enabled_and_works_for_clean_operator(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('delete', $operator)
            ->assertTableActionEnabled('delete', $operator)
            ->callTableAction('delete', $operator->getKey());

        $this->assertDatabaseMissing('users', ['id' => $operator->id]);
    }

    // ===================== Guard self-deactivate (form) =====================

    public function test_super_admin_cannot_deactivate_self_via_edit(): void
    {
        $super = $this->actingSuper();

        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->fillForm(['is_active' => false, 'role' => 'operator'])
            ->call('save');

        $fresh = $super->fresh();
        $this->assertTrue($fresh->is_active, 'Superadmin tak boleh menonaktifkan dirinya sendiri.');
        $this->assertSame('super_admin', $fresh->role, 'Superadmin tak boleh menurunkan role dirinya sendiri.');
    }

    // ===================== Super Admin tunggal =====================

    public function test_another_super_admin_exists_helper(): void
    {
        $super = $this->actingSuper();

        $this->assertTrue(User::anotherSuperAdminExists(), 'Sudah ada satu super admin.');
        $this->assertFalse(
            User::anotherSuperAdminExists($super->getKey()),
            'Dikecualikan dirinya sendiri → tak ada super LAIN.'
        );
    }

    public function test_cannot_create_second_super_admin_via_resource(): void
    {
        $this->actingSuper(); // sistem sudah punya 1 super_admin

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Super Kedua',
                'email' => 'super2@test.id',
                'password' => 'password123',
                'role' => 'super_admin',
                'hpp_per_mika' => 9500,
                'harga_mika' => 200,
                'komisi_per_mika' => 500,
                'komisi_kios_baru_per_mika' => 1000,
                'is_active' => true,
            ])
            ->call('create');

        // Apapun jalurnya (opsi form disembunyikan / guard server halt) → tetap 1 super.
        $this->assertSame(1, User::where('role', 'super_admin')->count(), 'Hanya boleh satu Super Admin.');
    }

    public function test_cannot_promote_user_to_super_admin_via_edit(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->fillForm(['role' => 'super_admin'])
            ->call('save');

        $this->assertSame('owner', $owner->fresh()->role, 'Owner tak boleh dipromosikan jadi super admin kedua.');
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    // ===================== Owner nonaktif → operator terkunci =====================

    public function test_operator_blocked_from_login_when_owner_inactive(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => false]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $operator->email)
            ->set('form.password', 'password');
        $component->call('login')->assertNoRedirect();

        $this->assertStringContainsString('owner', $component->errors()->first('form.email'));
        $this->assertGuest();
    }

    public function test_operator_login_restored_when_owner_reactivated(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => false]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        // Owner diaktifkan lagi → operator (data tak diubah) bisa login normal.
        $owner->update(['is_active' => true]);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $operator->email)
            ->set('form.password', 'password');
        $component->call('login')->assertHasNoErrors();

        $this->assertAuthenticated();
    }

    // ===================== Akun sistem tersembunyi =====================

    public function test_system_account_hidden_and_not_deletable(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $system = User::factory()->create([
            'role' => 'operator',
            'is_active' => false,
            'owner_id' => $owner->id,
            'email' => 'operator.migrasi.owner'.$owner->id.'@cemilanqontas.id',
        ]);

        $this->assertTrue($system->isSystemAccount());
        $this->assertFalse(
            UserResource::getEloquentQuery()->whereKey($system->id)->exists(),
            'Akun sistem harus tersembunyi dari panel.'
        );
        $this->assertStringContainsString('sistem', (string) $system->deletionBlockReason());
    }
}

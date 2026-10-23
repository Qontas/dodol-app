<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF (23 Okt 2026) TUGAS 2 — kolom "Owner" + filter Owner di Manajemen User,
 * dan email digabung sebagai description di bawah Nama (bukan kolom terpisah)
 * supaya tabel tak terlalu lebar di HP. Lihat UserResource::table().
 */
class UserResourceOwnerColumnTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($super);

        return $super;
    }

    public function test_kolom_owner_tampilkan_nama_owner_untuk_baris_operator(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Kualistories', 'role' => 'owner']);
        $operator = User::factory()->create(['name' => 'Operator Budi', 'role' => 'operator', 'owner_id' => $owner->id]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('owner.name', $owner->name, $operator->getKey());
    }

    public function test_kolom_owner_placeholder_strip_untuk_baris_bukan_operator(): void
    {
        $super = $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Tanpa Atasan', 'role' => 'owner']);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('—') // placeholder owner.name untuk baris owner/super admin
            ->assertTableColumnStateSet('owner.name', null, $owner->getKey())
            ->assertTableColumnStateSet('owner.name', null, $super->getKey());
    }

    public function test_pencarian_nama_tetap_menemukan_user_lewat_email(): void
    {
        $this->actingAsSuperAdmin();
        User::factory()->create(['name' => 'Siti Rahma', 'email' => 'siti.unik@cemilanqontas.id', 'role' => 'owner']);
        User::factory()->create(['name' => 'Joko Prasetyo', 'email' => 'joko@cemilanqontas.id', 'role' => 'owner']);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->searchTable('siti.unik@cemilanqontas.id')
            ->assertCanSeeTableRecords(User::where('name', 'Siti Rahma')->get())
            ->assertCanNotSeeTableRecords(User::where('name', 'Joko Prasetyo')->get());
    }

    public function test_email_tidak_lagi_jadi_kolom_terpisah_tapi_tetap_tampil_sebagai_deskripsi(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Deskripsi', 'email' => 'owner.deskripsi@cemilanqontas.id', 'role' => 'owner']);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnDoesNotExist('email')
            ->assertTableColumnHasDescription('name', $owner->email, $owner);
    }

    public function test_filter_owner_hanya_menyaring_operator_milik_owner_terpilih(): void
    {
        $this->actingAsSuperAdmin();
        $ownerA = User::factory()->create(['name' => 'Owner A', 'role' => 'owner']);
        $ownerB = User::factory()->create(['name' => 'Owner B', 'role' => 'owner']);
        $operatorA = User::factory()->create(['name' => 'Operator Milik A', 'role' => 'operator', 'owner_id' => $ownerA->id]);
        $operatorB = User::factory()->create(['name' => 'Operator Milik B', 'role' => 'operator', 'owner_id' => $ownerB->id]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->filterTable('owner_id', $ownerA->id)
            ->assertCanSeeTableRecords([$operatorA])
            ->assertCanNotSeeTableRecords([$operatorB, $ownerA, $ownerB]);
    }

    public function test_filter_owner_tersembunyi_untuk_viewer_bukan_super_admin(): void
    {
        // UserResource hanya terdaftar di admin panel (super admin) — tapi
        // visible() filter ini bergantung role LOGIN, bukan panel aktif, jadi
        // tetap dikunci di sini sebagai dokumentasi/pengaman anti-regresi kalau
        // kelak resource ini ikut didaftarkan ke panel owner (cegah bocor daftar
        // nama owner lintas tenant ke viewer owner).
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableFilterHidden('owner_id');
    }
}

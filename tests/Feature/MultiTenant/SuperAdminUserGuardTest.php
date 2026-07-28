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
use Illuminate\Support\Facades\Hash;
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

    // ============ Tombol Delete di HALAMAN EDIT (bukan cuma tabel) ============
    //
    // 🚨 REGRESI YANG DITUTUP: header action `DeleteAction::make()` di EditUser dulu
    // POLOS — tanpa ->hidden/->disabled/->before. Guard delete semuanya hidup di action
    // TABEL, dan User tak punya event model `deleting`, jadi halaman Edit MELEWATI
    // seluruh guard: super admin benar-benar bisa menghapus dirinya sendiri dari sana
    // (lockout permanen), owner berdata meledak jadi FK 1451 mentah. Test di bawah
    // menguji lapisan UI DAN guard server-side-nya.

    public function test_edit_page_delete_button_hidden_for_self_and_super_admin(): void
    {
        $super = $this->actingSuper();
        $otherSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->assertActionHidden('delete');

        Livewire::test(EditUser::class, ['record' => $otherSuper->getKey()])
            ->assertActionHidden('delete');
    }

    /**
     * Lapisan kedua untuk kasus super admin / akun sendiri: tombolnya HILANG, jadi
     * callAction() pun tak bisa dipakai menirukan bypass (Filament menolak action
     * tersembunyi). Yang diuji di sini predikat yang dikonsultasi ->before() —
     * SAMA persis dengan yang dipakai tabel & bulk. Kalau ini null, guard runtime
     * ikut bolong walau tombolnya tak terlihat.
     */
    public function test_edit_page_delete_guard_reason_exists_for_self_and_super_admin(): void
    {
        $super = $this->actingSuper();
        $otherSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->assertStringContainsString(
            'sendiri',
            (string) UserResource::deleteBlockReason($super),
            'Akun sendiri harus punya alasan blokir yang dibaca ->before().'
        );
        $this->assertStringContainsString(
            'Super Admin',
            (string) UserResource::deleteBlockReason($otherSuper),
            'Super admin lain harus punya alasan blokir yang dibaca ->before().'
        );
    }

    public function test_edit_page_delete_button_hidden_for_user_with_data(): void
    {
        $this->actingSuper();
        $owner = $this->ownerWithData();
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertActionHidden('delete');

        Livewire::test(EditUser::class, ['record' => $operator->getKey()])
            ->assertActionHidden('delete');

        // Guard server-side tetap menahan andai UI di-bypass — dan menahannya dengan
        // pesan ramah, BUKAN QueryException FK 1451 (500) seperti sebelum fix.
        $this->assertStringContainsString('kios', (string) UserResource::deleteBlockReason($owner));
        $this->assertStringContainsString('trip', (string) UserResource::deleteBlockReason($operator->fresh()));

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $operator->id]);
    }

    /**
     * Tombol yang hilang tanpa penjelasan = owner menebak-nebak. Alasannya harus
     * TERBACA di halaman (bukan tooltip: tombol disabled Filament punya
     * pointer-events-none, jadi tooltipnya tak pernah muncul).
     */
    public function test_edit_page_shows_visible_reason_when_delete_unavailable(): void
    {
        $super = $this->actingSuper();
        $owner = $this->ownerWithData();
        $cleanOperator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);

        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->assertSee('Tidak bisa dihapus')
            ->assertSee('akun sendiri');

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertSee('Tidak bisa dihapus')
            ->assertSee('Nonaktifkan saja, jangan hapus.');

        // User bersih → tak ada peringatan menggantung.
        Livewire::test(EditUser::class, ['record' => $cleanOperator->getKey()])
            ->assertDontSee('Tidak bisa dihapus');
    }

    public function test_edit_page_delete_works_for_clean_operator(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);

        Livewire::test(EditUser::class, ['record' => $operator->getKey()])
            ->assertActionVisible('delete')
            ->assertActionEnabled('delete')
            ->callAction('delete');

        $this->assertDatabaseMissing('users', ['id' => $operator->id]);
    }

    // ============ Tooltip alasan di TABEL harus punya elemen pembungkus ============

    /**
     * 🚨 REGRESI YANG DITUTUP: tombol Delete yang disabled memakai ->tooltip() berisi
     * alasan, tapi tooltipnya TAK PERNAH muncul. Akarnya di Tippy, bukan CSS: `show()`
     * miliknya punya penjaga `!getCurrentTarget().hasAttribute("disabled")`, jadi
     * selama atribut `disabled` menempel Tippy menolak tampil — bahkan `.show()`
     * manual pun tak berefek. Melonggarkan `pointer-events` lewat CSS TIDAK cukup.
     *
     * Fix: tooltip dipindah ke elemen PEMBUNGKUS (view kustom
     * `filament.actions.link-action-tooltip-wrapped`). Test ini menjaga pembungkus itu
     * tetap ada untuk baris terblokir, dan TIDAK ada untuk baris yang tombolnya aktif
     * (kalau tidak, tombol aktif punya dua tooltip).
     */
    public function test_blocked_delete_button_renders_tooltip_on_wrapper_element(): void
    {
        $this->actingSuper();
        $owner = $this->ownerWithData();

        Livewire::test(ListUsers::class)
            ->assertSee('fi-dodol-disabled-action-wrapper', false)
            // Alasannya benar-benar ikut terkirim ke pembungkus, bukan pembungkus kosong.
            ->assertSee('Nonaktifkan saja, jangan hapus.', false);

        $this->assertNotNull($owner->deletionBlockReason());
    }

    public function test_enabled_delete_button_has_no_tooltip_wrapper(): void
    {
        $this->actingSuper();
        // Owner TANPA operator: begitu owner punya 1 operator pun, baris owner-nya
        // ikut terblokir ("punya … 1 operator"), jadi fixture "bersih" harus benar
        // -benar kosong — bukan sekadar tanpa kios/trip.
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->assertNull($owner->deletionBlockReason(), 'Fixture harus benar-benar bersih.');

        Livewire::test(ListUsers::class)
            ->assertDontSee('fi-dodol-disabled-action-wrapper', false);
    }

    // ===================== Form: komisi cuma untuk OWNER =====================

    public function test_tarif_komisi_hidden_for_super_admin_but_visible_for_owner(): void
    {
        $super = $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);

        // Super admin tidak mengantar dodol → tak punya tarif komisi.
        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->assertFormFieldIsHidden('komisi_per_mika')
            ->assertFormFieldIsHidden('komisi_kios_baru_per_mika')
            ->assertFormFieldIsHidden('hpp_per_mika')
            ->assertFormFieldIsHidden('harga_mika')
            // 'Owner' (atasan) juga tak relevan untuk super admin.
            ->assertFormFieldIsHidden('owner_id');

        // Owner = yang MENETAPKAN tarif → tetap tampil normal.
        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertFormFieldExists('komisi_per_mika')
            ->assertFormFieldExists('komisi_kios_baru_per_mika')
            ->assertFormFieldExists('hpp_per_mika')
            ->assertFormFieldExists('harga_mika');

        // Operator TIDAK menetapkan tarif (dia dibayar pakai tarif owner-nya).
        Livewire::test(EditUser::class, ['record' => $operator->getKey()])
            ->assertFormFieldIsHidden('komisi_per_mika')
            ->assertFormFieldIsHidden('komisi_kios_baru_per_mika');
    }

    public function test_editing_super_admin_without_tarif_fields_still_saves(): void
    {
        $super = $this->actingSuper();

        Livewire::test(EditUser::class, ['record' => $super->getKey()])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $super->fresh()->name);
    }

    // ===================== Password: kosong = JANGAN diubah =====================

    public function test_saving_with_empty_password_does_not_change_password(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $hashLama = $owner->password;

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->fillForm(['name' => 'Owner Ganti Nama', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $owner->fresh();
        $this->assertSame('Owner Ganti Nama', $fresh->name, 'Simpan memang jalan.');
        $this->assertSame($hashLama, $fresh->password, 'Password kosong TIDAK boleh menimpa password lama.');
    }

    public function test_saving_with_filled_password_does_change_password(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $hashLama = $owner->password;

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->fillForm(['password' => 'rahasiabaru123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $owner->fresh();
        $this->assertNotSame($hashLama, $fresh->password);
        $this->assertTrue(Hash::check('rahasiabaru123', $fresh->password), 'Password baru harus ter-hash & cocok.');
    }

    public function test_password_field_blocks_browser_autofill(): void
    {
        $this->actingSuper();
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // autocomplete="new-password" = satu-satunya yang menghentikan Chrome mengisi
        // field ini dengan password tersimpan. Tanpa itu, sekali klik Simpan menimpa
        // password dengan nilai yang owner tak pernah lihat.
        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertSee('autocomplete="new-password"', false);

        // Field password juga harus KOSONG saat halaman dibuka — hash lama TIDAK boleh
        // bocor ke form (User::$hidden menyembunyikannya dari fill). Kalau ini terisi,
        // "kosongkan kalau tak ingin mengubah" jadi mustahil dipatuhi.
        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertFormSet(['password' => null]);
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

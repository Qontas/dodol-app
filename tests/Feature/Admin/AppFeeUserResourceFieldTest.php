<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TUGAS 4b (BRIEF 23 Okt 2026) — field "Fee Aplikasi per Kemasan (Rp)" di
 * UserResource HANYA boleh dilihat & disimpan super admin.
 *
 * 🔴 Owner JUSTRU BISA membuka UserResource ini (untuk operatornya sendiri, lihat
 * koreksi docblock di UserResource::form()) — jadi pengaman field ini BUKAN
 * "owner tak pernah lewat sini", melainkan ->visible()/->dehydrated() isSuperAdmin()
 * di FIELD itu sendiri. Test "tampered role" di sini membuktikan itu: owner yang
 * (secara hipotetis, lewat payload ditempa) memaksa data.role jadi 'owner' TETAP
 * tidak bisa melihat/menyimpan field ini, karena gate-nya cek isSuperAdmin(), bukan
 * $get('role') semata.
 */
class AppFeeUserResourceFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_and_save_the_field_on_owner_record(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => null]);

        $this->actingAs($superAdmin);

        Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])
            ->assertFormFieldIsVisible('app_fee_per_pack')
            ->set('data.app_fee_per_pack', 300)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(300, $owner->fresh()->app_fee_per_pack);
    }

    public function test_field_is_hidden_for_super_admin_when_editing_non_owner_role(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $otherOwner->id]);

        $this->actingAs($superAdmin);

        // Section hanya visible kalau $get('role') === 'owner' — record operator
        // tidak pernah menampilkan field fee aplikasi, bahkan untuk super admin.
        Livewire::test(EditUser::class, ['record' => $operator->getRouteKey()])
            ->assertFormFieldIsHidden('app_fee_per_pack');
    }

    public function test_owner_editing_own_operator_never_sees_the_field_even_with_tampered_role(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $this->actingAs($owner);

        $component = Livewire::test(EditUser::class, ['record' => $operator->getRouteKey()])
            ->assertFormFieldIsHidden('app_fee_per_pack');

        // Payload DITEMPA: paksa data.role jadi 'owner' secara langsung (melewati Select
        // yang di UI normal tidak pernah menawarkan opsi 'owner' ke non-super-admin —
        // lihat assertion role-options di bawah). Gate field HARUS tetap menolak karena
        // ia cek isSuperAdmin(), bukan cuma nilai role yang sedang terisi di form.
        $component->set('data.role', 'owner')
            ->assertFormFieldIsHidden('app_fee_per_pack')
            ->set('data.app_fee_per_pack', 999)
            ->call('save');

        $operator->refresh();
        $this->assertNull($operator->app_fee_per_pack, 'Payload tempaan tidak boleh menyimpan fee aplikasi untuk owner.');
        $this->assertSame('operator', $operator->role, 'mutateFormDataBeforeSave tetap memaksa role balik ke operator.');
        $this->assertSame($owner->id, $operator->owner_id, 'owner_id tetap milik owner yang login (tidak ikut payload tempaan).');
    }

    public function test_role_select_options_never_offer_owner_to_non_super_admin(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('role', function ($field): bool {
                $options = $field->getOptions();

                return ! array_key_exists('owner', $options) && ! array_key_exists('super_admin', $options);
            });
    }

    public function test_role_select_offers_owner_to_super_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin);

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('role', function ($field): bool {
                return array_key_exists('owner', $field->getOptions());
            });
    }
}

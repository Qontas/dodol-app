<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Filament\Resources\OperatorResource;
use App\Filament\Resources\OperatorResource\Pages\ManageOperators;
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
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'Operator Baru',
            'email' => 'baru@cemilanqontas.id',
            'role' => 'operator',
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * BRIEF D9 (23 Okt 2026) Tugas 1a — owner mengisi tarif khusus LEWAT
     * OperatorResource (bukan lagi hanya lewat UserResource super admin).
     */
    public function test_owner_can_set_komisi_khusus_via_operator_resource_create_form(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $this->actingAs($owner);

        Livewire::test(ManageOperators::class)
            ->callAction('create', [
                'name' => 'Rian Khusus',
                'email' => 'rian.khusus.operator@cemilanqontas.id',
                'password' => 'password123',
                'komisi_per_mika_khusus' => 800,
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $operator = User::where('email', 'rian.khusus.operator@cemilanqontas.id')->firstOrFail();
        $this->assertEquals($owner->id, $operator->owner_id);
        $this->assertEquals(800, $operator->komisi_per_mika_khusus);
        $this->assertEquals(800.0, $operator->getEffectiveKomisiPerMikaValue());
    }

    /** Kosongkan field → ikut tarif default usaha owner, bukan 0 atau null-error. */
    public function test_owner_can_leave_komisi_khusus_empty_via_operator_resource_create_form(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $this->actingAs($owner);

        Livewire::test(ManageOperators::class)
            ->callAction('create', [
                'name' => 'Rian Default',
                'email' => 'rian.default.operator@cemilanqontas.id',
                'password' => 'password123',
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $operator = User::where('email', 'rian.default.operator@cemilanqontas.id')->firstOrFail();
        $this->assertNull($operator->komisi_per_mika_khusus);
        $this->assertEquals(1000.0, $operator->getEffectiveKomisiPerMikaValue());
    }

    /**
     * Helper text field harus menampilkan tarif default OWNER YANG LOGIN
     * (bukan 1000 hardcode) — bukti rumus dibaca dari
     * HasKomisiKhususOperatorField::resolveOwnerDefaultKomisiRate(), sama
     * dengan UserResource (tidak dobel rumus).
     */
    public function test_komisi_khusus_helper_text_menampilkan_tarif_default_owner_yang_login(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1234]);
        $this->actingAs($owner);

        $html = Livewire::test(ManageOperators::class)
            ->mountAction('create')
            ->html();

        $this->assertStringContainsString(
            'Kosongkan untuk ikut tarif default usaha (Rp 1.234). Berlaku untuk trip yang dimulai SETELAH disimpan.',
            $html,
        );
    }

    /**
     * BRIEF D9 Tugas 1b — kolom tabel menampilkan tarif EFEKTIF per baris
     * (khusus kalau diisi, ikut default kalau tidak), bukan lagi tarif owner
     * login yang sama untuk semua baris.
     */
    public function test_tabel_operator_menampilkan_tarif_efektif_dan_suffix_per_baris(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true, 'name' => 'Operator Khusus', 'komisi_per_mika_khusus' => 800]);
        User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true, 'name' => 'Operator Default', 'komisi_per_mika_khusus' => null]);

        $this->actingAs($owner);

        Livewire::test(ManageOperators::class)
            ->assertOk()
            ->assertSee('Rp 800 (khusus)')
            ->assertSee('Rp 1.000 (ikut default)');
    }

    /**
     * BRIEF D9 Tugas 1c(i) — owner A TIDAK bisa mengubah komisi operator milik
     * owner B lewat OperatorResource. Record owner B tidak pernah muncul di
     * getEloquentQuery() owner A (scoped where owner_id) — Filament table
     * action testing helper (mountTableAction) SENDIRI memanggil
     * getTableRecord($key) lewat query yang sama itu: kalau record tak
     * ditemukan di situ, aksinya SENGAJA TIDAK pernah ter-mount (bukan error
     * terlempar, tapi juga tidak ada apa pun yang terjadi) — dibuktikan via
     * assertTableActionNotMounted() + nilai DB operator B tidak berubah.
     */
    public function test_owner_a_tidak_bisa_mengubah_komisi_operator_milik_owner_b(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $operatorB = User::factory()->create([
            'role' => 'operator', 'owner_id' => $ownerB->id, 'is_active' => true,
            'name' => 'Operator Milik B', 'komisi_per_mika_khusus' => null,
        ]);

        $this->actingAs($ownerA);

        Livewire::test(ManageOperators::class)
            ->mountTableAction('edit', $operatorB)
            ->assertTableActionNotMounted('edit');

        $this->assertNull($operatorB->refresh()->komisi_per_mika_khusus, 'Nilai DB operator milik owner B tidak boleh berubah sama sekali.');
    }

    /** BRIEF D9 Tugas 1c(ii) — operator (bukan owner) tidak bisa mengakses form ini sama sekali. */
    public function test_operator_tidak_bisa_mengakses_halaman_operator_resource(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true]);

        $this->actingAs($operator)->get('/owner-panel/operators')->assertForbidden();
    }
}

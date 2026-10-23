<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF Kolom Komisi & Fee (23 Okt 2026) — kolom "Komisi Operator /Mika" baris OWNER kini
 * berlabel "Default Rp X" (bukan lagi "Bayar Rp X") + description "Operator:
 * Rp MIN – Rp MAX" kalau ADA operator aktifnya yang tarif efektif ≠ default.
 * Kolom baru "Fee Aplikasi" (app_fee_per_pack) HANYA visible super admin.
 *
 * Tampilan saja — tidak ada migrasi, tidak ada angka uang yang berubah
 * (rumus getKomisiPerMikaValue()/getEffectiveKomisiPerMikaValue()/
 * getAppFeePerPackValue() TETAP, lihat UserResource::table() & User.php).
 */
class UserResourceKomisiFeeColumnTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($super);

        return $super;
    }

    public function test_baris_owner_pakai_label_default_bukan_bayar(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Label', 'role' => 'owner', 'komisi_per_mika' => 1000]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnFormattedStateSet('komisi_efektif_per_mika', 'Default Rp 1.000', $owner)
            ->assertTableColumnFormattedStateNotSet('komisi_efektif_per_mika', 'Bayar Rp 1.000', $owner);
    }

    public function test_description_muncul_saat_ada_operator_tarif_berbeda_dari_default(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Rentang', 'role' => 'owner', 'komisi_per_mika' => 1000]);
        User::factory()->create([
            'name' => 'Operator Khusus 500', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => true, 'komisi_per_mika_khusus' => 500,
        ]);
        User::factory()->create([
            'name' => 'Operator Ikut Default', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => true, 'komisi_per_mika_khusus' => null,
        ]);
        User::factory()->create([
            'name' => 'Operator Khusus 1500', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => true, 'komisi_per_mika_khusus' => 1500,
        ]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnHasDescription('komisi_efektif_per_mika', 'Operator: Rp 500 – Rp 1.500', $owner->getKey());
    }

    public function test_tanpa_description_kalau_tidak_ada_operator_khusus(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Seragam', 'role' => 'owner', 'komisi_per_mika' => 1000]);
        User::factory()->create([
            'name' => 'Operator Seragam', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => true, 'komisi_per_mika_khusus' => null,
        ]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnHasDescription('komisi_efektif_per_mika', null, $owner->getKey());
    }

    public function test_tanpa_description_kalau_owner_tidak_punya_operator_aktif(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Sendiri', 'role' => 'owner', 'komisi_per_mika' => 1000]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnHasDescription('komisi_efektif_per_mika', null, $owner->getKey());
    }

    public function test_operator_nonaktif_tidak_ikut_hitungan_min_max(): void
    {
        $this->actingAsSuperAdmin();
        $owner = User::factory()->create(['name' => 'Owner Nonaktif', 'role' => 'owner', 'komisi_per_mika' => 1000]);
        User::factory()->create([
            'name' => 'Operator Nonaktif Murah', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => false, 'komisi_per_mika_khusus' => 100,
        ]);
        User::factory()->create([
            'name' => 'Operator Aktif Ikut Default', 'role' => 'operator', 'owner_id' => $owner->id,
            'is_active' => true, 'komisi_per_mika_khusus' => null,
        ]);

        // Operator nonaktif (Rp 100) diabaikan — satu-satunya operator AKTIF ikut
        // default, jadi tanpa description (bukan "Operator: Rp 100 – Rp 1.000").
        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnHasDescription('komisi_efektif_per_mika', null, $owner->getKey());
    }

    public function test_kolom_fee_aplikasi_tampil_untuk_super_admin(): void
    {
        $this->actingAsSuperAdmin();
        $ownerBerbayar = User::factory()->create(['name' => 'Owner Berbayar', 'role' => 'owner', 'app_fee_per_pack' => 300]);
        $ownerGratis = User::factory()->create(['name' => 'Owner Gratis', 'role' => 'owner', 'app_fee_per_pack' => null]);
        $operator = User::factory()->create(['name' => 'Operator Fee', 'role' => 'operator', 'owner_id' => $ownerBerbayar->id]);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnVisible('app_fee_per_pack')
            ->assertTableColumnFormattedStateSet('app_fee_per_pack', 'Rp 300 /mika', $ownerBerbayar)
            ->assertTableColumnFormattedStateSet('app_fee_per_pack', 'Rp 0', $ownerGratis)
            // Baris operator: state null → placeholder '—' DIRENDER (bukan diformat
            // oleh formatState(), lihat pola yang sama di UserResourceOwnerColumnTest
            // untuk kolom owner.name).
            ->assertTableColumnStateSet('app_fee_per_pack', null, $operator);
    }

    public function test_kolom_fee_aplikasi_tidak_terlihat_oleh_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnHidden('app_fee_per_pack')
            ->assertDontSee('Fee Aplikasi')
            ->assertDontSee('Rp 300 /mika');
    }

    /**
     * Guard anti-N+1: MIN/MAX tarif operator per owner dibaca lewat subquery
     * correlated yang ikut query utama (addSelect di getEloquentQuery()) — query
     * count TIDAK boleh tumbuh seiring jumlah operator per owner.
     */
    public function test_query_konstan_berapa_pun_jumlah_operator_per_owner(): void
    {
        $this->actingAsSuperAdmin();
        $ownerSedikit = User::factory()->create(['name' => 'A Owner Sedikit', 'role' => 'owner']);
        User::factory()->create(['name' => 'A Operator Sedikit', 'role' => 'operator', 'owner_id' => $ownerSedikit->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListUsers::class)->assertSuccessful();
        $sedikit = count(DB::getQueryLog());
        DB::disableQueryLog();

        $ownerBanyak = User::factory()->create(['name' => 'B Owner Banyak', 'role' => 'owner']);
        for ($i = 1; $i <= 6; $i++) {
            User::factory()->create([
                'name' => sprintf('B Operator Banyak %02d', $i),
                'role' => 'operator',
                'owner_id' => $ownerBanyak->id,
                'komisi_per_mika_khusus' => 500 + $i,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListUsers::class)->assertSuccessful();
        $banyak = count(DB::getQueryLog());
        DB::disableQueryLog();

        $selisih = $banyak - $sedikit;
        $this->assertLessThanOrEqual(
            2,
            $selisih,
            "Query tumbuh per operator (sedikit={$sedikit}, banyak={$banyak}, selisih={$selisih}) — subquery MIN/MAX jadi N+1?",
        );
    }
}

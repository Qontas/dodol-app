<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\OwnerPerformanceTable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF (23 Okt 2026) TUGAS 1 — "Hitungan operator salah": owner Ismi tampil 2
 * operator padahal aslinya 1. Akar masalah: 3 titik hitung ("Operator" di
 * UserResource, "Leaderboard Owner Hari Ini", kartu "Total Operator" dashboard
 * owner) masing-masing menghitung `operators()`/`User::where('role','operator')`
 * MENTAH — ikut menghitung operator NONAKTIF dan akun sistem (operator.migrasi.*
 * dibuat kios:saldo-awal/OpeningBalance).
 *
 * Fix: satu relasi `User::realOperators()` (aktif & bukan akun sistem), dipakai
 * ULANG di ketiga tempat — bukan ditulis ulang per file. Test ini MENGUNCI skenario
 * persis dari brief: owner dengan 1 operator AKTIF + 1 NONAKTIF + 1 AKUN SISTEM
 * harus tampil "1" di SEMUA tempat, bukan 2 atau 3.
 */
class OperatorCountTest extends TestCase
{
    use RefreshDatabase;

    private function buatOwnerDenganTigaOperator(): User
    {
        $owner = User::factory()->create(['name' => 'Owner Ismi', 'role' => 'owner', 'is_active' => true]);

        // 1 operator AKTIF — satu-satunya yang boleh terhitung.
        User::factory()->create([
            'name' => 'Operator Aktif Satu',
            'role' => 'operator',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        // 1 operator NONAKTIF — tidak boleh terhitung.
        User::factory()->create([
            'name' => 'Operator Nonaktif Dua',
            'role' => 'operator',
            'owner_id' => $owner->id,
            'is_active' => false,
        ]);

        // 1 AKUN SISTEM (pola email operator.migrasi.* dari kios:saldo-awal) — aktif
        // secara kolom `is_active` di beberapa jalur, tapi tetap bukan operator
        // manusia dan tidak boleh terhitung. Pola persis SuperAdminUserGuardTest.
        User::factory()->create([
            'name' => 'Akun Migrasi Owner',
            'role' => 'operator',
            'owner_id' => $owner->id,
            'is_active' => false,
            'email' => 'operator.migrasi.owner'.$owner->id.'@cemilanqontas.id',
        ]);

        return $owner;
    }

    public function test_kolom_operator_user_resource_hanya_hitung_aktif_dan_bukan_sistem(): void
    {
        $owner = $this->buatOwnerDenganTigaOperator();

        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($super);

        // ->getKey() (bukan model $owner langsung) — assertTableColumnStateSet
        // MEMUAT ULANG record dari query tabel (lengkap dengan withCount) hanya
        // kalau diberi ID; model mentah dari factory tidak punya atribut
        // active_operators_count sama sekali (null → false positive kalau lolos).
        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('active_operators_count', 1, $owner->getKey());
    }

    public function test_leaderboard_owner_performance_table_hanya_hitung_aktif_dan_bukan_sistem(): void
    {
        $owner = $this->buatOwnerDenganTigaOperator();

        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($super)->test(OwnerPerformanceTable::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('operators_count', 1, $owner->getKey());
    }

    public function test_kartu_total_operator_dashboard_owner_hanya_hitung_aktif_dan_bukan_sistem(): void
    {
        $owner = $this->buatOwnerDenganTigaOperator();

        $this->actingAs($owner)
            ->get(route('owner.dashboard'))
            ->assertSuccessful()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['total_operator'] === 1);
    }
}

<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\OperatorResource\Pages\ManageOperators;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guard delete TIDAK boleh N+1 saat render tabel.
 *
 * LATAR (audit 15 Juli 2026): ->disabled() dan ->tooltip() Filament masing-masing
 * memanggil guard sekali PER BARIS, dan versi real-time menembak 2-3 COUNT tiap
 * panggilan → ~53 query untuk 10 baris. Terbatas pagination (tak meledak) tapi mubazir.
 * Fix: withCount di getEloquentQuery + deletionBlockReasonForDisplay() yang membaca
 * count itu (0 query/baris). Test ini MENGUNCI supaya tak diam-diam balik lagi.
 */
class UserListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function hitungQuery(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function buatOwnerBerdata(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'C'.uniqid(), 'owner_id' => $owner->id]);
        Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true]);
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        return $owner;
    }

    public function test_daftar_user_query_konstan_berapa_pun_jumlah_user(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($super);

        for ($i = 0; $i < 2; $i++) {
            $this->buatOwnerBerdata();
        }
        $sedikit = $this->hitungQuery(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        for ($i = 0; $i < 10; $i++) {
            $this->buatOwnerBerdata();
        }
        $banyak = $this->hitungQuery(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        // Yang diuji: query TIDAK tumbuh PER BARIS. Sengaja BUKAN kesetaraan persis —
        // begitu baris melewati satu halaman, Filament menambah query paginasi (biaya
        // TETAP, bukan per baris). 5→25 user (+20 user) boleh menambah beberapa query
        // paginasi; yang TAK BOLEH adalah tambahan sebanding jumlah baris.
        $selisih = $banyak - $sedikit;
        $this->assertLessThanOrEqual(
            4,
            $selisih,
            "Query tumbuh per baris (5 user={$sedikit}, 25 user={$banyak}, selisih={$selisih}) — guard delete N+1 lagi?",
        );

        // Sebelum fix: 24 query untuk 5 user, 54 untuk 25 (guard 2-3 COUNT × 2 pemanggil
        // per baris). Ambang ini menangkap kalau itu kembali.
        $this->assertLessThan(12, $banyak, "Daftar user boros query: {$banyak}.");
    }

    public function test_daftar_operator_owner_query_konstan(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($owner);

        // trip_date DIBUAT UNIK per operator: trips punya unique (owner_id, trip_date,
        // trip_number_of_day) — satu owner dengan belasan trip akan bentrok kalau semua
        // memakai tanggal bawaan factory yang sama.
        $ke = 0;
        $buatOperator = function () use ($owner, &$ke) {
            $op = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true]);
            Trip::factory()->create([
                'owner_id' => $owner->id,
                'operator_id' => $op->id,
                'trip_date' => now()->subDays(++$ke),
            ]);
        };

        for ($i = 0; $i < 2; $i++) {
            $buatOperator();
        }
        $sedikit = $this->hitungQuery(fn () => Livewire::test(ManageOperators::class)->assertSuccessful());

        for ($i = 0; $i < 10; $i++) {
            $buatOperator();
        }
        $banyak = $this->hitungQuery(fn () => Livewire::test(ManageOperators::class)->assertSuccessful());

        $selisih = $banyak - $sedikit;
        $this->assertLessThanOrEqual(
            4,
            $selisih,
            "Query daftar operator tumbuh per baris (2 op={$sedikit}, 12 op={$banyak}) — guard delete N+1 lagi?",
        );
        $this->assertLessThan(10, $banyak, "Daftar operator boros query: {$banyak}.");
    }

    /** Versi render membaca count yang sudah dimuat → NOL query tambahan. */
    public function test_versi_render_nol_query_saat_count_sudah_dimuat(): void
    {
        $owner = $this->buatOwnerBerdata();

        $dimuat = User::withCount(['ownedKiosks', 'ownedTrips', 'operators', 'operatedTrips', 'commissions'])
            ->findOrFail($owner->id);

        $n = $this->hitungQuery(function () use ($dimuat) {
            $dimuat->deletionBlockReasonForDisplay();
            $dimuat->deletionBlockReasonForDisplay(); // dipanggil 2x (disabled + tooltip)
        });

        $this->assertSame(0, $n, "Versi render harus 0 query, ternyata {$n}.");
    }

    /** Tanpa count ter-eager-load, versi render JATUH ke real-time — jawabannya tetap benar. */
    public function test_versi_render_fallback_benar_tanpa_count(): void
    {
        $owner = $this->buatOwnerBerdata();
        $polos = User::findOrFail($owner->id); // tanpa withCount

        $this->assertSame(
            $owner->deletionBlockReason(),
            $polos->deletionBlockReasonForDisplay(),
            'Fallback harus memberi alasan yang sama dengan versi real-time.',
        );
        $this->assertStringContainsString('1 kios', $polos->deletionBlockReasonForDisplay());
    }

    /** Dua jalur (render vs real-time) harus memberi teks IDENTIK untuk semua peran. */
    public function test_dua_jalur_memberi_alasan_identik(): void
    {
        $owner = $this->buatOwnerBerdata();
        $operator = User::where('owner_id', $owner->id)->firstOrFail();
        $super = User::factory()->create(['role' => 'super_admin']);
        $bersih = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);

        foreach ([$owner, $operator, $super, $bersih] as $user) {
            $dimuat = User::withCount(['ownedKiosks', 'ownedTrips', 'operators', 'operatedTrips', 'commissions'])
                ->findOrFail($user->id);

            $this->assertSame(
                $user->deletionBlockReason(),
                $dimuat->deletionBlockReasonForDisplay(),
                "Alasan berbeda antara jalur real-time & render untuk role {$user->role}.",
            );
        }
    }

    /**
     * INTI pemisahan dua jalur: keputusan HAPUS harus REAL-TIME, tak boleh bersandar
     * angka yang dimuat saat render (bisa basi berdetik/bermenit). Di sini count
     * di-load DULU, lalu data baru dibuat — versi real-time WAJIB melihatnya.
     */
    public function test_keputusan_hapus_realtime_tidak_pakai_count_basi(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator->update(['owner_id' => $owner->id]);

        // Dimuat saat "render": operator masih bersih → boleh dihapus.
        $dimuat = User::withCount(['ownedKiosks', 'ownedTrips', 'operators', 'operatedTrips', 'commissions'])
            ->findOrFail($operator->id);
        $this->assertNull($dimuat->deletionBlockReasonForDisplay());

        // Setelah render, operator dapat trip (mis. operator lain memulai trip).
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        // Angka render sudah BASI (masih bilang boleh)...
        $this->assertNull($dimuat->deletionBlockReasonForDisplay());

        // ...tapi guard REAL-TIME (yang dipakai ->before saat hapus ditekan) melihatnya.
        $this->assertStringContainsString('1 trip', $dimuat->deletionBlockReason());
    }
}

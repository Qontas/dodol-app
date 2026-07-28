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
 *
 * ── ATURAN DETERMINISME (28 Juli 2026, setelah test ini merah acak 2 sesi) ──
 * Test ini SEMPAT flaky. Penyebabnya BUKAN warmup, melainkan N+1 SUNGGUHAN yang
 * ketutupan: kolom "Komisi/Mika" membaca `$record->owner?->…` sehingga tiap baris
 * OPERATOR memuat owner-nya sendiri. Karena tabel diurut `name asc` dan halaman 1
 * cuma 10 baris, jumlah baris operator yang tampil bergantung NAMA RANDOM factory
 * → query count naik-turun 5..9 sementara ambang 4 → merah acak. Diperbaiki dengan
 * `->with('owner')` di UserResource::getEloquentQuery().
 *
 * Karena itu, di file ini: JANGAN pernah pakai nama bawaan factory untuk user yang
 * ikut tampil di tabel. Nama eksplisit = komposisi halaman terkunci = angka query
 * deterministik. Merah di sini berarti benar-benar ada masalah, bukan kebetulan.
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

    /**
     * NAMA SELALU EKSPLISIT — jangan pernah pakai nama bawaan factory di test ini.
     *
     * Tabel diurut `name asc` dan halaman 1 hanya memuat 10 baris, jadi nama
     * menentukan BARIS MANA yang tampil. Dengan nama random (fake()->name()),
     * jumlah baris OPERATOR di halaman 1 berubah tiap run → query count ikut
     * naik-turun → test merah acak. Nama eksplisit mengunci komposisi halaman.
     */
    private function buatOwnerBerdata(string $namaOwner, string $namaOperator): User
    {
        $owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true, 'name' => $namaOwner,
        ]);
        $cluster = Cluster::create(['name' => 'C'.uniqid(), 'owner_id' => $owner->id]);
        Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $operator = User::factory()->create([
            'role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true, 'name' => $namaOperator,
        ]);
        Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        return $owner;
    }

    public function test_daftar_user_query_konstan_berapa_pun_jumlah_user(): void
    {
        $super = User::factory()->create([
            'role' => 'super_admin', 'is_active' => true, 'name' => 'ZZ Super Admin',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($super);

        // FASE 1 — 5 user, semuanya muat di halaman 1 (2 di antaranya operator).
        for ($i = 1; $i <= 2; $i++) {
            $this->buatOwnerBerdata("M Owner Kecil {$i}", "M Operator Kecil {$i}");
        }
        $sedikit = $this->hitungQuery(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        // FASE 2 — +20 user (jadi 25). Nama "A …" sengaja dipilih agar 10 OPERATOR
        // baru inilah yang mengisi halaman 1 SELURUHNYA. Itu kasus TERBURUK untuk
        // N+1 kolom "Komisi/Mika" (tiap baris operator memuat owner-nya): kalau
        // eager load `with('owner')` hilang, halaman ini menembak 10 query ekstra
        // sedangkan fase 1 cuma 2 → selisih melonjak ke ~8, jauh di atas ambang.
        for ($i = 1; $i <= 10; $i++) {
            $this->buatOwnerBerdata(
                sprintf('Z Owner Besar %02d', $i),
                sprintf('A Operator Besar %02d', $i),
            );
        }
        $banyak = $this->hitungQuery(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        // Yang diuji: query TIDAK tumbuh per baris. Dengan fixture yang komposisi
        // halamannya terkunci, selisih sebenarnya = 0 (5 user → 3 query, 25 user →
        // 3 query: count paginasi + query utama + eager load owner). Ambang 2 dipakai
        // sebagai kelonggaran untuk query berbiaya TETAP yang mungkin ditambah
        // Filament saat data melewati satu halaman — masih jauh di bawah +8 yang
        // dihasilkan N+1 per-baris, jadi daya tangkapnya utuh.
        $selisih = $banyak - $sedikit;
        $this->assertLessThanOrEqual(
            2,
            $selisih,
            "Query tumbuh per baris (5 user={$sedikit}, 25 user={$banyak}, selisih={$selisih}) — eager load/withCount hilang?",
        );

        // Sebelum fix guard delete: 24 query untuk 5 user, 54 untuk 25 (2-3 COUNT ×
        // 2 pemanggil per baris). Ambang absolut ini menangkap kalau itu kembali.
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
        // Nama EKSPLISIT & berurutan — alasan sama dengan test di atas: tabel diurut
        // `name asc` + halaman 1 hanya 10 baris, jadi nama random bikin komposisi
        // halaman (dan query count) berubah tiap run. Saat ini kolom ManageOperators
        // tak memuat relasi per baris sehingga hitungannya kebetulan stabil; nama
        // eksplisit menjaga agar penambahan kolom relasi nanti muncul sebagai MERAH
        // yang jujur, bukan merah acak.
        $ke = 0;
        $buatOperator = function () use ($owner, &$ke) {
            $ke++;
            $op = User::factory()->create([
                'role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true,
                'name' => sprintf('Operator %02d', $ke),
            ]);
            Trip::factory()->create([
                'owner_id' => $owner->id,
                'operator_id' => $op->id,
                'trip_date' => now()->subDays($ke),
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
        $owner = $this->buatOwnerBerdata('Owner Uji', 'Operator Uji');

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
        $owner = $this->buatOwnerBerdata('Owner Uji', 'Operator Uji');
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
        $owner = $this->buatOwnerBerdata('Owner Uji', 'Operator Uji');
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

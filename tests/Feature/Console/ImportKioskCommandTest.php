<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportKioskCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tulis CSV bergaya file abang: 2 baris dekoratif, lalu data.
     * Kolom 0-based: D(3)=nama, E(4)=qty, F(5)=alamat, G(6)=lokasi.
     */
    private function makeFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kios').'.csv';
        $fh = fopen($path, 'w');
        $rows = [
            ['DODOL'],
            ['LIST TEMPAT TITIPAN'],
            // A,B,C,D(nama),E(qty),F(alamat),G(lokasi),H,I,J
            ['1', '', '', 'Kios DMS', '3', 'Jl Satu', '3° 36\' 15.5196" N 98° 39\' 54.072" E', '', '', 'Aidil'],
            ['2', '', '', 'Kios Link', '', 'Jl Dua', 'https://maps.app.goo.gl/abc', '', '', 'Aidil'],
            ['3', '', '', 'Kios Teks', '5', 'Jl Tiga', 'WARKOP NST', '', '', 'Aidil'],
            ['4', '', '', 'Kios Kosong', '', 'Jl Empat', '', '', '', 'Aidil'],
            ['5', '', '', 'Kios DMS', '2', 'Jl Lima', '', '', '', 'Aidil'], // duplikat nama dalam file
            ['6', '', '', '', '2', 'Tanpa Nama', '', '', '', 'Aidil'],      // tanpa nama → skip
        ];
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    public function test_dry_run_does_not_write(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $file = $this->makeFixture();

        $this->artisan('kios:import', ['file' => $file, '--owner' => $owner->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('kiosks', 0);
        @unlink($file);
    }

    public function test_import_creates_scoped_kiosks_with_parsed_location(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $file = $this->makeFixture();

        $this->artisan('kios:import', ['file' => $file, '--owner' => $owner->id])
            ->assertSuccessful();

        // 4 valid (DMS, Link, Teks, Kosong). Duplikat & baris tanpa nama dilewati.
        $this->assertSame(4, Kiosk::count());

        // Semua masuk cluster milik owner → scoping owner via cluster.owner_id.
        $cluster = Cluster::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(4, Kiosk::where('cluster_id', $cluster->id)->count());

        // DMS → koordinat desimal.
        $dms = Kiosk::where('name', 'Kios DMS')->firstOrFail();
        $this->assertEqualsWithDelta(3.604311, (float) $dms->latitude, 0.0001);
        $this->assertEqualsWithDelta(98.665020, (float) $dms->longitude, 0.0001);

        // Link maps → notes, koordinat null.
        $link = Kiosk::where('name', 'Kios Link')->firstOrFail();
        $this->assertNull($link->latitude);
        $this->assertStringStartsWith('GPS: https://maps.app.goo.gl', $link->notes);

        // Teks → digabung ke location_description, koordinat null.
        $teks = Kiosk::where('name', 'Kios Teks')->firstOrFail();
        $this->assertNull($teks->latitude);
        $this->assertStringContainsString('WARKOP NST', $teks->location_description);

        // Qty kosong → default 2; qty terisi dipakai.
        $this->assertSame(2, (int) $link->default_qty_mika);
        $this->assertSame(5, (int) $teks->default_qty_mika);

        @unlink($file);
    }

    public function test_skips_db_duplicate_by_name_within_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Lama', 'owner_id' => $owner->id, 'is_active' => true]);
        Kiosk::create(['name' => 'Kios DMS', 'cluster_id' => $cluster->id, 'is_active' => true]);

        $file = $this->makeFixture();
        $this->artisan('kios:import', ['file' => $file, '--owner' => $owner->id])->assertSuccessful();

        // "Kios DMS" sudah ada → tidak dibuat lagi. Hanya 3 baru (Link, Teks, Kosong).
        $this->assertSame(1, Kiosk::where('name', 'Kios DMS')->count());
        $this->assertSame(4, Kiosk::count()); // 1 lama + 3 baru
        @unlink($file);
    }

    public function test_actual_import_requires_owner(): void
    {
        $file = $this->makeFixture();
        $this->artisan('kios:import', ['file' => $file])->assertFailed();
        $this->assertDatabaseCount('kiosks', 0);
        @unlink($file);
    }
}

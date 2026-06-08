<?php

namespace Tests\Feature\Owner;

use App\Filament\Imports\KioskImporter;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskImporterAidilTest extends TestCase
{
    use RefreshDatabase;

    private function makeImporter(User $owner): KioskImporter
    {
        $import = Import::create([
            'file_name' => 'aidil.csv',
            'file_path' => 'aidil.csv',
            'importer' => KioskImporter::class,
            'total_rows' => 3,
            'user_id' => $owner->id,
        ]);

        $columnMap = [
            'name' => 'nama_kios',
            'cluster' => 'cluster',
            'default_qty_mika' => 'qty_mika',
            'location_description' => 'deskripsi_lokasi',
            'koordinat_atau_link' => 'koordinat_atau_link',
            'first_titip_date' => 'tanggal_pertama_titip',
        ];

        return new KioskImporter($import, $columnMap, []);
    }

    public function test_dms_coordinate_parsed_and_numeric_cluster_created(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $importer = $this->makeImporter($owner);

        $importer([
            'nama_kios' => 'Karya 1 (masjid)',
            'cluster' => '1',
            'qty_mika' => '3',
            'deskripsi_lokasi' => 'Jl. Karya disamping Masjid',
            'koordinat_atau_link' => '3° 36\' 15.5196" N 98° 39\' 54.072" E',
            'tanggal_pertama_titip' => '26/02/2025',
        ]);

        // Cluster angka → auto-create "Cluster 1" milik owner
        $cluster = Cluster::where('owner_id', $owner->id)->where('name', 'Cluster 1')->first();
        $this->assertNotNull($cluster);

        $kiosk = Kiosk::where('name', 'Karya 1 (masjid)')->first();
        $this->assertNotNull($kiosk);
        $this->assertSame($cluster->id, $kiosk->cluster_id);
        $this->assertSame('3.6043110', $kiosk->latitude);
        $this->assertSame('98.6650200', $kiosk->longitude);
        $this->assertSame('2025-02-26', $kiosk->first_titip_date->toDateString());
        $this->assertNull($kiosk->owner_name); // tidak ada kolom pemilik
    }

    public function test_google_maps_link_stored_in_notes(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $importer = $this->makeImporter($owner);

        $importer([
            'nama_kios' => 'Karya 2',
            'cluster' => '2',
            'qty_mika' => '2',
            'deskripsi_lokasi' => 'Depan Alfa Midi',
            'koordinat_atau_link' => 'https://maps.app.goo.gl/abcd1234',
            'tanggal_pertama_titip' => '',
        ]);

        $kiosk = Kiosk::where('name', 'Karya 2')->first();
        $this->assertNotNull($kiosk);
        $this->assertNull($kiosk->latitude);
        $this->assertNull($kiosk->longitude);
        $this->assertStringContainsString('GPS: https://maps.app.goo.gl/abcd1234', $kiosk->notes);
        $this->assertNull($kiosk->first_titip_date); // kosong = null, bukan today()
    }

    public function test_plain_text_and_blank_cluster_uncategorized(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $importer = $this->makeImporter($owner);

        $importer([
            'nama_kios' => 'Gaperta 3',
            'cluster' => '',
            'qty_mika' => '5',
            'deskripsi_lokasi' => 'Jl. Gaperta ujung',
            'koordinat_atau_link' => 'WARKOP NST',
            'tanggal_pertama_titip' => '',
        ]);

        $kiosk = Kiosk::where('name', 'Gaperta 3')->first();
        $this->assertNotNull($kiosk);
        $this->assertNull($kiosk->latitude); // teks biasa → tanpa koordinat
        $this->assertNull($kiosk->longitude);

        $uncat = Cluster::where('owner_id', $owner->id)->where('name', 'Uncategorized')->first();
        $this->assertNotNull($uncat);
        $this->assertSame($uncat->id, $kiosk->cluster_id);
    }
}

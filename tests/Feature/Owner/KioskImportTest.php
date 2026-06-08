<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\ListKiosks;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KioskImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_list_page_has_import_action()
    {
        // Create an owner user who can access the owner/admin dashboard
        $user = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(ListKiosks::class)
            ->assertActionExists('import');
    }

    public function test_kiosk_importer_maps_and_creates_records()
    {
        $user = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // Cluster milik owner ini (multi-tenant: resolveCluster scope by owner_id).
        $cluster = \App\Models\Cluster::create(['name' => 'Cluster A', 'is_active' => true, 'owner_id' => $user->id]);

        $import = \Filament\Actions\Imports\Models\Import::create([
            'file_name' => 'kiosks.csv',
            'file_path' => 'kiosks.csv',
            'importer' => \App\Filament\Imports\KioskImporter::class,
            'total_rows' => 3,
            'user_id' => $user->id,
        ]);

        $columnMap = [
            'name' => 'nama',
            'owner_name' => 'pemilik',
            'cluster' => 'cluster',
            'default_qty_mika' => 'qty_mika',
            'phone' => 'telepon',
            'location_description' => 'alamat',
            'latitude' => 'lat',
            'longitude' => 'lng',
        ];

        $importer = new \App\Filament\Imports\KioskImporter($import, $columnMap, []);

        // Row 1: Valid kiosk with existing cluster
        $importer([
            'nama' => 'Kios Sejahtera',
            'pemilik' => 'Budi',
            'cluster' => 'Cluster A',
            'qty_mika' => '10',
            'telepon' => '0812345678',
            'alamat' => 'Jl. Merdeka No. 1',
            'lat' => '3.5952',
            'lng' => '98.6722',
        ]);

        // Row 2: cluster tidak ada → auto-create "Cluster B" milik owner
        $importer([
            'nama' => 'Kios Jaya',
            'pemilik' => 'Joko',
            'cluster' => 'Cluster B', // doesn't exist → dibuat otomatis
            'qty_mika' => '5',
            'telepon' => '0898765432',
            'alamat' => 'Jl. Sudirman No. 2',
            'lat' => '-6.2000',
            'lng' => '106.8166',
        ]);

        // Row 3: cluster kosong → auto-create "Uncategorized" milik owner
        $importer([
            'nama' => 'Kios Mandiri',
            'pemilik' => 'Siti',
            'cluster' => '',
            'qty_mika' => null,
            'telepon' => null,
            'alamat' => null,
            'lat' => null,
            'lng' => null,
        ]);

        $this->assertDatabaseHas('kiosks', [
            'name' => 'Kios Sejahtera',
            'owner_name' => 'Budi',
            'cluster_id' => $cluster->id,
            'default_qty_mika' => 10,
            'phone' => '0812345678',
            'location_description' => 'Jl. Merdeka No. 1',
            'latitude' => '3.5952000',
            'longitude' => '98.6722000',
        ]);

        // "Cluster B" auto-dibuat untuk owner ini
        $clusterB = \App\Models\Cluster::where('owner_id', $user->id)->where('name', 'Cluster B')->first();
        $this->assertNotNull($clusterB);
        $this->assertDatabaseHas('kiosks', [
            'name' => 'Kios Jaya',
            'owner_name' => 'Joko',
            'cluster_id' => $clusterB->id,
            'default_qty_mika' => 5,
            'phone' => '0898765432',
            'location_description' => 'Jl. Sudirman No. 2',
            'latitude' => '-6.2000000',
            'longitude' => '106.8166000',
        ]);

        // "Uncategorized" auto-dibuat untuk owner ini
        $uncategorized = \App\Models\Cluster::where('owner_id', $user->id)->where('name', 'Uncategorized')->first();
        $this->assertNotNull($uncategorized);
        $this->assertDatabaseHas('kiosks', [
            'name' => 'Kios Mandiri',
            'owner_name' => 'Siti',
            'cluster_id' => $uncategorized->id,
            'default_qty_mika' => null,
            'phone' => null,
            'location_description' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    public function test_kiosk_importer_requires_name()
    {
        $user = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $import = \Filament\Actions\Imports\Models\Import::create([
            'file_name' => 'kiosks.csv',
            'file_path' => 'kiosks.csv',
            'importer' => \App\Filament\Imports\KioskImporter::class,
            'total_rows' => 1,
            'user_id' => $user->id,
        ]);

        $columnMap = [
            'name' => 'nama',
        ];

        $importer = new \App\Filament\Imports\KioskImporter($import, $columnMap, []);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $importer([
            'nama' => '', // Empty name triggers validation exception
        ]);
    }
}

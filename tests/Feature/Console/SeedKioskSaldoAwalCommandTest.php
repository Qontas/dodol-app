<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedKioskSaldoAwalCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeVariant(int $ownerId): void
    {
        $product = Product::create(['name' => 'Dodol', 'category' => 'dodol', 'owner_id' => $ownerId, 'is_active' => true]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Mika 15 biji',
            'units_per_pack' => 15,
            'sale_price_per_pack' => 12000,
            'is_active' => true,
        ]);
    }

    private function makeFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'saldo').'.csv';
        $fh = fopen($path, 'w');
        $rows = [
            ['DODOL'],
            ['LIST TEMPAT TITIPAN'],
            // no, , , D=nama, E=qty, F=addr, G=loc, H=sc, I=tanggal, J=sales
            ['1', '', '', 'Kios A', '3', 'addr', 'loc', 'sc', '15/03/2025', 'Aidil'],
            ['2', '', '', 'Kios B', '', 'addr', 'loc', 'sc', '20/03/2025', 'Aidil'],
            ['3', '', '', 'Kios Ghost', '5', 'addr', 'loc', 'sc', '01/01/2025', 'Aidil'],
        ];
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    private function seedOwnerWithKiosks(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->makeVariant($owner->id);
        $cluster = Cluster::create(['name' => 'Tempat Titipan', 'owner_id' => $owner->id, 'is_active' => true]);
        $kA = Kiosk::create(['name' => 'Kios A', 'cluster_id' => $cluster->id, 'default_qty_mika' => 4, 'is_active' => true]);
        $kB = Kiosk::create(['name' => 'Kios B', 'cluster_id' => $cluster->id, 'default_qty_mika' => 6, 'is_active' => true]);

        return [$owner, $kA, $kB];
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$owner] = $this->seedOwnerWithKiosks();
        $file = $this->makeFixture();

        $this->artisan('kios:saldo-awal', ['file' => $file, '--owner' => $owner->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('trips', 0);
        @unlink($file);
    }

    public function test_seeds_pending_delivery_and_sets_first_titip_date(): void
    {
        [$owner, $kA, $kB] = $this->seedOwnerWithKiosks();
        $file = $this->makeFixture();

        $this->artisan('kios:saldo-awal', ['file' => $file, '--owner' => $owner->id])->assertSuccessful();

        // Hanya 2 kios match (Ghost dilewati).
        $this->assertSame(2, Delivery::count());

        $dA = Delivery::where('kiosk_id', $kA->id)->firstOrFail();
        $this->assertSame(3, (int) $dA->qty_delivered);              // qty dari kolom E
        $this->assertSame('consignment', $dA->delivery_type);
        $this->assertSame('new_procurement', $dA->source_type);
        $this->assertNull($dA->procurement_batch_id);
        $this->assertFalse($dA->settlement()->exists());             // TANPA settlement → pending
        $this->assertSame('2025-03-15', $kA->fresh()->first_titip_date->toDateString());
        $this->assertNotEquals(today()->toDateString(), $kA->fresh()->first_titip_date->toDateString());

        $dB = Delivery::where('kiosk_id', $kB->id)->firstOrFail();
        $this->assertSame(6, (int) $dB->qty_delivered);              // qty kosong → fallback default_qty_mika kios
        $this->assertSame('2025-03-20', $dB->created_at->toDateString()); // created_at = tanggal spreadsheet

        // Trip migrasi: ended, owner benar, operator nonaktif.
        $trip = Trip::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame('Migrasi saldo awal dari spreadsheet', $trip->ended_reason);
        $this->assertNotNull($trip->ended_at);
        $operator = User::find($trip->operator_id);
        $this->assertFalse((bool) $operator->is_active);
        $this->assertSame('2025-03-15', $trip->trip_date->toDateString()); // tanggal paling awal
    }

    public function test_is_idempotent(): void
    {
        [$owner] = $this->seedOwnerWithKiosks();
        $file = $this->makeFixture();

        $this->artisan('kios:saldo-awal', ['file' => $file, '--owner' => $owner->id])->assertSuccessful();
        $this->artisan('kios:saldo-awal', ['file' => $file, '--owner' => $owner->id])->assertSuccessful();

        $this->assertSame(2, Delivery::count());      // tidak dobel
        $this->assertSame(1, Trip::count());          // tidak buat trip kedua
        @unlink($file);
    }

    public function test_requires_owner(): void
    {
        $file = $this->makeFixture();
        $this->artisan('kios:saldo-awal', ['file' => $file])->assertFailed();
        $this->assertDatabaseCount('deliveries', 0);
        @unlink($file);
    }
}

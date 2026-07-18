<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource;
use App\Filament\Resources\KioskResource\Pages\ListKiosks;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\OpeningBalance;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * List Kios owner: tombol "Set Saldo Awal" DIHAPUS (redundan) + kolom "Titipan"
 * (ganti kolom Telepon) menampilkan 3 kondisi. Agregat titipan via withSum = TANPA N+1.
 */
class KioskListTitipanColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area List', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);
    }

    public function test_set_saldo_awal_action_removed_from_list(): void
    {
        Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        Livewire::test(ListKiosks::class)
            ->assertTableActionDoesNotExist('set_opening_balance');
    }

    public function test_titipan_column_shows_four_conditions(): void
    {
        // Konsinyasi + titipan berjalan → "4 mika".
        $konsinyasi = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);
        OpeningBalance::create($konsinyasi, 4);

        // Cash-only → "Cash Only".
        Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => true]);

        // BOOKING (belum ada dodol; jatah NULL, bukan cash) → "Booking" (WAJAR, abu — bukan anomali).
        $booking = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => null]);
        $this->assertTrue($booking->isBooking());

        // Konsinyasi TANPA titipan (jatah terisi tapi titipan hilang) → "Belum ada titipan" (anomali, merah).
        Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 6]);

        Livewire::test(ListKiosks::class)
            ->assertSee('4 mika')
            ->assertSee('Cash Only')
            ->assertSee('Booking')          // kondisi baru: booking bukan anomali
            ->assertSee('Belum ada titipan') // anomali betulan (konsinyasi kehilangan titipan)
            ->assertDontSee('Telepon');
    }

    public function test_titipan_aggregate_has_no_n_plus_1(): void
    {
        // Banyak kios + titipan → agregat harus 1 query, tak scale per baris.
        for ($i = 0; $i < 12; $i++) {
            $k = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => false]);
            OpeningBalance::create($k, 3);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $kiosks = KioskResource::getEloquentQuery()->get();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Satu SELECT (withSum = subquery berkorelasi di dalam query utama, bukan N+1).
        $this->assertSame(1, $queryCount, "List kios butuh {$queryCount} query — indikasi N+1.");
        $this->assertGreaterThanOrEqual(12, $kiosks->count());
        // Nilai agregat ter-hidrasi ke kolom pending_titipan_mika.
        $this->assertSame(3, (int) $kiosks->first()->pending_titipan_mika);
    }
}

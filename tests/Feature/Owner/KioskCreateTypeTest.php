<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * JENIS KEDAI saat owner input kios di Filament: Konsinyasi (titipan berjalan → langsung
 * bisa ditagih) vs Cash-Only (is_cash_only, tanpa titipan). Plus Catatan Kedai (store_note).
 */
class KioskCreateTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area Owner', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);
    }

    public function test_konsinyasi_with_opening_balance_creates_pending_titipan(): void
    {
        Livewire::test(CreateKiosk::class)
            ->fillForm([
                'name' => 'Kedai Owner Konsinyasi',
                'cluster_id' => $this->cluster->id,
                'store_note' => 'Biasa titip 4',
                'default_qty_mika' => 4,
                'jenis_kedai' => 'konsinyasi',
                'opening_balance_mika' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kedai Owner Konsinyasi')->firstOrFail();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertSame('Biasa titip 4', $kiosk->store_note);

        $pending = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first();
        $this->assertNotNull($pending);
        $this->assertSame(4, (int) $pending->qty_delivered);
    }

    public function test_cash_only_sets_flag_and_no_titipan(): void
    {
        Livewire::test(CreateKiosk::class)
            ->fillForm([
                'name' => 'Kedai Owner Cash',
                'cluster_id' => $this->cluster->id,
                'store_note' => 'Cash-only, minta 5',
                'jenis_kedai' => 'cash_only',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kedai Owner Cash')->firstOrFail();
        $this->assertTrue((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertSame('Cash-only, minta 5', $kiosk->store_note);
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
    }
}

<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk;
use App\Filament\Resources\KioskResource\Pages\EditKiosk;
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

    public function test_konsinyasi_jatah_creates_pending_titipan(): void
    {
        // SATU field mika: Jatah Titipan (default_qty_mika) = 4 → otomatis titipan awal 4.
        // Tak ada lagi field "opening_balance_mika" terpisah.
        Livewire::test(CreateKiosk::class)
            ->fillForm([
                'name' => 'Kedai Owner Konsinyasi',
                'cluster_id' => $this->cluster->id,
                'store_note' => 'Biasa titip 4',
                'default_qty_mika' => 4,
                'jenis_kedai' => 'konsinyasi',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kedai Owner Konsinyasi')->firstOrFail();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertSame(4, (int) $kiosk->default_qty_mika);
        $this->assertSame('Biasa titip 4', $kiosk->store_note);

        // Titipan awal = jatah (4 mika), bukan dari field terpisah.
        $pending = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first();
        $this->assertNotNull($pending);
        $this->assertSame(4, (int) $pending->qty_delivered);
    }

    /** EDIT konsinyasi: satu field Jatah Titipan TAMPIL (bisa diubah). */
    public function test_edit_konsinyasi_shows_jatah_field(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);

        Livewire::test(EditKiosk::class, ['record' => $kiosk->getRouteKey()])
            ->assertFormFieldExists('default_qty_mika')
            ->assertFormSet(['default_qty_mika' => 4]);
    }

    /** EDIT cash-only: field Jatah Titipan TERSEMBUNYI (tak relevan). */
    public function test_edit_cash_only_hides_jatah_field(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => true, 'default_qty_mika' => null,
        ]);

        Livewire::test(EditKiosk::class, ['record' => $kiosk->getRouteKey()])
            ->assertFormFieldIsHidden('default_qty_mika');
    }

    public function test_booking_creates_identity_only_no_titipan(): void
    {
        // Jenis BOOKING (owner Filament): identitas saja, tak ada titipan, jatah NULL,
        // bukan cash-only. Jenis final ditentukan operator di lapangan.
        Livewire::test(CreateKiosk::class)
            ->fillForm([
                'name' => 'Kedai Owner Booking',
                'cluster_id' => $this->cluster->id,
                'store_note' => 'Baru survei, belum titip',
                'jenis_kedai' => 'booking',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kedai Owner Booking')->firstOrFail();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertTrue($kiosk->isBooking());
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
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

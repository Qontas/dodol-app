<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * KEDAI BOOKING di sisi operator (dalam trip): kedai yang identitasnya sudah dicatat tapi
 * BELUM ada dodol (is_cash_only=false, default_qty_mika=null → Kiosk::isBooking()).
 * Operator melihat 3 aksi yang SUDAH ADA (Titip Cash / Mulai Titipan / Lewati); jenis final
 * ditentukan aksi itu. Plus badge "Belum pernah dititip" yang hilang setelah titipan pertama.
 */
class BookingKiosOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;
    protected Cluster $cluster;
    protected Trip $trip;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Cluster Booking', 'owner_id' => $this->operator->owner_id]);
        $this->trip = Trip::factory()->create([
            'owner_id' => $this->operator->owner_id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'ended_at' => null,
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    /** Factory kios polos = booking (bukan cash-only, tanpa jatah). */
    private function bookingKiosk(): Kiosk
    {
        return Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => null,
        ]);
    }

    public function test_booking_kiosk_shows_three_actions_no_tagih(): void
    {
        $kiosk = $this->bookingKiosk();
        $this->assertTrue($kiosk->isBooking());

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('chosenAction', null)
            ->assertSee('Titip Cash')
            ->assertSee('Mulai Titipan')
            ->assertSee('Lewati')
            ->assertDontSee('Tagih + Titip Ulang');
    }

    public function test_booking_titip_cash_makes_kiosk_cash_only(): void
    {
        // Booking → Titip Cash → kedai jadi cash-only seterusnya + omset/komisi cash.
        $kiosk = $this->bookingKiosk();

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 2)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $this->assertTrue((bool) $kiosk->is_cash_only, 'Booking + Titip Cash → cash-only.');
        $this->assertFalse($kiosk->isBooking());

        // Cash sale 2 mika di trip → komisi terhitung (drop real = 2).
        $cash = Delivery::where('kiosk_id', $kiosk->id)->where('delivery_type', 'cash_sale')->first();
        $this->assertNotNull($cash);
        $this->assertSame(2, (int) $cash->qty_delivered);
        $this->assertSame($this->trip->id, (int) $cash->trip_id);
    }

    public function test_booking_mulai_titipan_makes_kiosk_konsinyasi(): void
    {
        // Booking → Mulai Titipan → kedai jadi konsinyasi + jatah terisi + titipan berjalan.
        $kiosk = $this->bookingKiosk();

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 3)
            ->set('jatahMulai', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $this->assertFalse((bool) $kiosk->is_cash_only);
        $this->assertSame(5, (int) $kiosk->default_qty_mika, 'Jatah seterusnya = 5.');
        $this->assertFalse($kiosk->isBooking());

        $pending = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first();
        $this->assertNotNull($pending);
        $this->assertSame(3, (int) $pending->qty_delivered);
        $this->assertSame('consignment', $pending->delivery_type);
    }

    public function test_badge_belum_pernah_dititip_shows_and_disappears(): void
    {
        $booking = $this->bookingKiosk();
        $booking->update(['name' => 'Kedai Booking Badge']);

        $this->actingAs($this->operator);

        // Badge tampil untuk kedai booking.
        Livewire::test(ActiveTrip::class)
            ->assertSee('Belum pernah dititip');

        // Setelah titipan pertama (Mulai Titipan) → bukan booking lagi → badge hilang.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $booking->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 3)
            ->set('jatahMulai', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        Livewire::test(ActiveTrip::class)
            ->assertDontSee('Belum pernah dititip');
    }

    public function test_badge_absent_for_cash_only_and_konsinyasi(): void
    {
        // Cash-only & konsinyasi (punya jatah) BUKAN booking → tak ada badge.
        Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'name' => 'Kedai Cash', 'is_cash_only' => true,
        ]);
        Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'name' => 'Kedai Konsinyasi', 'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->assertDontSee('Belum pernah dititip');
    }
}

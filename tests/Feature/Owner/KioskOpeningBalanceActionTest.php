<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\ListKiosks;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\OpeningBalance;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BACKFILL KIOS LAMA via Filament: action "Set Saldo Awal" pada list kios owner.
 * Untuk kios existing yang belum punya titipan berjalan (mis. 9 kios owner hasil
 * input tanpa saldo awal) → owner klik, isi mika → OpeningBalance buat 1 delivery
 * konsinyasi pending → kios bisa "Tagih + Titip Ulang". Idempoten (tombol hilang
 * begitu kios sudah punya titipan).
 */
class KioskOpeningBalanceActionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area Saldo', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);
    }

    public function test_action_visible_and_creates_saldo_awal_for_kiosk_without_pending(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        Livewire::test(ListKiosks::class)
            ->assertTableActionVisible('set_opening_balance', $kiosk)
            ->callTableAction('set_opening_balance', $kiosk, data: ['opening_balance_mika' => 4])
            ->assertHasNoTableActionErrors();

        $pending = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first();
        $this->assertNotNull($pending);
        $this->assertSame('consignment', $pending->delivery_type);
        $this->assertSame(4, (int) $pending->qty_delivered);
    }

    /**
     * REGRESI 500: dulu trip migrasi memakai tanggal KEMARIN + number 1 → bentrok
     * dengan trip operasional owner #1 kemarin (unique owner_id,trip_date,number) →
     * UniqueConstraintViolation → 500 di produksi tiap owner sudah jalan trip kemarin.
     * Sekarang trip migrasi pakai tanggal sentinel masa lampau, tak pernah bentrok.
     */
    public function test_set_saldo_awal_does_not_500_when_owner_has_real_trip_yesterday(): void
    {
        $operator = User::factory()->create([
            'role' => 'operator', 'owner_id' => $this->owner->id, 'is_active' => true,
        ]);

        // Trip operasional NYATA kemarin, nomor 1 — kondisi produksi.
        \App\Models\Trip::create([
            'owner_id' => $this->owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today()->subDay()->toDateString(),
            'trip_number_of_day' => 1,
            'started_at' => today()->subDay(),
            'ended_at' => today()->subDay(),
            'qty_carried_total' => 10,
        ]);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        Livewire::test(ListKiosks::class)
            ->callTableAction('set_opening_balance', $kiosk, data: ['opening_balance_mika' => 4])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->first());
    }

    public function test_action_hidden_when_kiosk_already_has_pending_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        OpeningBalance::create($kiosk, 3); // sudah punya titipan berjalan

        Livewire::test(ListKiosks::class)
            ->assertTableActionHidden('set_opening_balance', $kiosk);
    }
}

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

    public function test_action_hidden_when_kiosk_already_has_pending_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        OpeningBalance::create($kiosk, 3); // sudah punya titipan berjalan

        Livewire::test(ListKiosks::class)
            ->assertTableActionHidden('set_opening_balance', $kiosk);
    }
}

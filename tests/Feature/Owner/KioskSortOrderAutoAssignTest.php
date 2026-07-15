<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk as OwnerCreateKiosk;
use App\Filament\Resources\KioskResource\Pages\EditKiosk;
use App\Livewire\Operator\CreateKiosk as OperatorCreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * sort_order OTOMATIS = urutan TERAKHIR di cluster kalau tidak diisi (kosong ≠ NULL).
 * Owner isi eksplisit → dihormati + auto-reflow. Pindah cluster → terakhir di cluster baru.
 */
class KioskSortOrderAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $operator;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id]);
        $this->cluster = Cluster::create(['name' => 'Area Urut', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    private function seedKiosks(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => $i]);
        }
    }

    // ---------- OPERATOR create-kiosk ----------

    public function test_operator_create_without_order_gets_last_in_cluster(): void
    {
        $this->seedKiosks(2); // sort_order 1,2

        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);
        Livewire::test(OperatorCreateKiosk::class)
            ->set('namaKios', 'Kios Operator Akhir')
            ->set('namaPemilik', 'Pak O')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 2)
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kios Operator Akhir')->firstOrFail();
        $this->assertSame(3, (int) $kiosk->sort_order);
        $this->assertNotNull($kiosk->sort_order);
    }

    public function test_operator_create_in_empty_cluster_gets_one(): void
    {
        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);
        Livewire::test(OperatorCreateKiosk::class)
            ->set('namaKios', 'Kios Pertama')
            ->set('namaPemilik', 'Pak P')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'cash_only')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kios Pertama')->firstOrFail();
        $this->assertSame(1, (int) $kiosk->sort_order);
    }

    // ---------- OWNER Filament create ----------

    public function test_owner_create_without_order_gets_last(): void
    {
        $this->seedKiosks(3); // 1,2,3
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(OwnerCreateKiosk::class)
            ->fillForm([
                'name' => 'Kios Owner Akhir',
                'cluster_id' => $this->cluster->id,
                'jenis_kedai' => 'konsinyasi',
                'default_qty_mika' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kios Owner Akhir')->firstOrFail();
        $this->assertSame(4, (int) $kiosk->sort_order);
    }

    public function test_owner_create_in_empty_cluster_gets_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(OwnerCreateKiosk::class)
            ->fillForm([
                'name' => 'Kios Owner Satu',
                'cluster_id' => $this->cluster->id,
                'jenis_kedai' => 'cash_only',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kios Owner Satu')->firstOrFail();
        $this->assertSame(1, (int) $kiosk->sort_order);
    }

    /**
     * Field "Urutan Rute" DIBUANG dari form create/edit (15 Juli 2026): owner tak
     * memakainya saat input. Konsekuensinya kios baru SELALU jadi TERAKHIR di area-nya
     * dan kios lain TIDAK tergeser — tak ada lagi jalur "sisip di posisi N" saat create
     * (menyisipkan tetap bisa BELAKANGAN lewat kolom "Urutan" di daftar → diuji
     * KioskSortOrderReflowTest).
     */
    public function test_owner_create_form_has_no_sort_order_field(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(OwnerCreateKiosk::class)
            ->assertFormFieldDoesNotExist('sort_order');
    }

    public function test_owner_edit_form_has_no_sort_order_field(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => 2]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(EditKiosk::class, ['record' => $kiosk->id])
            ->assertFormFieldDoesNotExist('sort_order');

        // Urutan yang sudah ada TIDAK ikut hilang cuma karena field-nya dibuang dari form.
        $this->assertSame(2, (int) $kiosk->refresh()->sort_order);
    }

    public function test_owner_create_appends_last_without_shifting_others(): void
    {
        $a = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => 1, 'name' => 'A']);
        $b = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => 2, 'name' => 'B']);
        $c = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => 3, 'name' => 'C']);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(OwnerCreateKiosk::class)
            ->fillForm([
                'name' => 'Kios Baru',
                'cluster_id' => $this->cluster->id,
                'jenis_kedai' => 'cash_only',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $new = Kiosk::where('name', 'Kios Baru')->firstOrFail();
        $this->assertSame(4, (int) $new->sort_order); // terakhir di area

        // Kios lama tetap di posisinya — tak ada reflow saat create.
        $this->assertSame(1, (int) $a->refresh()->sort_order);
        $this->assertSame(2, (int) $b->refresh()->sort_order);
        $this->assertSame(3, (int) $c->refresh()->sort_order);

        $orders = Kiosk::where('cluster_id', $this->cluster->id)->pluck('sort_order')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4], $orders); // gapless, tak ada duplikat
    }

    // ---------- EDIT pindah cluster ----------

    public function test_moving_kiosk_to_other_cluster_gets_last_there(): void
    {
        $other = Cluster::create(['name' => 'Area Lain', 'owner_id' => $this->owner->id]);
        Kiosk::factory()->create(['cluster_id' => $other->id, 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $other->id, 'sort_order' => 2]);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'sort_order' => 5]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);

        Livewire::test(EditKiosk::class, ['record' => $kiosk->id])
            ->fillForm(['cluster_id' => $other->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $kiosk->refresh();
        $this->assertSame($other->id, (int) $kiosk->cluster_id);
        $this->assertSame(3, (int) $kiosk->sort_order); // terakhir di cluster baru (MAX 2 + 1)
    }

    public function test_no_kiosk_left_with_null_sort_order_after_create(): void
    {
        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);
        Livewire::test(OperatorCreateKiosk::class)
            ->set('namaKios', 'Kios Cek Null')
            ->set('namaPemilik', 'Pak N')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'cash_only')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $this->assertSame(0, Kiosk::where('cluster_id', $this->cluster->id)->whereNull('sort_order')->count());
    }
}

<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk as OwnerCreateKiosk;
use App\Http\Controllers\Owner\TripExportController;
use App\Livewire\Operator\CreateKiosk as OperatorCreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fix temuan audit B9 (docs/UI_INVENTORY.md): business_name + 5 field default
 * kedai baru sudah ada kolom/accessor-nya tapi tidak punya form input. Test ini
 * menutup gap tersebut: persist & reload, pengaruh ke header/export/kios baru
 * (owner & operator), validasi silang, dan isolasi antar-owner.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'owner',
            'is_active' => true,
        ], $overrides));
    }

    public function test_settings_page_shows_existing_defaults_when_unset(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get('/owner/settings')
            ->assertOk()
            ->assertSee('Cemilan Qontas')
            ->assertSee('value="10"', false)
            ->assertSee('value="14"', false)
            ->assertSee('value="7"', false)
            ->assertSee('value="30"', false)
            ->assertSee('value="1"', false);
    }

    public function test_update_persists_all_fields_and_reloads(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put('/owner/settings', [
                'business_name' => 'Dodol Mantap Jaya',
                'harga_mika' => 200,
                'komisi_per_mika' => 500,
                'komisi_kios_baru_per_mika' => 1000,
                'default_target_visit_interval_days' => 8,
                'default_warning_visit_interval_days' => 12,
                'default_fast_mover_threshold_days' => 5,
                'kios_baru_days' => 20,
                'default_qty_mika' => 3,
            ])
            ->assertRedirect(route('owner.settings'))
            ->assertSessionHas('status');

        $owner->refresh();
        $this->assertSame('Dodol Mantap Jaya', $owner->business_name);
        $this->assertSame(8, (int) $owner->default_target_visit_interval_days);
        $this->assertSame(12, (int) $owner->default_warning_visit_interval_days);
        $this->assertSame(5, (int) $owner->default_fast_mover_threshold_days);
        $this->assertSame(20, (int) $owner->kios_baru_days);
        $this->assertSame(3, (int) $owner->default_qty_mika);

        // Reload halaman harus menunjukkan nilai yang baru disimpan.
        $this->actingAs($owner)
            ->get('/owner/settings')
            ->assertOk()
            ->assertSee('Dodol Mantap Jaya')
            ->assertSee('value="8"', false)
            ->assertSee('value="12"', false)
            ->assertSee('value="5"', false)
            ->assertSee('value="20"', false)
            ->assertSee('value="3"', false);
    }

    public function test_empty_business_name_falls_back_to_default(): void
    {
        $owner = $this->makeOwner(['business_name' => 'Nama Lama']);

        $this->actingAs($owner)
            ->put('/owner/settings', $this->validPayload(['business_name' => '']))
            ->assertRedirect(route('owner.settings'));

        $owner->refresh();
        $this->assertNull($owner->business_name);
        $this->assertSame('Cemilan Qontas', $owner->getBusinessNameValue());
    }

    public function test_validation_rejects_warning_less_than_target(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)
            ->put('/owner/settings', $this->validPayload([
                'default_target_visit_interval_days' => 15,
                'default_warning_visit_interval_days' => 10,
            ]));

        $response->assertSessionHasErrors([
            'default_warning_visit_interval_days' => 'Peringatan belum dikunjungi (hari) harus lebih besar atau sama dengan Ideal dikunjungi tiap (hari).',
        ]);

        $owner->refresh();
        // Tak ada perubahan yang tersimpan karena validasi gagal.
        $this->assertSame(10, (int) $owner->getDefaultTargetVisitIntervalDaysValue());
        $this->assertSame(14, (int) $owner->getDefaultWarningVisitIntervalDaysValue());
    }

    public function test_validation_accepts_warning_equal_to_target(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put('/owner/settings', $this->validPayload([
                'default_target_visit_interval_days' => 10,
                'default_warning_visit_interval_days' => 10,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('owner.settings'));
    }

    public function test_validation_rejects_values_out_of_bounds(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put('/owner/settings', $this->validPayload(['default_qty_mika' => 101]))
            ->assertSessionHasErrors('default_qty_mika');

        $this->actingAs($owner)
            ->put('/owner/settings', $this->validPayload(['kios_baru_days' => 400]))
            ->assertSessionHasErrors('kios_baru_days');
    }

    public function test_business_name_appears_in_owner_dashboard_header(): void
    {
        $owner = $this->makeOwner(['business_name' => 'Kios Barokah Jaya']);

        $this->actingAs($owner)
            ->get('/owner/dashboard')
            ->assertOk()
            ->assertSee('Kios Barokah Jaya');
    }

    public function test_business_name_flows_into_trip_pdf_data(): void
    {
        $owner = $this->makeOwner(['business_name' => 'Dodol Makmur PDF']);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);
        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
        ]);

        $data = TripExportController::buildTripData($trip);

        $this->assertSame('Dodol Makmur PDF', $data['businessName']);
    }

    public function test_owner_kiosk_create_uses_all_custom_defaults_and_persists_them(): void
    {
        $owner = $this->makeOwner([
            'default_target_visit_interval_days' => 8,
            'default_warning_visit_interval_days' => 12,
            'default_fast_mover_threshold_days' => 5,
            'default_qty_mika' => 4,
        ]);
        $cluster = Cluster::create(['name' => 'Area Default', 'owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($owner);

        Livewire::test(OwnerCreateKiosk::class)
            ->assertFormSet([
                'default_qty_mika' => 4,
                'target_visit_interval_days' => 8,
                'warning_visit_interval_days' => 12,
                'fast_mover_threshold_days' => 5,
            ])
            ->fillForm([
                'name' => 'Kios Default Owner',
                'cluster_id' => $cluster->id,
                'jenis_kedai' => 'konsinyasi',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kios Default Owner')->firstOrFail();
        $this->assertSame(4, (int) $kiosk->default_qty_mika);
        $this->assertSame(8, (int) $kiosk->target_visit_interval_days);
        $this->assertSame(12, (int) $kiosk->warning_visit_interval_days);
        $this->assertSame(5, (int) $kiosk->fast_mover_threshold_days);
    }

    public function test_operator_kiosk_create_uses_all_custom_defaults_and_persists_them(): void
    {
        $owner = $this->makeOwner([
            'default_target_visit_interval_days' => 9,
            'default_warning_visit_interval_days' => 15,
            'default_fast_mover_threshold_days' => 6,
            'default_qty_mika' => 5,
        ]);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);
        $cluster = Cluster::create(['name' => 'Area Operator', 'owner_id' => $owner->id]);

        $this->actingAs($operator);

        Livewire::test(OperatorCreateKiosk::class)
            ->assertSet('defaultQtyMika', 5)
            ->set('namaKios', 'Kios Default Operator')
            ->set('namaPemilik', 'Bu Default')
            ->set('clusterId', $cluster->id)
            ->set('jenisKedai', 'booking')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kios Default Operator')->firstOrFail();
        // Tanpa trip aktif, kios operator menjadi booking sehingga jatah belum disimpan;
        // defaultQtyMika tetap sudah terbukti ter-prefill dari Settings di atas.
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertSame(9, (int) $kiosk->target_visit_interval_days);
        $this->assertSame(15, (int) $kiosk->warning_visit_interval_days);
        $this->assertSame(6, (int) $kiosk->fast_mover_threshold_days);
    }

    public function test_other_owners_defaults_are_unaffected(): void
    {
        $ownerA = $this->makeOwner();
        $ownerB = $this->makeOwner();

        $this->actingAs($ownerA)
            ->put('/owner/settings', $this->validPayload([
                'business_name' => 'Punya Owner A',
                'default_qty_mika' => 9,
                'kios_baru_days' => 60,
            ]))
            ->assertRedirect(route('owner.settings'));

        $ownerB->refresh();
        $this->assertNull($ownerB->business_name);
        $this->assertSame('Cemilan Qontas', $ownerB->getBusinessNameValue());
        $this->assertSame(1, (int) $ownerB->getDefaultQtyMikaValue());
        $this->assertSame(30, (int) $ownerB->getKiosBaruDaysValue());
    }

    public function test_operator_cannot_access_owner_settings_route(): void
    {
        $owner = $this->makeOwner();
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $this->actingAs($operator)
            ->put('/owner/settings', $this->validPayload())
            ->assertForbidden();
    }

    /**
     * Payload lengkap & valid untuk PUT /owner/settings, dengan override opsional
     * per-field supaya test lain tetap singkat & jelas apa yang sedang diuji.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Cemilan Qontas',
            'harga_mika' => 200,
            'komisi_per_mika' => 500,
            'komisi_kios_baru_per_mika' => 1000,
            'default_target_visit_interval_days' => 10,
            'default_warning_visit_interval_days' => 14,
            'default_fast_mover_threshold_days' => 7,
            'kios_baru_days' => 30,
            'default_qty_mika' => 1,
        ], $overrides);
    }
}

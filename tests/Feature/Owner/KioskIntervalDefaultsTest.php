<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk as OwnerCreateKiosk;
use App\Filament\Resources\KioskResource\Pages\EditKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DEFAULT tiga field interval di form kios BARU (angka dari owner, 15 Juli 2026):
 * ideal 10 hari, peringatan 14 hari, laris 7 hari. Prefilled saat CREATE (owner boleh
 * ubah) dan TIDAK dipaksakan ke kios LAMA — form edit selalu diisi dari record.
 */
class KioskIntervalDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area Default', 'owner_id' => $this->owner->id]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);
    }

    public function test_create_form_is_prefilled_with_owner_defaults(): void
    {
        Livewire::test(OwnerCreateKiosk::class)
            ->assertFormSet([
                'target_visit_interval_days' => 10,
                'warning_visit_interval_days' => 14,
                'fast_mover_threshold_days' => 7,
            ]);
    }

    public function test_defaults_are_persisted_when_owner_does_not_touch_them(): void
    {
        Livewire::test(OwnerCreateKiosk::class)
            ->fillForm([
                'name' => 'Kios Default',
                'cluster_id' => $this->cluster->id,
                'jenis_kedai' => 'cash_only',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kios Default')->firstOrFail();
        $this->assertSame(10, (int) $kiosk->target_visit_interval_days);
        $this->assertSame(14, (int) $kiosk->warning_visit_interval_days);
        $this->assertSame(7, (int) $kiosk->fast_mover_threshold_days);
    }

    public function test_owner_can_still_override_the_defaults(): void
    {
        Livewire::test(OwnerCreateKiosk::class)
            ->fillForm([
                'name' => 'Kios Custom',
                'cluster_id' => $this->cluster->id,
                'jenis_kedai' => 'cash_only',
                'target_visit_interval_days' => 3,
                'warning_visit_interval_days' => 5,
                'fast_mover_threshold_days' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Kios Custom')->firstOrFail();
        $this->assertSame(3, (int) $kiosk->target_visit_interval_days);
        $this->assertSame(5, (int) $kiosk->warning_visit_interval_days);
        $this->assertSame(2, (int) $kiosk->fast_mover_threshold_days);
    }

    /** Kios LAMA tak boleh ikut berubah cuma karena default form diubah. */
    public function test_existing_kiosk_keeps_its_own_values_on_edit(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 21,
            'warning_visit_interval_days' => 30,
            'fast_mover_threshold_days' => null,
        ]);

        Livewire::test(EditKiosk::class, ['record' => $kiosk->id])
            ->assertFormSet([
                'target_visit_interval_days' => 21,
                'warning_visit_interval_days' => 30,
                'fast_mover_threshold_days' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $kiosk->refresh();
        $this->assertSame(21, (int) $kiosk->target_visit_interval_days);
        $this->assertSame(30, (int) $kiosk->warning_visit_interval_days);
        $this->assertNull($kiosk->fast_mover_threshold_days);
    }
}

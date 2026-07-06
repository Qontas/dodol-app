<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bukti fix "peta owner tak gerak saat Loncat ke lokasi" (dotswan/filament-map-picker
 * -> Leaflet hand-rolled): tombol "Loncat ke lokasi" (suffixAction TextInput
 * maps_paste_input) men-$set('location', [...]). Test ini memverifikasi hasil akhir
 * lat/lng yang TERSIMPAN ke DB benar & tidak tertukar — pergerakan peta di browser
 * sendiri sudah dibuktikan lewat repro headed Chrome asli (di luar test suite).
 */
class KioskMapJumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_set_location_via_pasted_link_persists_correct_coordinates(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        $cluster = Cluster::create(['name' => 'Area Kios', 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($owner);

        Livewire::test(CreateKiosk::class)
            ->fillForm([
                'name' => 'Sippin Milk & Tea',
                'cluster_id' => $cluster->id,
                'maps_paste_input' => '3.6157078, 98.6758666',
            ])
            ->callFormComponentAction('maps_paste_input', 'jumpToMapsLocation')
            ->assertHasNoFormComponentActionErrors()
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::where('name', 'Sippin Milk & Tea')->first();

        $this->assertNotNull($kiosk, 'Kiosk harus tersimpan.');
        $this->assertEqualsWithDelta(
            3.6157078,
            $kiosk->latitude,
            0.0000010,
            'Latitude Medan (~3.6) harus tersimpan benar — bukan tertukar dgn longitude (~98.6).',
        );
        $this->assertEqualsWithDelta(
            98.6758666,
            $kiosk->longitude,
            0.0000010,
            'Longitude Medan (~98.6) harus tersimpan benar.',
        );
    }
}

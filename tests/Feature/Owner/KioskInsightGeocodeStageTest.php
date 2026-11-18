<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use App\Support\KioskInsightExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Tahap awal export "Melengkapi alamat": mengisi kiosks.maps_address yang kosong lewat ReverseGeocoder, tanpa menggagalkan export. */
class KioskInsightGeocodeStageTest extends TestCase
{
    use RefreshDatabase;

    private string $lockPath;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->lockPath = tempnam(sys_get_temp_dir(), 'export-geo-');
        config([
            'reverse_geocoding.provider' => 'nominatim',
            'reverse_geocoding.user_agent' => 'GeocoderTest/1.0',
            'reverse_geocoding.contact_email' => 'public-service@example.test',
            'reverse_geocoding.lock_path' => $this->lockPath,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            File::deleteDirectory(storage_path('app/private/kiosk-insight-exports/'.$id));
        }
        @unlink($this->lockPath);
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function kiosk(User $owner, array $attributes = []): Kiosk
    {
        return Kiosk::factory()->create(array_merge([
            'cluster_id' => Cluster::factory()->create(['owner_id' => $owner->id])->id,
            'latitude' => 3.6,
            'longitude' => 98.6,
        ], $attributes));
    }

    private function start(User $owner, string $format = 'excel'): string
    {
        $this->actingAs($owner);
        $id = KioskInsightExport::start($format, $owner->id);
        $this->ids[] = $id;

        return $id;
    }

    private function nominatim(string $road = 'Jalan Otomatis'): void
    {
        Http::fake(['*' => Http::response(['address' => ['road' => $road, 'city' => 'Medan']])]);
    }

    private function sheetText(string $id): string
    {
        $path = storage_path('app/private/kiosk-insight-exports/'.$id.'/report.xlsx');
        $text = '';
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                $text .= ' '.implode(' ', array_map('strval', $row));
            }
        }

        return $text;
    }

    public function test_kedai_tanpa_alamat_terisi_saat_export_dan_masuk_ke_laporan(): void
    {
        $owner = $this->owner();
        $kiosk = $this->kiosk($owner);
        $this->nominatim();

        $id = $this->start($owner);
        $status = KioskInsightExport::status($id);
        $this->assertSame('addresses', $status['stage']);
        $this->assertSame('Melengkapi alamat 0/1…', $status['message']);

        $progress = KioskInsightExport::tick($id);
        $this->assertSame('write', $progress['stage']);
        $this->assertSame('Jalan Otomatis, Medan', $kiosk->fresh()->maps_address);
        $this->assertSame('nominatim', $kiosk->fresh()->maps_address_source);
        $this->assertNotNull($kiosk->fresh()->maps_address_at);

        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        $this->assertStringContainsString('Jalan Otomatis, Medan', $this->sheetText($id));
        Http::assertSentCount(1);
    }

    public function test_batas_per_tick_dan_total_per_export(): void
    {
        $this->assertSame(6, config('reverse_geocoding.export_per_tick'));
        $this->assertSame(60, config('reverse_geocoding.export_max'));
        config(['reverse_geocoding.export_per_tick' => 2, 'reverse_geocoding.export_max' => 3]);
        $owner = $this->owner();
        foreach (range(1, 5) as $ignored) {
            $this->kiosk($owner);
        }
        $this->nominatim();

        $id = $this->start($owner);
        $this->assertSame('Melengkapi alamat 0/3…', KioskInsightExport::status($id)['message']);

        $first = KioskInsightExport::tick($id);
        $this->assertSame('addresses', $first['stage']);
        $this->assertSame('Melengkapi alamat 2/3…', $first['message']);
        $this->assertSame(2, Kiosk::whereNotNull('maps_address')->count());

        $second = KioskInsightExport::tick($id);
        $this->assertSame('write', $second['stage']);
        $this->assertSame(3, Kiosk::whereNotNull('maps_address')->count());
        // Sisanya (urutan baris laporan) dilewati dan diisi di export berikutnya.
        $this->assertSame(2, Kiosk::whereNull('maps_address')->count());
        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        Http::assertSentCount(3);
    }

    public function test_provider_gagal_tidak_menggagalkan_export(): void
    {
        Log::spy();
        $owner = $this->owner();
        $kiosk = $this->kiosk($owner);
        Http::fake(['*' => Http::response([], 429)]);

        $id = $this->start($owner);
        $progress = KioskInsightExport::tick($id);
        $this->assertSame('write', $progress['stage']);
        $this->assertFalse($progress['failed']);
        $this->assertNull($kiosk->fresh()->maps_address);
        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'KIOSK_INSIGHT_GEOCODE_SKIPPED'))->once();
        Http::assertSentCount(1);
    }

    public function test_timeout_koneksi_dilewati_tanpa_membocorkan_pesan_ke_log(): void
    {
        Log::spy();
        $owner = $this->owner();
        $this->kiosk($owner);
        $this->kiosk($owner);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('https://provider.invalid?key=secret-api-key');
        });

        $id = $this->start($owner);
        $this->assertSame('write', KioskInsightExport::tick($id)['stage']);
        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        // Berhenti di kegagalan pertama: tanpa retry dan tanpa request lanjutan.
        $this->assertSame(1, $calls);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'KIOSK_INSIGHT_GEOCODE_SKIPPED') && ! str_contains((string) $message, 'secret-api-key'))->once();
    }

    public function test_tanpa_email_kontak_tahap_dilewati_dan_tidak_ada_request(): void
    {
        Log::spy();
        config(['reverse_geocoding.contact_email' => '']);
        Http::fake();
        $owner = $this->owner();
        $kiosk = $this->kiosk($owner);

        $id = $this->start($owner);
        $this->assertSame('write', KioskInsightExport::status($id)['stage']);
        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        $this->assertNull($kiosk->fresh()->maps_address);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'not_configured'))->once();
    }

    public function test_kedai_owner_lain_dan_yang_sudah_beralamat_atau_tanpa_koordinat_tidak_disentuh(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $target = $this->kiosk($owner);
        $foreign = $this->kiosk($other, ['latitude' => 4.6]);
        $filled = $this->kiosk($owner, ['latitude' => 5.6]);
        $filled->forceFill(['maps_address' => 'Alamat lama', 'maps_address_at' => now(), 'maps_address_source' => 'google'])->save();
        $noCoordinates = $this->kiosk($owner, ['latitude' => null, 'longitude' => null]);
        $this->nominatim();

        $id = $this->start($owner, 'pdf');
        KioskInsightExport::tick($id);

        $this->assertNotNull($target->fresh()->maps_address);
        $this->assertNull($foreign->fresh()->maps_address);
        $this->assertSame('Alamat lama', $filled->fresh()->maps_address);
        $this->assertNull($noCoordinates->fresh()->maps_address);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => (float) $request['lat'] === 3.6);
        $this->assertSame('photos', KioskInsightExport::status($id)['stage']);
    }

    public function test_semua_alamat_sudah_ada_tidak_menambah_tahap_atau_request(): void
    {
        Http::fake();
        $owner = $this->owner();
        $kiosk = $this->kiosk($owner);
        $kiosk->forceFill(['maps_address' => 'Sudah ada', 'maps_address_at' => now(), 'maps_address_source' => 'google'])->save();

        $id = $this->start($owner);
        $this->assertSame('write', KioskInsightExport::status($id)['stage']);
        Http::assertNothingSent();
    }
}

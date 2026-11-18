<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FillKioskMapsAddressTest extends TestCase
{
    use RefreshDatabase;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->lockPath = tempnam(sys_get_temp_dir(), 'maps-address-');
        config([
            'reverse_geocoding.provider' => 'nominatim',
            'reverse_geocoding.user_agent' => 'GeocoderTest/1.0',
            'reverse_geocoding.contact_email' => 'public-service@example.test',
            'reverse_geocoding.lock_path' => $this->lockPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        parent::tearDown();
    }

    private function kiosk(User $owner, array $attributes = []): Kiosk
    {
        return Kiosk::factory()->create(array_merge([
            'cluster_id' => Cluster::factory()->create(['owner_id' => $owner->id])->id,
            'latitude' => 3.6,
            'longitude' => 98.6,
            'location_description' => 'Petunjuk manual jangan ditimpa',
        ], $attributes));
    }

    private function cacheAddress(Kiosk $kiosk): void
    {
        $kiosk->forceFill([
            'maps_address' => 'Alamat lama',
            'maps_address_at' => now()->subDay(),
            'maps_address_source' => 'nominatim',
        ])->save();
    }

    public function test_owner_and_limit_are_required_valid_and_never_send_http_on_error(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $this->artisan('kiosks:fill-maps-address')->assertFailed();
        foreach (['all', '0', '-1', $operator->id, 999999] as $id) {
            $this->artisan('kiosks:fill-maps-address', ['--owner' => $id])->assertFailed();
        }
        foreach (['0', '-1', '1001', 'abc'] as $limit) {
            $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--limit' => $limit])->assertFailed();
        }
        Http::assertNothingSent();
    }

    public function test_default_dry_run_calls_geocoder_shows_example_and_writes_no_database_rows(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        $before = $kiosk->fresh()->getRawOriginal();
        Http::fake(['*' => Http::response(['address' => ['road' => 'Jalan Contoh, Medan']])]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('Jalan Contoh, Medan')
            ->expectsOutputToContain('tersimpan=0')
            ->assertSuccessful();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        Http::assertSentCount(1);
        $this->assertSame($before, $kiosk->fresh()->getRawOriginal());
    }

    public function test_force_persists_metadata_without_changing_manual_address_and_rerun_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        $updatedAt = $kiosk->getRawOriginal('updated_at');
        Http::fake(['*' => Http::response(['address' => ['road' => 'Alamat dari Maps']])]);
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('tersimpan=1')->assertSuccessful();
        $kiosk->refresh();
        $this->assertSame('Alamat dari Maps', $kiosk->maps_address);
        $this->assertSame('nominatim', $kiosk->maps_address_source);
        $this->assertNotNull($kiosk->maps_address_at);
        $this->assertSame('Petunjuk manual jangan ditimpa', $kiosk->location_description);
        $this->assertSame($updatedAt, $kiosk->getRawOriginal('updated_at'));
        $before = $kiosk->getRawOriginal();
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('kedai=0')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame($before, $kiosk->fresh()->getRawOriginal());
    }

    public function test_only_explicit_owner_eligible_coordinates_are_processed_including_inactive(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $eligible = $this->kiosk($a, ['is_active' => false]);
        $otherOwner = $this->kiosk($b, ['latitude' => 4.6]);
        $noCluster = Kiosk::factory()->create(['latitude' => 5.6, 'longitude' => 98.6]);
        $missing = $this->kiosk($a, ['latitude' => null]);
        $invalid = $this->kiosk($a, ['latitude' => 91]);
        $walkIn = $this->kiosk($a, ['name' => Kiosk::WALKIN_SENTINEL_NAME]);
        $existing = $this->kiosk($a);
        $this->cacheAddress($existing);
        Http::fake(['*' => Http::response(['address' => ['road' => 'Alamat baru']])]);

        $this->artisan('kiosks:fill-maps-address', ['--owner' => $a->id, '--force' => true])
            ->expectsOutputToContain('kedai=1 sukses=1 tersimpan=1')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => (float) $request['lat'] === 3.6);
        $this->assertSame('Alamat baru', $eligible->fresh()->maps_address);
        foreach ([$otherOwner, $noCluster, $missing, $invalid, $walkIn] as $untouched) {
            $this->assertNull($untouched->fresh()->maps_address);
        }
        $this->assertSame('Alamat lama', $existing->fresh()->maps_address);
    }

    public function test_limit_applies_to_eligible_rows_and_zero_coordinates_are_valid(): void
    {
        $owner = User::factory()->create();
        $this->kiosk($owner, ['longitude' => null]);
        $first = $this->kiosk($owner, ['latitude' => 0, 'longitude' => 0]);
        $second = $this->kiosk($owner);
        Http::fake(['*' => Http::response(['address' => ['road' => 'Zero coordinates']])]);
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--limit' => 1, '--force' => true])
            ->expectsOutputToContain('kedai=1')->assertSuccessful();
        $this->assertSame('Zero coordinates', $first->fresh()->maps_address);
        $this->assertNull($second->fresh()->maps_address);
        Http::assertSentCount(1);
    }

    public function test_coordinate_changes_clear_all_maps_fields_even_without_price_tier_and_no_http(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        foreach ([['latitude' => 3.7], ['longitude' => 98.7], ['latitude' => null]] as $change) {
            $this->cacheAddress($kiosk);
            $kiosk->update($change);
            $kiosk->refresh();
            $this->assertNull($kiosk->maps_address);
            $this->assertNull($kiosk->maps_address_at);
            $this->assertNull($kiosk->maps_address_source);
        }
        Http::assertNothingSent();
    }

    public function test_create_clears_injected_maps_cache_without_http(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $kiosk = new Kiosk;
        $kiosk->forceFill([
            'name' => 'Kios baru', 'cluster_id' => $cluster->id,
            'latitude' => 3.6, 'longitude' => 98.6,
            'maps_address' => 'Tidak dapat dipercaya', 'maps_address_at' => now(), 'maps_address_source' => 'google',
        ])->save();
        $this->assertNull($kiosk->fresh()->maps_address);
        $this->assertNull($kiosk->fresh()->maps_address_at);
        $this->assertNull($kiosk->fresh()->maps_address_source);
        Http::assertNothingSent();
    }

    public function test_unchanged_coordinates_and_unrelated_edits_keep_cache_without_http(): void
    {
        Http::fake();
        $kiosk = $this->kiosk(User::factory()->create());
        $this->cacheAddress($kiosk);
        $at = $kiosk->maps_address_at->toDateTimeString();
        $kiosk->update(['latitude' => '3.6000000', 'longitude' => 98.6, 'name' => 'Nama berubah', 'location_description' => 'Manual berubah']);
        $this->assertSame('Alamat lama', $kiosk->fresh()->maps_address);
        $this->assertSame($at, $kiosk->fresh()->maps_address_at->toDateTimeString());
        $this->assertSame('nominatim', $kiosk->fresh()->maps_address_source);
        Http::assertNothingSent();
    }

    public function test_coordinate_edit_during_geocoding_rejects_stale_response(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        Http::fake(function () use ($kiosk) {
            $kiosk->update(['latitude' => 3.8]);

            return Http::response(['address' => ['road' => 'Alamat koordinat lama']]);
        });
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('tersimpan=0 gagal=0 berubah=1')->assertSuccessful();
        $this->assertNull($kiosk->fresh()->maps_address);
        Http::assertSentCount(1);
    }

    public function test_cluster_owner_change_during_geocoding_rejects_response(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        Http::fake(function () use ($kiosk, $other) {
            $kiosk->cluster->update(['owner_id' => $other->id]);

            return Http::response(['address' => ['road' => 'Tidak boleh disimpan']]);
        });
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('berubah=1')->assertSuccessful();
        $this->assertNull($kiosk->fresh()->maps_address);
    }

    public function test_concurrent_cache_fill_is_not_overwritten(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        Http::fake(function () use ($kiosk) {
            $this->cacheAddress($kiosk);

            return Http::response(['address' => ['road' => 'Jangan timpa']]);
        });
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('berubah=1')->assertSuccessful();
        $this->assertSame('Alamat lama', $kiosk->fresh()->maps_address);
    }

    public function test_provider_failure_does_not_create_negative_cache_or_retry(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('gagal=1')->assertFailed();
        $this->assertNull($kiosk->fresh()->maps_address);
        $this->assertNull($kiosk->fresh()->maps_address_at);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_stops_remaining_requests_and_still_prints_summary(): void
    {
        $owner = User::factory()->create();
        $first = $this->kiosk($owner);
        $second = $this->kiosk($owner);
        Http::fake(['*' => Http::response([], 429)]);
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->expectsOutputToContain('kedai=1 sukses=0 tersimpan=0 gagal=1')->assertFailed();
        Http::assertSentCount(1);
        $this->assertNull($first->fresh()->maps_address);
        $this->assertNull($second->fresh()->maps_address);
    }

    public function test_exception_never_prints_provider_key_or_writes_cache(): void
    {
        $owner = User::factory()->create();
        $kiosk = $this->kiosk($owner);
        Http::fake(fn () => throw new ConnectionException('https://provider.invalid?key=secret-api-key'));
        $this->artisan('kiosks:fill-maps-address', ['--owner' => $owner->id, '--force' => true])
            ->doesntExpectOutputToContain('secret-api-key')->assertFailed();
        $this->assertNull($kiosk->fresh()->maps_address);
    }
}

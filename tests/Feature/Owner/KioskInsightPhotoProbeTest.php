<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KioskInsightPhotoProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_requires_an_owner_and_valid_limit(): void
    {
        $this->artisan('kiosks:insight-photo-probe')->assertFailed();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->artisan('kiosks:insight-photo-probe', ['--owner' => $owner->id, '--limit' => 101])->assertFailed();
    }

    public function test_probe_only_reads_selected_owners_photos_and_never_writes_cache_or_business_data(): void
    {
        Storage::fake('probe');
        config(['app.media_disk' => 'probe']);
        $a = User::factory()->create(['role' => 'owner']);
        $b = User::factory()->create(['role' => 'owner']);
        $ca = Cluster::factory()->create(['owner_id' => $a->id]);
        $cb = Cluster::factory()->create(['owner_id' => $b->id]);
        $ka = Kiosk::factory()->create(['cluster_id' => $ca->id, 'photo_path' => 'kiosks/a.jpg']);
        $kb = Kiosk::factory()->create(['cluster_id' => $cb->id, 'photo_path' => 'kiosks/b.jpg']);
        Storage::disk('probe')->put('kiosks/a.jpg', 'photo-a');
        Storage::disk('probe')->put('kiosks/b.jpg', 'photo-b');
        $before = Storage::disk('probe')->allFiles();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->artisan('kiosks:insight-photo-probe', ['--owner' => $a->id, '--limit' => 20])
            ->expectsOutputToContain('kedai #'.$ka->id)
            ->doesntExpectOutputToContain('kedai #'.$kb->id.' ')
            ->expectsOutputToContain('foto=1 sukses=1 timeout=0 gagal_lain=0')
            ->expectsOutputToContain('median=')
            ->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertSame($before, Storage::disk('probe')->allFiles());
    }
}

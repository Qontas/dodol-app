<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Support\KioskInsightExport;
use App\Support\KioskInsightThumbnail;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real sockets/cURL/AWS retries, deliberately no DB fixtures or RefreshDatabase. */
class KioskInsightPhotoDeadlineTest extends TestCase
{
    private array $servers = [];

    private array $directories = [];

    private array $locals = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        foreach ($this->locals as $local) {
            File::delete($local);
        }
        parent::tearDown();
    }

    private function server(string $mode): string
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('Real S3 transfer tests require curl.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $directory = storage_path('framework/testing/photo-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $this->directories[] = $directory;
        $process = new Process([PHP_BINARY, base_path('tests/Support/slow-s3-server.php'), (string) $port, $mode, $directory]);
        $process->start();
        $process->waitUntil(static fn ($type, $output) => str_contains($output, 'ready'));
        $this->servers[] = $process;
        config([
            'app.media_disk' => 'photo-deadline',
            'filesystems.disks.photo-deadline' => [
                'driver' => 's3', 'key' => 'fixture', 'secret' => 'fixture', 'region' => 'us-east-1',
                'bucket' => 'photos', 'endpoint' => "http://127.0.0.1:$port", 'use_path_style_endpoint' => true,
                'root' => 'tenant-prefix', 'retries' => 5,
            ],
        ]);

        return $directory;
    }

    private function row(string $name): array
    {
        $path = 'kiosks/'.Str::uuid().'-'.$name.'.jpg';
        $key = hash('sha256', config('app.media_disk').'|'.$path.'|160-q65-v2');
        $this->locals[] = storage_path('app/private/kiosk-insight-thumbs/'.$key.'.jpg');

        return ['photo_path' => $path, 'photo_status' => 'pending'];
    }

    private function requests(string $directory): array
    {
        $path = $directory.'/requests.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_map(static fn ($line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private function fastTimeouts(): void
    {
        config(['kiosk_insights.photo' => [
            'budget' => 3, 'concurrency' => 10, 'thumb_timeout' => 0.2,
            'source_timeout' => 0.35, 'put_timeout' => 0.2,
        ]]);
    }

    public function test_config_is_clamped_and_expired_deadline_never_starts_first_wave(): void
    {
        $directory = $this->server('hang');
        config(['kiosk_insights.photo' => [
            'budget' => 999, 'concurrency' => 999, 'thumb_timeout' => 999,
            'source_timeout' => 999, 'put_timeout' => 999, 'max_source_bytes' => PHP_INT_MAX,
        ]]);
        $settings = KioskInsightThumbnail::settings();
        $this->assertSame(8.5, KioskInsightThumbnail::budgetSeconds());
        $this->assertSame(10, $settings['concurrency']);
        $this->assertLessThan(KioskInsightThumbnail::budgetSeconds(), KioskInsightThumbnail::worstCaseSeconds());
        $row = KioskInsightThumbnail::prepareBatch([$this->row('never')], KioskInsightThumbnail::now() + 0.01)[0];
        $this->assertSame('Foto gagal dimuat', $row['photo_status']);
        $this->assertSame([], $this->requests($directory));
        config(['kiosk_insights.photo.budget' => 3]);
        $this->assertSame(3.0, KioskInsightThumbnail::budgetSeconds());
        $this->assertLessThan(3, KioskInsightThumbnail::worstCaseSeconds());
    }

    public function test_success_and_hanging_source_share_one_wave_and_retain_both_caches(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('mixed');
        $good = $this->row('good');
        $bad = $this->row('hang');
        $started = KioskInsightThumbnail::now();
        $out = KioskInsightThumbnail::prepareBatch([$good, $bad]);
        $this->assertLessThan(1.5, KioskInsightThumbnail::now() - $started);
        $this->assertSame('ok', $out[0]['photo_status']);
        $this->assertSame('Foto gagal dimuat', $out[1]['photo_status']);
        $this->assertFileExists($out[0]['thumbnail_path']);
        $requests = $this->requests($directory);
        $this->assertCount(5, $requests); // two GET cache, two GET source, one PUT
        $this->assertStringStartsWith('/photos/tenant-prefix/', $requests[0]['path']);
        File::delete($out[0]['thumbnail_path']);
        $remote = KioskInsightThumbnail::prepare($good);
        $this->assertSame('ok', $remote['photo_status']);
        $this->assertCount(6, $this->requests($directory)); // GET remote thumbnail only
        $this->assertSame('ok', KioskInsightThumbnail::prepare($good)['photo_status']);
        $this->assertCount(6, $this->requests($directory)); // warm local, no HTTP
        KioskInsightThumbnail::prepare($bad);
        $this->assertCount(8, $this->requests($directory)); // failures not negatively cached
    }

    public function test_real_503_is_attempted_once_per_operation_despite_inherited_retry_config(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('retry');
        $row = KioskInsightThumbnail::prepare($this->row('503'));
        $this->assertSame('Foto gagal dimuat', $row['photo_status']);
        $requests = $this->requests($directory);
        $this->assertCount(2, $requests); // one cache GET and one source GET, never SDK retries
        $this->assertCount(2, array_unique(array_column($requests, 'path')));
    }

    public function test_all_hanging_gets_use_phase_totals_not_per_photo_totals(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('hang');
        $rows = array_map(fn ($i) => $this->row('timeout-'.$i), range(1, 10));
        $started = KioskInsightThumbnail::now();
        $out = KioskInsightThumbnail::prepareBatch($rows);
        $elapsed = KioskInsightThumbnail::now() - $started;
        $this->assertGreaterThan(0.45, $elapsed);
        $this->assertLessThan(1.5, $elapsed);
        $this->assertSame(array_fill(0, 10, 'Foto gagal dimuat'), array_column($out, 'photo_status'));
        $this->assertCount(20, $this->requests($directory));
    }

    public function test_put_hang_does_not_discard_successful_thumbnail_and_drip_has_total_timeout(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('phases');
        $started = KioskInsightThumbnail::now();
        $out = KioskInsightThumbnail::prepare($this->row('good'));
        $this->assertSame('ok', $out['photo_status']);
        $this->assertLessThan(1.3, KioskInsightThumbnail::now() - $started);
        $this->assertCount(3, $this->requests($directory));
        $this->server('drip');
        $started = KioskInsightThumbnail::now();
        $this->assertSame('Foto gagal dimuat', KioskInsightThumbnail::prepare($this->row('drip'))['photo_status']);
        $this->assertLessThan(1.5, KioskInsightThumbnail::now() - $started);
    }

    public function test_no_content_length_body_and_huge_cached_thumbnail_are_bounded(): void
    {
        $this->fastTimeouts();
        $this->server('chunked');
        $started = KioskInsightThumbnail::now();
        $out = KioskInsightThumbnail::prepare($this->row('chunked'));
        $this->assertSame('Foto gagal dimuat', $out['photo_status']);
        $this->assertLessThan(1.5, KioskInsightThumbnail::now() - $started);
        $this->server('huge-thumb');
        $this->assertSame('ok', KioskInsightThumbnail::prepare($this->row('fallback-source'))['photo_status']);
    }

    public function test_corrupt_local_cache_is_repaired_and_local_put_exception_keeps_good_bytes(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('mixed');
        $row = $this->row('repair');
        $out = KioskInsightThumbnail::prepare($row);
        File::put($out['thumbnail_path'], 'corrupt');
        $this->assertSame('ok', KioskInsightThumbnail::prepare($row)['photo_status']);
        $this->assertNotFalse(getimagesize($out['thumbnail_path']));
        $this->assertCount(4, $this->requests($directory));
        $bytes = File::get($out['thumbnail_path']);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('readStream')->once()->with(\Mockery::pattern('~^thumbs/~'))->andReturn(false);
        $disk->shouldReceive('readStream')->once()->with(\Mockery::pattern('~^kiosks/~'))->andReturnUsing(static function () use ($bytes) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        });
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('cache unavailable'));
        config(['app.media_disk' => 'local-put-failure', 'filesystems.disks.local-put-failure.driver' => 'local']);
        Storage::shouldReceive('disk')->with('local-put-failure')->andReturn($disk);
        $this->assertSame('ok', KioskInsightThumbnail::prepare($this->row('local-put'))['photo_status']);
    }

    public function test_photo_poll_advances_cursor_with_failure_and_respects_default_budget(): void
    {
        $directory = $this->server('hang');
        // Default timings, real hangs. More than one wave ensures the cursor remains resumable.
        $owner = new User;
        $owner->forceFill(['id' => 987654, 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        $id = (string) Str::uuid();
        $path = storage_path('app/private/kiosk-insight-exports/'.$id);
        File::ensureDirectoryExists($path);
        $this->directories[] = $path;
        $rows = array_map(fn ($i) => $this->row('poll-'.$i), range(1, 20));
        File::put($path.'/state.json', json_encode([
            'creator_id' => $owner->id, 'owner_id' => $owner->id, 'format' => 'photos',
            'expires_at' => now()->addHour()->toIso8601String(), 'stage' => 'photos',
            'cursor' => 0, 'message' => 'pending', 'data' => ['rows' => $rows],
        ]));
        $started = KioskInsightThumbnail::now();
        $progress = KioskInsightExport::tick($id);
        $this->assertLessThan(10, KioskInsightThumbnail::now() - $started);
        $this->assertFalse($progress['failed']);
        $state = json_decode(File::get($path.'/state.json'), true);
        $this->assertSame(10, $state['cursor']);
        $this->assertSame('photos', $state['stage']);
        $this->assertCount(20, $this->requests($directory));
        $this->assertSame('Foto gagal dimuat', $state['data']['rows'][0]['photo_status']);
        $this->assertSame('pending', $state['data']['rows'][10]['photo_status']);
        KioskInsightExport::tick($id);
        $state = json_decode(File::get($path.'/state.json'), true);
        $this->assertSame('write', $state['stage']);
        $this->assertCount(40, $this->requests($directory));
    }

    public function test_legacy_source_over_four_megapixels_fails_without_caching_failure(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('large-image');
        $row = $this->row('legacy-large');
        $this->assertSame('Foto gagal dimuat', KioskInsightThumbnail::prepare($row)['photo_status']);
        $this->assertSame('Foto gagal dimuat', KioskInsightThumbnail::prepare($row)['photo_status']);
        $this->assertCount(4, $this->requests($directory));
        $this->assertFileDoesNotExist(end($this->locals));
    }

    public function test_opt_in_413_cold_and_warm_photo_polls_benchmark(): void
    {
        if (getenv('RUN_PHOTO_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set RUN_PHOTO_BENCHMARK=1 for the real 413-photo benchmark.');
        }
        $directory = $this->server('benchmark');
        $owner = new User;
        $owner->forceFill(['id' => 987654, 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        $rows = array_map(fn ($i) => $this->row('benchmark-'.$i), range(1, 413));
        foreach (['cold', 'warm'] as $temperature) {
            $id = (string) Str::uuid();
            $path = storage_path('app/private/kiosk-insight-exports/'.$id);
            File::ensureDirectoryExists($path);
            $this->directories[] = $path;
            File::put($path.'/state.json', json_encode([
                'creator_id' => $owner->id, 'owner_id' => $owner->id, 'format' => 'photos',
                'expires_at' => now()->addHour()->toIso8601String(), 'stage' => 'photos',
                'cursor' => 0, 'message' => 'pending', 'data' => ['rows' => $rows],
            ]));
            $started = KioskInsightThumbnail::now();
            $max = 0;
            $ticks = 0;
            do {
                $tick = KioskInsightThumbnail::now();
                $progress = KioskInsightExport::tick($id);
                $max = max($max, KioskInsightThumbnail::now() - $tick);
                $ticks++;
                $this->assertFalse($progress['failed']);
                $this->assertLessThan(180, KioskInsightThumbnail::now() - $started);
            } while ($progress['stage'] === 'photos');
            $state = json_decode(File::get($path.'/state.json'), true);
            $this->assertSame(array_fill(0, 413, 'ok'), array_column($state['data']['rows'], 'photo_status'));
            $wall = KioskInsightThumbnail::now() - $started;
            $this->assertLessThan(10, $max);
            fwrite(STDERR, sprintf("PHOTO_BENCHMARK %s photos=413 source=1600x1200 ticks=%d total=%.3fs max_tick=%.3fs plus_poll1s=%.3fs requests=%d peak_mb=%.1f\n", $temperature, $ticks, $wall, $max, $wall + $ticks, count($this->requests($directory)), memory_get_peak_usage(true) / 1048576));
        }
    }

    public function test_probe_uses_bounded_read_only_transfer_and_preserves_result_contract(): void
    {
        $this->fastTimeouts();
        $directory = $this->server('mixed');
        $result = KioskInsightThumbnail::probe(['kiosks/good.jpg', 'kiosks/hang.jpg'], true);
        $this->assertSame('sukses', $result['rows'][0]['status']);
        $this->assertGreaterThan(0, $result['rows'][0]['bytes']);
        $this->assertSame('timeout', $result['rows'][1]['status']);
        $this->assertLessThan(1500, $result['wall_ms']);
        $this->assertCount(2, $this->requests($directory));
        $this->assertSame(['GET', 'GET'], array_column($this->requests($directory), 'method'));
        $this->server('chunked');
        $result = KioskInsightThumbnail::probe(['kiosks/huge.jpg'], false);
        $this->assertSame('gagal', $result['rows'][0]['status']);
        $this->assertNull($result['rows'][0]['bytes']);
    }
}

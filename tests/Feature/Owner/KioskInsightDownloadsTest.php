<?php

namespace Tests\Feature\Owner;

use App\Livewire\Owner\KioskInsightDownloads;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use App\Support\KioskInsightExport;
use App\Support\KioskInsightThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class KioskInsightDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            File::deleteDirectory(storage_path('app/private/kiosk-insight-exports/'.$id));
        }
        parent::tearDown();
    }

    private function report(User $creator, ?int $ownerId = null, string $stage = 'ready', ?string $expires = null): string
    {
        $id = (string) Str::uuid();
        $this->ids[] = $id;
        $directory = storage_path('app/private/kiosk-insight-exports/'.$id);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/state.json', json_encode([
            'creator_id' => $creator->id, 'owner_id' => $ownerId ?? $creator->id,
            'format' => 'excel', 'created_at' => now()->toIso8601String(),
            'expires_at' => $expires ?? now()->addHours(24)->toIso8601String(),
            'stage' => $stage, 'message' => 'Siap', 'filename' => 'Laporan Kedai Uji.xlsx',
        ]));
        File::put($directory.'/report.xlsx', 'test-private-report');

        return $id;
    }

    public function test_owner_and_admin_can_open_page_but_operator_is_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true]);
        $this->actingAs($owner)->get(route('kiosk-insights.index'))->assertOk()->assertSee('Export Excel + Foto');
        $this->actingAs($admin)->get(route('kiosk-insights.index'))->assertRedirect(route('filament.admin.pages.laporan-kedai'));
        $this->actingAs($admin)->get(route('filament.admin.pages.laporan-kedai'))->assertOk()->assertSee('Semua owner');
        $this->actingAs($operator)->get(route('kiosk-insights.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('kiosk-insights.download', ['export' => (string) Str::uuid()]))->assertForbidden();
        Livewire::actingAs($operator)->test(KioskInsightDownloads::class)->assertForbidden();
    }

    public function test_reload_resumes_saved_export_for_creator_only(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $id = $this->report($owner);
        $this->actingAs($owner)->get('/kiosk-insights?export='.$id)->assertOk()->assertSee('Unduh Laporan');
        $this->actingAs($other)->get('/kiosk-insights?export='.$id)->assertNotFound();
        $this->actingAs($owner)->get('/kiosk-insights?export=not-a-uuid')->assertNotFound();
    }

    public function test_only_creator_can_download_even_super_admin_cannot_take_another_creators_report(): void
    {
        $a = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $b = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $id = $this->report($a);
        $url = route('kiosk-insights.download', ['export' => $id]);
        $this->actingAs($a)->get($url)->assertOk()->assertDownload('Laporan Kedai Uji.xlsx');
        $this->actingAs($b)->get($url.'?owner_id='.$a->id)->assertNotFound();
        $this->actingAs($admin)->get($url)->assertNotFound();
    }

    public function test_owner_cannot_select_another_owner_or_all_owners_through_livewire(): void
    {
        $a = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $b = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        Livewire::actingAs($a)->test(KioskInsightDownloads::class)
            ->set('ownerId', (string) $b->id)->call('generate', 'excel')->assertForbidden();
        Livewire::actingAs($a)->test(KioskInsightDownloads::class)
            ->set('ownerId', '')->call('generate', 'excel')->assertForbidden();
    }

    public function test_expired_file_returns_gone_and_is_removed(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $id = $this->report($owner, expires: now()->subSecond()->toIso8601String());
        $this->actingAs($owner)->get(route('kiosk-insights.download', ['export' => $id]))->assertGone();
        $this->assertDirectoryDoesNotExist(storage_path('app/private/kiosk-insight-exports/'.$id));
    }

    public function test_role_downgrade_blocks_existing_livewire_component_and_file(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $id = $this->report($owner);
        $component = Livewire::actingAs($owner)->test(KioskInsightDownloads::class);
        $owner->update(['role' => 'operator']);
        $component->call('advance')->assertForbidden();
        $this->actingAs($owner->fresh())->get(route('kiosk-insights.download', ['export' => $id]))->assertForbidden();
    }

    public function test_unready_report_cannot_be_downloaded_and_invalid_identifier_is_not_a_path(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $id = $this->report($owner, stage: 'photos');
        $this->actingAs($owner)->get(route('kiosk-insights.download', ['export' => $id]))->assertStatus(409);
        $this->get('/kiosk-insights/not-a-uuid/download')->assertNotFound();
    }

    public function test_thumbnail_handles_corruption_absence_and_uses_small_cached_jpeg(): void
    {
        Storage::fake('insight-test-media');
        config(['app.media_disk' => 'insight-test-media']);
        $this->assertSame('Tidak ada foto', KioskInsightThumbnail::prepare(['photo_path' => null])['photo_status']);
        Storage::disk('insight-test-media')->put('kiosks/corrupt.jpg', 'not an image');
        $this->assertSame('Foto gagal dimuat', KioskInsightThumbnail::prepare(['photo_path' => 'kiosks/corrupt.jpg'])['photo_status']);
        $this->assertSame('Foto gagal dimuat', KioskInsightThumbnail::prepare(['photo_path' => 'https://example.test/file'])['photo_status']);
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 160, 90, 20));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('insight-test-media')->put('kiosks/dummy.jpg', $bytes);
        $first = KioskInsightThumbnail::prepare(['photo_path' => 'kiosks/dummy.jpg']);
        $this->assertSame('ok', $first['photo_status']);
        $size = getimagesize($first['thumbnail_path']);
        $this->assertSame(160, max($size[0], $size[1]));
        $this->assertLessThan(10000, filesize($first['thumbnail_path']));
        $this->assertCount(1, Storage::disk('insight-test-media')->files('thumbs/kiosk-insights'));
        $this->assertSame($first['thumbnail_path'], KioskInsightThumbnail::prepare(['photo_path' => 'kiosks/dummy.jpg'])['thumbnail_path']);
        File::delete($first['thumbnail_path']);
    }

    /** Server S3 palsu yang MENGGANTUNG/MENETES; mengembalikan [process, port]. */
    private function startSlowS3(string $mode): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $process = new Process([PHP_BINARY, base_path('tests/Support/slow-s3-server.php'), (string) $port, $mode]);
        $process->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($process->getOutput(), 'ready') && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertStringContainsString('ready', $process->getOutput());

        return [$process, $port];
    }

    private function slowS3Config(int $port, array $photo): void
    {
        config([
            'filesystems.disks.slow-s3' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b',
                'endpoint' => "http://127.0.0.1:$port", 'use_path_style_endpoint' => true, 'throw' => false, 'report' => false],
            'app.media_disk' => 'slow-s3',
            'kiosk_insights.photo' => $photo,
        ]);
        File::deleteDirectory(storage_path('app/private/kiosk-insight-thumbs'));
    }

    #[DataProvider('slowModes')]
    public function test_slow_or_hanging_remote_photos_never_exceed_tick_budget_and_export_still_finishes(string $mode): void
    {
        [$server, $port] = $this->startSlowS3($mode);
        try {
            $this->slowS3Config($port, ['budget' => 4, 'concurrency' => 10, 'connect_timeout' => 1, 'thumb_timeout' => 1,
                'source_timeout' => 1, 'put_timeout' => 1, 'max_source_bytes' => 5 * 1024 * 1024]);
            $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
            $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
            for ($i = 0; $i < 25; $i++) {
                Kiosk::factory()->create(['cluster_id' => $cluster->id, 'photo_path' => 'kiosks/slow-'.$i.'.jpg']);
            }
            $this->actingAs($owner);
            $id = KioskInsightExport::start('photos', $owner->id);
            $this->ids[] = $id;
            $budget = KioskInsightThumbnail::budgetSeconds(); // terburuk satu gelombang = 1+1+1+1 = 4 dtk
            $this->assertSame(4.0, $budget);
            $ticks = 0;
            $slowest = 0.0;
            do {
                $t = microtime(true);
                $progress = KioskInsightExport::tick($id);
                $slowest = max($slowest, microtime(true) - $t);
                $ticks++;
            } while (! $progress['ready'] && ! $progress['failed'] && $ticks < 12);
            // Tidak pernah melebihi anggaran (+ margin CPU/IO), jauh dari batas 30 dtk.
            $this->assertLessThanOrEqual($budget + 1.5, $slowest, "tick terlama {$slowest}s");
            $this->assertTrue($progress['ready'], json_encode($progress));
            $this->assertGreaterThan(1, $ticks, 'foto lambat dibagi ke beberapa tick');
            $book = IOFactory::load(KioskInsightExport::download($id)->getFile()->getPathname());
            $values = json_encode($book->getSheetByName('Daftar Kedai')->toArray());
            $this->assertSame(25, substr_count($values, 'Foto gagal dimuat'));
            $book->disconnectWorksheets();
        } finally {
            $server->stop(0);
        }
    }

    public static function slowModes(): array
    {
        return ['server menggantung total' => ['hang'], 'body menetes' => ['drip']];
    }

    public function test_remote_photos_are_fetched_in_parallel_not_one_after_another(): void
    {
        [$server, $port] = $this->startSlowS3('hang');
        try {
            $this->slowS3Config($port, ['budget' => 10, 'concurrency' => 10, 'connect_timeout' => 1, 'thumb_timeout' => 1,
                'source_timeout' => 1, 'put_timeout' => 1, 'max_source_bytes' => 5 * 1024 * 1024]);
            $rows = [];
            for ($i = 0; $i < 10; $i++) {
                $rows[$i] = ['photo_path' => 'kiosks/par-'.$i.'.jpg'];
            }
            $t = microtime(true);
            $out = KioskInsightThumbnail::prepareBatch($rows);
            $elapsed = microtime(true) - $t;
            // Berurutan = 10 × (1+1) = 20 dtk; paralel ≈ 2 dtk.
            $this->assertLessThan(4.5, $elapsed);
            foreach ($out as $row) {
                $this->assertSame('Foto gagal dimuat', $row['photo_status']);
            }
        } finally {
            $server->stop(0);
        }
    }

    public function test_failed_photo_is_retried_next_time_while_good_thumbnail_is_cached(): void
    {
        Storage::fake('insight-test-media');
        config(['app.media_disk' => 'insight-test-media']);
        File::deleteDirectory(storage_path('app/private/kiosk-insight-thumbs'));
        $disk = Storage::disk('insight-test-media');
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($image);
        $disk->put('kiosks/ok.jpg', ob_get_clean());
        $rows = [['photo_path' => 'kiosks/ok.jpg'], ['photo_path' => 'kiosks/late.jpg']];
        $first = KioskInsightThumbnail::prepareBatch($rows);
        $this->assertSame('ok', $first[0]['photo_status']);
        $this->assertSame('Foto gagal dimuat', $first[1]['photo_status']);
        $disk->put('kiosks/late.jpg', $disk->get('kiosks/ok.jpg'));
        $second = KioskInsightThumbnail::prepareBatch($rows);
        $this->assertSame('ok', $second[1]['photo_status']);
        $this->assertSame($first[0]['thumbnail_path'], $second[0]['thumbnail_path']);
        File::deleteDirectory(storage_path('app/private/kiosk-insight-thumbs'));
    }

    public function test_real_excel_generation_is_read_only_and_never_includes_other_owner(): void
    {
        $a = User::factory()->create(['role' => 'owner', 'is_active' => true, 'business_name' => 'Usaha A']);
        $b = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ca = Cluster::factory()->create(['owner_id' => $a->id]);
        $cb = Cluster::factory()->create(['owner_id' => $b->id]);
        Kiosk::factory()->create(['cluster_id' => $ca->id, 'name' => 'Kedai A aman']);
        Kiosk::factory()->create(['cluster_id' => $cb->id, 'name' => 'Rahasia Kedai B']);
        $this->actingAs($a);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $id = KioskInsightExport::start('excel', $a->id);
        $this->ids[] = $id;
        $progress = KioskInsightExport::tick($id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertTrue($progress['ready']);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\\b/i', $query['query']);
        }
        $file = KioskInsightExport::download($id)->getFile()->getPathname();
        $book = IOFactory::load($file);
        $values = json_encode($book->getSheetByName('Daftar Kedai')->toArray());
        $this->assertStringContainsString('Kedai A aman', $values);
        $this->assertStringNotContainsString('Rahasia Kedai B', $values);
        $book->disconnectWorksheets();
    }

    public function test_corrupt_photo_does_not_fail_entire_export_and_pdf_merges(): void
    {
        Storage::fake('insight-test-media');
        config(['app.media_disk' => 'insight-test-media']);
        Storage::disk('insight-test-media')->put('kiosks/broken.jpg', 'broken');
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        Kiosk::factory()->create(['cluster_id' => $cluster->id, 'photo_path' => 'kiosks/broken.jpg']);
        $this->actingAs($owner);
        foreach (['photos', 'pdf'] as $format) {
            $id = KioskInsightExport::start($format, $owner->id);
            $this->ids[] = $id;
            for ($i = 0; $i < 10; $i++) {
                $progress = KioskInsightExport::tick($id);
                if ($progress['ready'] || $progress['failed']) {
                    break;
                }
            }
            $this->assertTrue($progress['ready'], json_encode($progress));
            $file = KioskInsightExport::download($id)->getFile()->getPathname();
            if ($format === 'photos') {
                $book = IOFactory::load($file);
                $this->assertStringContainsString('Foto gagal dimuat', json_encode($book->getSheetByName('Daftar Kedai')->toArray()));
                $book->disconnectWorksheets();
            } else {
                $this->assertStringStartsWith('%PDF', File::get($file));
                $parser = new Fpdi;
                $this->assertGreaterThanOrEqual(2, $parser->setSourceFile($file));
            }
        }
    }

    public function test_superadmin_can_generate_all_owners_but_only_the_creator_downloads(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        foreach (['A', 'B'] as $name) {
            $owner = User::factory()->create(['role' => 'owner', 'business_name' => 'Usaha '.$name]);
            $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
            Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => 'Kedai '.$name]);
        }
        $this->actingAs($admin);
        $id = KioskInsightExport::start('excel', null);
        $this->ids[] = $id;
        $this->assertTrue(KioskInsightExport::tick($id)['ready']);
        $book = IOFactory::load(KioskInsightExport::download($id)->getFile()->getPathname());
        $values = json_encode($book->getSheetByName('Daftar Kedai')->toArray());
        $this->assertStringContainsString('Kedai A', $values);
        $this->assertStringContainsString('Kedai B', $values);
        $book->disconnectWorksheets();
    }

    public function test_prune_removes_only_expired_report_directories(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $old = $this->report($owner);
        $fresh = $this->report($owner);
        touch(storage_path('app/private/kiosk-insight-exports/'.$old), now()->subHours(25)->timestamp);
        KioskInsightExport::prune();
        $this->assertDirectoryDoesNotExist(storage_path('app/private/kiosk-insight-exports/'.$old));
        $this->assertDirectoryExists(storage_path('app/private/kiosk-insight-exports/'.$fresh));
    }
}

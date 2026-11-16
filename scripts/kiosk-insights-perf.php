<?php

/** Local-only reproducible export benchmark. No production or real media access. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\KioskInsightExport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

if (! app()->environment('local', 'testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_perf_db') || (int) config('database.connections.mysql.port') !== 3399) {
    throw new RuntimeException('Only local MySQL3399 *_perf_db allowed.');
}
config([
    'filesystems.disks.insight-perf' => ['driver' => 'local', 'root' => storage_path('perf/kiosk-insights/media'), 'throw' => false],
    'app.media_disk' => 'insight-perf',
]);
$media = storage_path('perf/kiosk-insights/media/kiosks');
File::ensureDirectoryExists($media);
$owner = User::where('email', 'perf.owner.a@perf.test')->firstOrFail();
$owner->update(['business_name' => 'Perf Usaha A']);
$kiosks = DB::table('kiosks')->whereIn('cluster_id', DB::table('clusters')->where('owner_id', $owner->id)->select('id'))->orderBy('id')->get(['id']);
if ($kiosks->count() < 450) {
    throw new RuntimeException('Requires at least450 kiosks for ownerA; run updated PerfDatasetSeeder.');
}
foreach ($kiosks as $index => $kiosk) {
    $path = 'kiosks/perf-'.$kiosk->id.'.jpg';
    if ($index === 0) {
        File::put(dirname($media).'/'.$path, 'intentionally corrupt dummy image');
    } else {
        $image = imagecreatetruecolor(640, 480);
        $bg = imagecolorallocate($image, 210 + $index % 40, 160 + $index % 60, 90 + $index % 80);
        imagefill($image, 0, 0, $bg);
        $ink = imagecolorallocate($image, 63, 36, 20);
        imagefilledrectangle($image, 80, 110, 560, 420, $ink);
        imagefilledrectangle($image, 60, 80, 580, 140, imagecolorallocate($image, 245, 236, 221));
        imagestring($image, 5, 130, 95, 'DUMMY KEDAI '.$index, $ink);
        imagejpeg($image, dirname($media).'/'.$path, 85);
        imagedestroy($image);
    }
    DB::table('kiosks')->where('id', $kiosk->id)->update(['photo_path' => $path]);
}
Auth::login($owner);
$results = ['mysql_version' => DB::selectOne('select version() as v')->v, 'kiosks' => $kiosks->count(), 'formats' => []];
$out = storage_path('perf/kiosk-insights');
foreach (['excel', 'photos', 'pdf'] as $format) {
    $start = microtime(true);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $id = KioskInsightExport::start($format, $owner->id);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $max = microtime(true) - $start;
    $ticks = 0;
    do {
        $tick = microtime(true);
        $progress = KioskInsightExport::tick($id);
        $max = max($max, microtime(true) - $tick);
        $ticks++;
        if ($progress['failed']) {
            throw new RuntimeException($format.' failed: '.$progress['message']);
        }
        if ($ticks > 1500) {
            throw new RuntimeException('Too many ticks.');
        }
    } while (! $progress['ready']);
    $response = KioskInsightExport::download($id);
    $destination = $out.'/'.$format.'-'.$response->getFile()->getFilename();
    File::copy($response->getFile()->getPathname(), $destination);
    $results['formats'][$format] = ['path' => $destination, 'bytes' => filesize($destination), 'seconds' => round(microtime(true) - $start, 3), 'max_request_seconds' => round($max, 3), 'ticks' => $ticks, 'start_queries' => $queries];
    echo json_encode($results['formats'][$format], JSON_UNESCAPED_SLASHES).PHP_EOL;
}
File::put($out.'/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

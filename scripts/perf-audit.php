<?php

/**
 * BRIEF 11 Nov 2026 "PERFORMA MELAMBAT" item 2 — harness audit performa lokal.
 *
 * Mem-boot app IN-PROCESS terhadap database `*_perf_db` (lihat PerfDatasetSeeder), login
 * sebagai tiap role, lalu mengukur tiap halaman: jumlah query, total ms query, wall ms
 * (median dari N kali setelah 1x pemanasan), query duplikat persis, dan query terlambat.
 *
 *   DB_DATABASE=cemilan_qontas_perf_db php scripts/perf-audit.php <label> [runs=5]
 *   → storage/perf/<label>.json  + tabel markdown ke stdout
 *
 * PENGAMAN: menolak jalan kecuali nama database berakhiran _perf_db.
 *
 * Catatan metodologi: wall ms = waktu di DALAM proses PHP (kernel->handle), tanpa
 * web server, jaringan, atau boot proses. Angka query lebih stabil & lebih bermakna
 * untuk perbandingan sebelum/sesudah; wall ms hanya pembanding kasar (mesin dev ≠ Railway).
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require __DIR__.'/../vendor/autoload.php';

$label = $argv[1] ?? 'run';
$runs = (int) ($argv[2] ?? 5);
$dump = $argv[3] ?? null; // substring nama skenario → cetak SEMUA query-nya (1x)

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = DB::connection()->getDatabaseName();
if (! str_ends_with((string) $db, '_perf_db')) {
    fwrite(STDERR, "Menolak jalan di database '{$db}' (harus berakhiran _perf_db)\n");
    exit(1);
}

// Matikan pencatat request lambat selama audit supaya tidak ikut mencampuri hitungan.
config(['performance.enabled' => false]);

$kernel = $app->make(Kernel::class);

// ---------------------------------------------------------------- util ukur
$collector = ['queries' => []];
DB::listen(function ($q) use (&$collector) {
    $collector['queries'][] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
});

function normalize(string $sql): string
{
    $sql = preg_replace('/\((\?,\s*)+\?\)/', '(...)', $sql);

    return preg_replace('/\s+/', ' ', $sql);
}

/** Jalankan $fn sekali, kembalikan metrik. */
function measure(callable $fn, array &$collector): array
{
    $collector['queries'] = [];
    $t0 = hrtime(true);
    $info = $fn();
    $wall = (hrtime(true) - $t0) / 1e6;

    $qs = $collector['queries'];
    $seen = [];
    $dupes = 0;
    $sigCount = [];
    foreach ($qs as $q) {
        $key = $q['sql'].'|'.json_encode($q['bindings']);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $sigCount[normalize($q['sql'])] = ($sigCount[normalize($q['sql'])] ?? 0) + 1;
    }
    foreach ($seen as $n) {
        if ($n > 1) {
            $dupes += $n - 1;
        }
    }
    usort($qs, fn ($a, $b) => $b['ms'] <=> $a['ms']);
    arsort($sigCount);

    return [
        'queries' => count($collector['queries']),
        'db_ms' => round(array_sum(array_column($collector['queries'], 'ms')), 1),
        'wall_ms' => round($wall, 1),
        'dupes' => $dupes,
        'slowest' => $qs ? ['ms' => round($qs[0]['ms'], 2), 'sql' => mb_substr(normalize($qs[0]['sql']), 0, 160)] : null,
        'top_repeat' => array_slice(array_map(fn ($s, $n) => ['n' => $n, 'sql' => mb_substr($s, 0, 140)], array_keys($sigCount), $sigCount), 0, 3),
        'status' => $info['status'] ?? null,
        'timing' => $info['timing'] ?? null,
    ];
}

function median(array $xs): float
{
    sort($xs);
    $n = count($xs);

    return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
}

function userByEmail(string $email): App\Models\User
{
    return App\Models\User::where('email', $email)->firstOrFail();
}

function http(Illuminate\Contracts\Http\Kernel $kernel, ?App\Models\User $user, string $uri): array
{
    app('auth')->forgetGuards();
    if ($user) {
        app('auth')->guard('web')->setUser($user);
    }
    $request = Request::create($uri, 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $loc = $status >= 300 && $status < 400 ? $response->headers->get('Location') : null;
    $timing = $response->headers->get('Server-Timing');
    $kernel->terminate($request, $response);
    app('auth')->forgetGuards();

    return ['status' => $status.($loc ? " → {$loc}" : ''), 'timing' => $timing];
}

/** Buka lagi trip lupa-diakhiri supaya skenario "hit pertama menutup trip" bisa diulang. */
function reopenStaleTrips(): array
{
    $ids = DB::table('trips')->where('ended_reason', 'auto_closed')->pluck('id')->all();
    if ($ids) {
        DB::table('commissions')->whereIn('trip_id', $ids)->delete();
        DB::table('trips')->whereIn('id', $ids)->update(['ended_at' => null, 'ended_reason' => null]);
    }

    return $ids;
}

// ---------------------------------------------------------------- skenario
$A1 = userByEmail('perf.op.a1@perf.test');   // trip BERJALAN hari ini
$A2 = userByEmail('perf.op.a2@perf.test');   // trip KEMARIN belum idle 6 jam
$A3 = userByEmail('perf.op.a3@perf.test');   // trip LUPA diakhiri (3 hari) → auto-close
$ownerA = userByEmail('perf.owner.a@perf.test');
$ownerB = userByEmail('perf.owner.b@perf.test');
$admin = userByEmail('perf.admin@perf.test');
$tripA1 = DB::table('trips')->where('operator_id', $A1->id)->whereNull('ended_at')->value('id');
$endedTripA = DB::table('trips')->where('owner_id', $ownerA->id)->whereNotNull('ended_at')->where('trip_date', '>', '2001-01-01')->orderByDesc('id')->value('id');

$monthStart = now()->startOfMonth()->toDateString();
$today = now()->toDateString();

$cases = [
    // [nama, user|null, callable]
    ['Login (GET /login)', null, fn () => http($kernel, null, '/login')],
    ['Operator: Beranda (A1, trip berjalan)', $A1, fn () => http($kernel, $A1, '/operator/dashboard')],
    ['Operator: Beranda (A2, trip kemarin belum idle)', $A2, fn () => http($kernel, $A2, '/operator/dashboard')],
    ['Operator: Mulai Trip (A1, ada trip berjalan → kartu)', $A1, fn () => http($kernel, $A1, '/operator/trip/start')],
    ['Operator: Trip Aktif (A1, 300 kios, 6 area)', $A1, fn () => http($kernel, $A1, '/operator/trip/'.$tripA1)],
    ['Operator: Kios Baru (A1)', $A1, fn () => http($kernel, $A1, '/operator/kiosks/create')],
    ['Owner: Dashboard (A)', $ownerA, fn () => http($kernel, $ownerA, '/owner/dashboard')],
    ['Owner: Riwayat Trip (A)', $ownerA, fn () => http($kernel, $ownerA, '/owner/trips')],
    ['Owner: Detail Trip (A)', $ownerA, fn () => http($kernel, $ownerA, '/owner/trips/'.$endedTripA)],
    ['Owner: Laporan Periode (A, bulan ini)', $ownerA, fn () => http($kernel, $ownerA, "/owner/reports/periode?from={$monthStart}&to={$today}")],
    ['Owner: Laporan Bulanan (A)', $ownerA, fn () => http($kernel, $ownerA, '/owner/reports/monthly')],
    ['Owner: Manajemen Kios (panel, 300 kios)', $ownerA, fn () => http($kernel, $ownerA, '/owner-panel/kiosks')],
    ['Owner: Dashboard panel Filament', $ownerA, fn () => http($kernel, $ownerA, '/owner-panel')],
    ['Admin: Dashboard (shell)', $admin, fn () => http($kernel, $admin, '/admin')],
];

// Komponen Livewire (poll/widget) — dihitung lewat Livewire::test.
$livewire = function (string $class, App\Models\User $as, array $params = [], bool $refreshOnly = false) {
    return function () use ($class, $as, $params, $refreshOnly) {
        app('auth')->forgetGuards();
        app('auth')->guard('web')->setUser($as);
        $t = Livewire::test($class, $params);
        if ($refreshOnly) {
            $GLOBALS['collector']['queries'] = []; // hanya biaya satu poll, bukan mount
            $t->call('$refresh');
        }

        return ['status' => 'lw'];
    };
};
$GLOBALS['collector'] = &$collector;

$cases[] = ['Owner: Live Trip — POLL 30s (1 tick)', $ownerA, $livewire(App\Livewire\Owner\LiveTripProgress::class, $ownerA, [], true)];

$widgetClasses = [
    App\Filament\Widgets\SuperAdminStatsOverview::class,
    App\Filament\Widgets\OwnerPerformanceTable::class,
    App\Filament\Widgets\OperatorKomisiTable::class,
    App\Filament\Widgets\OwnerOmsetChart::class,
    App\Filament\Widgets\AppFeeWidget::class,
    App\Filament\Widgets\AppFeeDailyWidget::class,
];
foreach ($widgetClasses as $w) {
    $cases[] = ['Admin widget: '.class_basename($w), $admin, $livewire($w, $admin)];
}

// ---------------------------------------------------------------- jalankan
$results = [];

// 1. Hit PERTAMA yang menutup trip lupa-diakhiri (middleware AutoCloseStaleTrips bekerja).
$reopened = reopenStaleTrips();
$results['Operator A3: Beranda — hit pertama (menutup trip lupa)'] = [measure(fn () => http($kernel, $A3, '/operator/dashboard'), $collector)];
$results['Operator A3: Beranda — hit berikutnya (stale sudah tertutup)'] = [measure(fn () => http($kernel, $A3, '/operator/dashboard'), $collector)];
reopenStaleTrips();
$results['Owner A: Dashboard — hit pertama (menutup trip lupa milik owner)'] = [measure(fn () => http($kernel, $ownerA, '/owner/dashboard'), $collector)];

// 2. Skenario biasa (keadaan tunak: stale sudah tertutup, A1 berjalan, A2 kemarin belum idle).
foreach ($cases as [$name, $user, $fn]) {
    $fn(); // pemanasan (compile view, route, dst) — tidak dihitung
    if ($dump !== null && str_contains($name, $dump)) {
        $collector['queries'] = [];
        $fn();
        echo "\n##### DUMP: {$name}\n";
        foreach ($collector['queries'] as $i => $q) {
            printf("%3d [%6.2f ms] %s\n", $i + 1, $q['ms'], mb_substr(normalize($q['sql']), 0, 230));
        }
        echo "#####\n";
    }
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $samples[] = measure($fn, $collector);
    }
    $results[$name] = $samples;
}

// ---------------------------------------------------------------- ringkas
$summary = [];
foreach ($results as $name => $samples) {
    $summary[$name] = [
        'queries' => (int) median(array_column($samples, 'queries')),
        'db_ms' => round(median(array_column($samples, 'db_ms')), 1),
        'wall_ms' => round(median(array_column($samples, 'wall_ms')), 1),
        'dupes' => (int) median(array_column($samples, 'dupes')),
        'status' => $samples[0]['status'],
        'slowest' => $samples[0]['slowest'],
        'top_repeat' => $samples[0]['top_repeat'],
    ];
}

@mkdir(__DIR__.'/../storage/perf', 0777, true);
file_put_contents(__DIR__."/../storage/perf/{$label}.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "| Halaman | Query | DB ms | Wall ms | Duplikat | Status |\n|---|---:|---:|---:|---:|---|\n";
foreach ($summary as $name => $s) {
    printf("| %s | %d | %.1f | %.1f | %d | %s |\n", $name, $s['queries'], $s['db_ms'], $s['wall_ms'], $s['dupes'], $s['status']);
}
echo "\nDB: {$db}  runs={$runs}\n";

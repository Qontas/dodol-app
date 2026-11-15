<?php

// Invoked only by AppFeeInvoiceConcurrencyTest, never by an HTTP endpoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\User;
use App\Support\AppFeeInvoiceService;
use App\Support\InvoiceException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || getenv('RUN_INVOICE_CONCURRENCY') !== '1') {
    fwrite(STDERR, "Worker requires explicit testing environment.\n");
    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || ! str_ends_with((string) config('database.connections.mysql.database'), '_test_db')) {
    fwrite(STDERR, "Worker refuses databases other than MySQL *_test_db.\n");
    exit(2);
}

[$script, $ownerId, $adminId, $period, $barrier, $barrierDir] = $argv;
Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00'));
$owner = User::findOrFail((int) $ownerId);
$admin = User::findOrFail((int) $adminId);
$waited = false;
$candidates = [];

// Stop immediately before the contested statement, not merely before issue().
// The number barrier ensures both transactions already chose the same number.
DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($barrier, $barrierDir, &$waited, &$candidates): void {
    $sql = strtolower($sql);
    $inserting = str_starts_with($sql, 'insert into `app_fee_invoices`');
    if ($inserting) {
        foreach ($bindings as $binding) {
            if (is_string($binding) && str_starts_with($binding, 'CQ-INV-')) {
                $candidates[] = $binding;
            }
        }
    }
    $contested = $barrier === 'number'
        ? $inserting
        : str_contains($sql, '`users`') && str_contains($sql, 'for update');
    if ($waited || ! $contested) {
        return;
    }
    $waited = true;
    file_put_contents($barrierDir.'/ready-'.getmypid(), 'READY');
    // Windows process pipes can buffer stdout/stdin until process termination.
    // The parent releases BOTH workers only after both READY marker files exist.
    $deadline = microtime(true) + 30;
    while (! is_file($barrierDir.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Missing parent barrier release.');
        }
        usleep(10000);
        clearstatcache(true, $barrierDir.'/go');
    }
});

try {
    $invoice = AppFeeInvoiceService::issue($owner, $period, $admin);
    $result = ['result' => 'issued', 'id' => $invoice->id, 'number' => $invoice->number];
} catch (InvoiceException $e) {
    $result = ['result' => 'rejected', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, json_encode($result + ['candidates' => $candidates], JSON_THROW_ON_ERROR)."\n");

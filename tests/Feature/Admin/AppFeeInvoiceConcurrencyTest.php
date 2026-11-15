<?php

namespace Tests\Feature\Admin;

use App\Models\AppFeeInvoice;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in destructive test on a DISPOSABLE MySQL *_test_db only.
 * No RefreshDatabase: process workers must see committed fixtures.
 * Run separately, never alongside another suite using the same database:
 * RUN_INVOICE_CONCURRENCY=1 php artisan test --filter=AppFeeInvoiceConcurrencyTest
 */
class AppFeeInvoiceConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_INVOICE_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt-in race harness; recreates the explicitly disposable test database.');
        }
        if (! app()->environment('testing') || config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test_db')) {
            throw new \RuntimeException('Concurrency harness refuses databases other than MySQL *_test_db in testing.');
        }

        // Guard BEFORE migrate:fresh. This test owns this disposable DB exclusively.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->travelTo(now()->setDate(2026, 12, 3)->setTime(10, 0));
    }

    private function ownerWithFee(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Race-'.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => '2026-11-03', 'trip_number_of_day' => 1,
            'started_at' => '2026-11-03 08:00:00', 'ended_at' => '2026-11-03 12:00:00',
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'product_variant_id' => $variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 10,
        ]);

        return $owner;
    }

    /** @return array<int, array<string, mixed>> */
    private function race(array $owners, User $admin, string $barrier): array
    {
        $db = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing', 'RUN_INVOICE_CONCURRENCY' => '1',
            'DB_CONNECTION' => 'mysql', 'DB_URL' => false,
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'], 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'SLOW_REQUEST_ENABLED' => 'false',
        ];
        $processes = [];
        $barrierDir = sys_get_temp_dir().'/cq-invoice-race-'.bin2hex(random_bytes(8));
        mkdir($barrierDir, 0700);

        try {
            foreach ($owners as $owner) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/invoice-issue-worker.php'),
                    (string) $owner->id, (string) $admin->id, '2026-11', $barrier, $barrierDir,
                ], base_path(), $env);
                $process->setTimeout(45);
                $process->start();
                $processes[] = $process;
            }

            // Both workers must reach the real contested SQL before EITHER proceeds.
            $deadline = microtime(true) + 30;
            while (count(glob($barrierDir.'/ready-*')) < 2) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                }
                $this->assertLessThan($deadline, microtime(true), 'Both workers must reach the SQL barrier.');
                usleep(10000);
                clearstatcache();
            }
            file_put_contents($barrierDir.'/go', 'GO');

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (glob($barrierDir.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrierDir);
            DB::purge();
        }
    }

    public function test_simultaneous_issue_same_owner_period_creates_only_one_active_invoice(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $owner = $this->ownerWithFee();
        $results = $this->race([$owner, $owner], $admin, 'owner');

        $this->assertEqualsCanonicalizing(['issued', 'rejected'], array_column($results, 'result'));
        $this->assertSame(1, AppFeeInvoice::active()->where('owner_id', $owner->id)->where('period', '2026-11')->count());
        $this->assertSame(['CQ-INV-202611-001'], AppFeeInvoice::pluck('number')->all());
    }

    public function test_different_owners_collide_on_number_then_retry_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $results = $this->race([$this->ownerWithFee(), $this->ownerWithFee()], $admin, 'number');

        $this->assertSame(['issued', 'issued'], array_column($results, 'result'));
        // Proves the retry path was exercised: initial candidates really collided.
        $this->assertSame('CQ-INV-202611-001', $results[0]['candidates'][0]);
        $this->assertSame('CQ-INV-202611-001', $results[1]['candidates'][0]);
        $this->assertEqualsCanonicalizing(['CQ-INV-202611-001', 'CQ-INV-202611-002'], array_column($results, 'number'));
        $this->assertSame(2, AppFeeInvoice::active()->count());
    }
}

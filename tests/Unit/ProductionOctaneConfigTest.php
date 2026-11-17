<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionOctaneConfigTest extends TestCase
{
    public function test_production_start_bounds_workers_and_requests_without_changing_other_startup_steps(): void
    {
        $docker = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
        preg_match('/^CMD (.+)$/m', $docker, $match);
        $command = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['sh', '-c'], array_slice($command, 0, 2));
        $this->assertSame(
            'php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache && php artisan migrate --force && (php artisan storage:link || true) && php artisan octane:frankenphp --host=0.0.0.0 --port=${PORT:-8000} --workers=${OCTANE_WORKERS:-4} --max-requests=${OCTANE_MAX_REQUESTS:-500} --log-level=info --caddyfile=/app/Caddyfile.production',
            $command[2],
        );
    }

    public function test_production_caddyfile_only_adds_bounded_thread_settings_to_octane_stub(): void
    {
        $root = dirname(__DIR__, 2);
        $production = file_get_contents($root.'/Caddyfile.production');
        $vendor = file_get_contents($root.'/vendor/laravel/octane/src/Commands/stubs/Caddyfile');
        $this->assertStringContainsString('num_threads {$FRANKENPHP_THREADS:8}', $production);
        $this->assertStringContainsString('max_threads {$FRANKENPHP_THREADS:8}', $production);
        $withoutLimits = preg_replace('/^\h*(?:num_threads|max_threads) .*\R/m', '', $production);
        $normalize = static fn (string $config): string => preg_replace('/\s+/', '', preg_replace('/^\h*#.*$/m', '', $config));

        // Detect accidental port/logging/routing changes and upstream stub drift on Octane upgrades.
        $this->assertSame($normalize($vendor), $normalize($withoutLimits));
    }
}

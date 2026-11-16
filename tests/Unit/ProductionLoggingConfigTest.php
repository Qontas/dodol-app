<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionLoggingConfigTest extends TestCase
{
    private function probe(string $env, ?string $stack = null): array
    {
        // No Kernel bootstrap / dotenv: mirrors Docker runtime, where .env is intentionally absent.
        $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = new Illuminate\Foundation\Application(getcwd());
        $config = require 'config/logging.php';
        echo json_encode($config['channels']['stack']['channels']);
        $logger = new Monolog\Logger('probe');
        $logger->pushHandler(new Monolog\Handler\StreamHandler('php://stderr', Monolog\Level::Warning));
        $logger->warning('SLOW_REQUEST route=probe');
        $logger->error('PROBE_ERROR');
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script], dirname(__DIR__, 2), [
            'APP_ENV' => $env, 'LOG_STACK' => $stack ?? false,
        ]);
        $process->mustRun();

        return [json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), $process->getErrorOutput()];
    }

    public function test_production_default_stack_includes_stderr_and_warning_is_visible(): void
    {
        [$channels, $stderr] = $this->probe('production');
        $this->assertSame(['single', 'stderr'], $channels);
        $this->assertStringContainsString('SLOW_REQUEST', $stderr);
        $this->assertStringContainsString('PROBE_ERROR', $stderr);
    }

    public function test_docker_preserves_raw_frankenphp_stderr_at_info_envelope_level(): void
    {
        $docker = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
        $this->assertStringContainsString('--log-level=info', $docker);
        // Vendor source audit: explicit log level chooses raw output before Octane's formatter.
        $command = file_get_contents(dirname(__DIR__, 2).'/vendor/laravel/octane/src/Commands/StartFrankenPhpCommand.php');
        $this->assertStringContainsString("if (\$this->option('log-level') !== null)", $command);
        $this->assertStringContainsString('$this->raw($output)', $command);
    }

    public function test_local_and_testing_defaults_remain_file_only(): void
    {
        $this->assertSame(['single'], $this->probe('local')[0]);
        $this->assertSame(['single'], $this->probe('testing')[0]);
    }

    public function test_explicit_stack_override_is_respected(): void
    {
        $this->assertSame(['single'], $this->probe('production', 'single')[0]);
    }
}

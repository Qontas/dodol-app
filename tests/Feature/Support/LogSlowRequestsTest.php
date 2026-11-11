<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * BRIEF 11 Nov 2026 "PERFORMA MELAMBAT" item 1 — middleware LogSlowRequests.
 */
class LogSlowRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml mematikan middleware ini supaya suite lain tidak berisik;
        // test ini menyalakannya sendiri.
        config([
            'performance.enabled' => true,
            'performance.server_timing' => true,
            'performance.slow_ms' => 800,
            'performance.slow_queries' => 40,
            'performance.log_channel' => 'slowtest',
        ]);

        Route::middleware('web')->get('/__perf/html', function () {
            DB::select('select 1');

            return response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']);
        })->name('perf.html');

        Route::middleware('web')->get('/__perf/json', fn () => response()->json(['ok' => true]))->name('perf.json');

        Route::middleware('web')->get('/__perf/queries', function () {
            for ($i = 0; $i < 45; $i++) {
                DB::select('select 1');
            }

            return response('ok', 200, ['Content-Type' => 'text/html']);
        })->name('perf.queries');

        Route::middleware('web')->get('/__perf/slow/{secret}', function () {
            usleep(60_000);

            return response('ok', 200, ['Content-Type' => 'text/html']);
        });
    }

    /** Pasang channel palsu & kumpulkan semua pesan warning yang masuk. */
    private function captureWarnings(): \ArrayObject
    {
        $messages = new \ArrayObject();

        $channel = Mockery::mock();
        $channel->shouldReceive('warning')->andReturnUsing(function ($message) use ($messages) {
            $messages[] = $message;
        });

        Log::shouldReceive('channel')->with('slowtest')->andReturn($channel);

        return $messages;
    }

    public function test_request_cepat_dan_sedikit_query_tidak_dicatat(): void
    {
        $messages = $this->captureWarnings();

        $this->get('/__perf/html')->assertOk();

        $this->assertCount(0, $messages);
    }

    public function test_request_dengan_query_melebihi_ambang_dicatat(): void
    {
        $messages = $this->captureWarnings();

        $this->get('/__perf/queries')->assertOk();

        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('SLOW_REQUEST route=perf.queries method=GET status=200', $messages[0]);
        $this->assertStringContainsString('queries=45', $messages[0]);
        $this->assertStringContainsString('role=guest', $messages[0]);
    }

    public function test_request_lambat_berdasarkan_durasi_dicatat_dan_ambang_bisa_diatur(): void
    {
        config(['performance.slow_ms' => 10]);
        $messages = $this->captureWarnings();

        $this->get('/__perf/slow/rahasia-123')->assertOk();

        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression('/dur_ms=\d+/', $messages[0]);
    }

    public function test_log_tidak_memuat_url_mentah_query_atau_data_pribadi(): void
    {
        config(['performance.slow_ms' => 10]);
        $messages = $this->captureWarnings();

        $owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'rahasia.owner@example.test',
            'name' => 'Nama Rahasia',
        ]);

        $this->actingAs($owner)->get('/__perf/slow/rahasia-123?token=abc&kios=77')->assertOk();

        $this->assertCount(1, $messages);
        $line = $messages[0];

        // Route tak bernama → template URI, BUKAN URL asli dengan nilai parameter.
        $this->assertStringContainsString('route=uri:__perf/slow/{secret}', $line);
        $this->assertStringContainsString('role=owner', $line);

        foreach (['rahasia-123', 'token=abc', 'kios=77', 'rahasia.owner', 'example.test', 'Nama Rahasia', 'select 1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $line, "Log tidak boleh memuat: {$forbidden}");
        }
    }

    public function test_header_server_timing_ada_di_respons_html(): void
    {
        $response = $this->get('/__perf/html')->assertOk();

        $header = $response->headers->get('Server-Timing');
        $this->assertNotNull($header);
        $this->assertMatchesRegularExpression('/^app;dur=\d+\.\d, db;dur=\d+\.\d, q;desc="\d+ queries"$/', $header);
    }

    public function test_server_timing_tidak_dipasang_di_respons_non_html(): void
    {
        $this->get('/__perf/json')->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_server_timing_bisa_dimatikan_lewat_config(): void
    {
        config(['performance.server_timing' => false]);

        $this->get('/__perf/html')->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_saklar_enabled_false_mematikan_semuanya(): void
    {
        config(['performance.enabled' => false, 'performance.slow_queries' => 1]);
        $messages = $this->captureWarnings();

        $this->get('/__perf/queries')->assertOk()->assertHeaderMissing('Server-Timing');

        $this->assertCount(0, $messages);
    }

    public function test_penghitung_di_reset_tiap_request_dan_listener_tidak_menumpuk(): void
    {
        $messages = $this->captureWarnings();

        // Request pertama melewati ambang query; request kedua (ringan) di instance
        // middleware yang SAMA (singleton) tidak boleh mewarisi hitungan request pertama.
        $this->get('/__perf/queries')->assertOk();
        $this->assertCount(1, $messages);

        $response = $this->get('/__perf/html')->assertOk();
        $this->assertCount(1, $messages, 'request ringan tidak boleh ikut tercatat');

        // 1 query di route (+ query sesi/lainnya) — jauh dari 45+; dan bukan terhitung ganda.
        preg_match('/q;desc="(\d+) queries"/', $response->headers->get('Server-Timing'), $m);
        $this->assertLessThan(10, (int) $m[1]);
    }
}

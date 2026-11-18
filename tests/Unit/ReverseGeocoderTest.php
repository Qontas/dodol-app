<?php

namespace Tests\Unit;

use App\Support\ReverseGeocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReverseGeocoderTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->lockPath = tempnam(sys_get_temp_dir(), 'geocoder-');
        config([
            'reverse_geocoding.provider' => 'nominatim',
            'reverse_geocoding.user_agent' => 'GeocoderTest/1.0',
            'reverse_geocoding.contact_email' => 'public-service@example.test',
            'reverse_geocoding.lock_path' => $this->lockPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        parent::tearDown();
    }

    public function test_nominatim_sends_only_coordinates_and_explicit_public_contact_with_strict_timeouts(): void
    {
        $options = [];
        config(['reverse_geocoding.timeout' => 99, 'reverse_geocoding.connect_timeout' => 99]);
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response([
                'display_name' => 'Must not be used, 20112, Indonesia',
                'address' => [
                    'road' => ' Jalan  Contoh ', 'house_number' => '12', 'quarter' => 'Sei Sikambing B',
                    'suburb' => 'Ignored suburb', 'city_district' => 'Medan Sunggal', 'city' => 'Medan',
                    'state' => 'Sumatera Utara', 'postcode' => '20122', 'country' => 'Indonesia', 'country_code' => 'id',
                ],
            ]);
        });

        $this->assertSame(['address' => 'Jalan Contoh 12, Sei Sikambing B, Medan Sunggal, Medan', 'source' => 'nominatim'], app(ReverseGeocoder::class)->reverse(3.6, 98.6));
        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'GeocoderTest/1.0 (contact: public-service@example.test)')
            && (float) $request['lat'] === 3.6 && (float) $request['lon'] === 98.6
            && (int) $request['addressdetails'] === 1
            && $request['format'] === 'jsonv2' && $request['accept-language'] === 'id'
            && ! isset($request['email']));
        Http::assertSentCount(1);
        $this->assertEquals(5, $options['timeout']);
        $this->assertEquals(2, $options['connect_timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_google_uses_structured_components_without_postcode_or_country(): void
    {
        config(['reverse_geocoding.provider' => 'google', 'reverse_geocoding.google_api_key' => 'fake-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => 'Must not be used, 20122, Indonesia',
            'address_components' => [
                ['long_name' => '7', 'types' => ['street_number']],
                ['long_name' => 'Jalan Google', 'types' => ['route']],
                ['long_name' => 'Babura', 'types' => ['administrative_area_level_4', 'political']],
                ['long_name' => 'Medan Baru', 'types' => ['administrative_area_level_3', 'political']],
                ['long_name' => 'Kota Medan', 'types' => ['administrative_area_level_2', 'political']],
                ['long_name' => 'Medan', 'types' => ['locality', 'political']],
                ['long_name' => 'Sumatera Utara', 'types' => ['administrative_area_level_1', 'political']],
                ['long_name' => 'Indonesia', 'types' => ['country', 'political']],
                ['long_name' => '20122', 'types' => ['postal_code']],
            ],
        ]]])]);

        $this->assertSame(['address' => 'Jalan Google 7, Babura, Medan Baru, Kota Medan', 'source' => 'google'], app(ReverseGeocoder::class)->reverse(0, 0));
        Http::assertSent(fn (Request $request) => $request['key'] === 'fake-key' && $request['latlng'] === '0,0');
        Http::assertSentCount(1);
    }

    public function test_missing_contact_fails_before_any_nominatim_request(): void
    {
        config(['reverse_geocoding.contact_email' => null]);
        Http::fake();
        try {
            app(ReverseGeocoder::class)->reverse(3.6, 98.6);
            $this->fail('Missing contact must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('REVERSE_GEOCODING_CONTACT_EMAIL', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_google_key_fails_before_any_request(): void
    {
        config(['reverse_geocoding.provider' => 'google', 'reverse_geocoding.google_api_key' => null]);
        Http::fake();
        try {
            app(ReverseGeocoder::class)->reverse(3.6, 98.6);
            $this->fail('Missing key must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GOOGLE_MAPS_API_KEY', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_http_error_has_no_retry_or_provider_fallback(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertNull(app(ReverseGeocoder::class)->reverse(3.6, 98.6));
        Http::assertSentCount(1);
    }

    public function test_rate_limit_throws_after_one_attempt(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        try {
            app(ReverseGeocoder::class)->reverse(3.6, 98.6);
            $this->fail('429 must stop the caller.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('429', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_nominatim_deduplicates_and_skips_missing_parts(): void
    {
        Http::fake(['*' => Http::response(['address' => [
            'road' => 'Jalan Medan', 'village' => 'Medan', 'town' => 'medan', 'country' => 'Indonesia',
        ]])]);
        $this->assertSame('Jalan Medan, Medan', app(ReverseGeocoder::class)->reverse(3.6, 98.6)['address']);
    }

    public function test_google_api_key_alone_selects_google_provider(): void
    {
        $previous = [getenv('REVERSE_GEOCODING_PROVIDER'), getenv('GOOGLE_MAPS_API_KEY')];
        putenv('REVERSE_GEOCODING_PROVIDER');
        putenv('GOOGLE_MAPS_API_KEY=only-key');
        unset($_ENV['REVERSE_GEOCODING_PROVIDER'], $_SERVER['REVERSE_GEOCODING_PROVIDER']);
        $_ENV['GOOGLE_MAPS_API_KEY'] = $_SERVER['GOOGLE_MAPS_API_KEY'] = 'only-key';
        try {
            $config = require config_path('reverse_geocoding.php');
            $this->assertSame('google', $config['provider']);
            $this->assertSame('only-key', $config['google_api_key']);
        } finally {
            foreach (['REVERSE_GEOCODING_PROVIDER', 'GOOGLE_MAPS_API_KEY'] as $index => $name) {
                if ($previous[$index] === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name.'='.$previous[$index]);
                    $_ENV[$name] = $_SERVER[$name] = $previous[$index];
                }
            }
        }
    }

    public function test_timeout_has_one_attempt_and_persists_throttle_timestamp(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('fake timeout');
        });
        try {
            app(ReverseGeocoder::class)->reverse(3.6, 98.6);
            $this->fail('Timeout must propagate.');
        } catch (ConnectionException) {
            $this->assertSame(1, $attempts);
            $this->assertGreaterThan(0, (float) file_get_contents($this->lockPath));
        }
    }

    public function test_empty_or_malformed_success_payload_is_not_an_address(): void
    {
        Http::fake(['*' => Http::response(['display_name' => 'Only display name, Indonesia', 'address' => ['road' => ['bad']]])]);
        $this->assertNull(app(ReverseGeocoder::class)->reverse(3.6, 98.6));
        Http::assertSentCount(1);
    }

    public function test_google_denied_response_is_not_an_address(): void
    {
        config(['reverse_geocoding.provider' => 'google', 'reverse_geocoding.google_api_key' => 'fake-key']);
        Http::fake(['*' => Http::response(['status' => 'REQUEST_DENIED', 'results' => [['address_components' => [['long_name' => 'Must not persist', 'types' => ['route']]]]]])]);
        $this->assertNull(app(ReverseGeocoder::class)->reverse(3.6, 98.6));
        Http::assertSentCount(1);
    }

    public function test_invalid_coordinates_never_call_http(): void
    {
        Http::fake();
        foreach ([[91, 0], [0, -181], [NAN, 0], [0, INF]] as [$latitude, $longitude]) {
            try {
                app(ReverseGeocoder::class)->reverse($latitude, $longitude);
                $this->fail('Invalid coordinates must fail.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_throttle_is_shared_across_real_php_processes_with_fake_http(): void
    {
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'reverse_geocoding.provider' => 'nominatim',
    'reverse_geocoding.user_agent' => 'GeocoderTest/1.0',
    'reverse_geocoding.contact_email' => 'public-service@example.test',
    'reverse_geocoding.lock_path' => $argv[2],
]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function () {
    print sprintf('REQUEST_AT=%.6F', microtime(true));
    return Illuminate\Support\Facades\Http::response(['address' => ['road' => 'Fake street']]);
});
$app->make(App\Support\ReverseGeocoder::class)->reverse(3.6, 98.6);
PHP;
        $first = new Process([PHP_BINARY, '-r', $code, base_path(), $this->lockPath], base_path(), null, null, 20);
        $second = new Process([PHP_BINARY, '-r', $code, base_path(), $this->lockPath], base_path(), null, null, 20);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());
        preg_match('/REQUEST_AT=([0-9.]+)/', $first->getOutput(), $a);
        preg_match('/REQUEST_AT=([0-9.]+)/', $second->getOutput(), $b);
        $this->assertArrayHasKey(1, $a);
        $this->assertArrayHasKey(1, $b);
        $this->assertGreaterThanOrEqual(0.99, abs((float) $a[1] - (float) $b[1]));
    }
}

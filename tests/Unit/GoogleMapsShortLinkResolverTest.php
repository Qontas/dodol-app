<?php

namespace Tests\Unit;

use App\Support\GoogleMapsShortLinkResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMapsShortLinkResolverTest extends TestCase
{
    public function test_is_eligible_accepts_known_google_maps_short_link_hosts(): void
    {
        $this->assertTrue(GoogleMapsShortLinkResolver::isEligible('https://maps.app.goo.gl/abc123'));
        $this->assertTrue(GoogleMapsShortLinkResolver::isEligible('https://goo.gl/maps/xyz'));
        $this->assertTrue(GoogleMapsShortLinkResolver::isEligible('https://g.co/kgs/xyz'));
        $this->assertTrue(GoogleMapsShortLinkResolver::isEligible('https://www.google.com/maps/@3.5,98.6,15z'));
    }

    /**
     * SSRF safeguard: host lookalike/subdomain-trick DAN raw IP (mis. metadata
     * endpoint cloud 169.254.169.254) harus ditolak sebelum request apapun dibuat.
     */
    public function test_is_eligible_rejects_lookalike_and_non_allowlisted_hosts(): void
    {
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('https://evil.com/maps.app.goo.gl'));
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('https://maps.app.goo.gl.evil.com/x'));
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('https://notgoo.gl/x'));
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('https://localhost/x'));
        $this->assertFalse(GoogleMapsShortLinkResolver::isEligible('not a url at all'));
    }

    public function test_resolve_rejects_non_allowlisted_host_without_making_any_request(): void
    {
        Http::fake();

        $result = GoogleMapsShortLinkResolver::resolve('https://evil.com/x');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_resolve_follows_redirect_to_allowlisted_host(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/@3.5896,98.6739,17z',
            ]),
            'www.google.com/*' => Http::response('ok', 200),
        ]);

        $result = GoogleMapsShortLinkResolver::resolve('https://maps.app.goo.gl/abc123');

        $this->assertSame('https://www.google.com/maps/@3.5896,98.6739,17z', $result);
    }

    /**
     * SSRF safeguard: kalau short link (host allowlisted) redirect ke host DI
     * LUAR allowlist, chain harus diputus di situ — TIDAK boleh diam-diam
     * dilanjutkan hanya karena hop pertama tadinya sah.
     */
    public function test_resolve_rejects_redirect_chain_that_escapes_to_non_allowlisted_host(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://evil.com/steal-internal-data',
            ]),
        ]);

        $result = GoogleMapsShortLinkResolver::resolve('https://maps.app.goo.gl/abc123');

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_redirect_has_no_location_header(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, []),
        ]);

        $this->assertNull(GoogleMapsShortLinkResolver::resolve('https://maps.app.goo.gl/abc123'));
    }

    public function test_resolve_returns_null_when_redirect_chain_exceeds_max_hops(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://maps.app.goo.gl/abc123',
            ]),
        ]);

        $this->assertNull(GoogleMapsShortLinkResolver::resolve('https://maps.app.goo.gl/abc123'));
    }

    public function test_resolve_returns_null_on_connection_failure(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
        ]);

        $this->assertNull(GoogleMapsShortLinkResolver::resolve('https://maps.app.goo.gl/abc123'));
    }
}

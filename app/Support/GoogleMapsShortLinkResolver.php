<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Resolve link pendek Google Maps (maps.app.goo.gl / goo.gl/maps / g.co/kgs)
 * ke URL panjang lewat HTTP redirect — SATU-SATUNYA tempat di aplikasi yang
 * boleh nembak URL milik user ke luar. TIER 2 best-effort: dipakai HANYA
 * setelah KioskLocationParser::parse() gagal (Tier 1, murni string, 0 network).
 *
 * SSRF safeguard: host di-cek terhadap allowlist di SETIAP hop redirect (bukan
 * cuma URL awal) — link pendek yang di-redirect ke host non-Google ditolak,
 * begitu juga kalau host itu resolve ke IP privat/internal.
 */
class GoogleMapsShortLinkResolver
{
    private const ALLOWED_HOSTS = [
        'goo.gl',
        'maps.app.goo.gl',
        'g.co',
        'google.com',
        'www.google.com',
        'maps.google.com',
    ];

    private const MAX_REDIRECTS = 5;

    private const TIMEOUT_SECONDS = 5;

    public static function isEligible(string $url): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return $host !== null && self::hostAllowed($host);
    }

    /**
     * Follow redirect chain hop-by-hop, validasi allowlist + bukan IP privat
     * di tiap hop. Balikin URL akhir (buat diparse KioskLocationParser::parse())
     * atau null kalau gagal/ditolak/timeout — TIDAK PERNAH throw ke caller.
     */
    public static function resolve(string $url): ?string
    {
        $current = trim($url);

        if (! self::hopAllowed($current)) {
            return null;
        }

        for ($hop = 0; $hop < self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withoutRedirecting()
                    ->withUserAgent('Mozilla/5.0 (compatible; DodolAppKioskLocation/1.0)')
                    ->get($current);
            } catch (\Throwable $e) {
                return null;
            }

            if (! $response->redirect()) {
                return $response->successful() ? $current : null;
            }

            $location = $response->header('Location');
            if (! $location) {
                return null;
            }

            $current = self::resolveRelative($current, $location);

            if (! self::hopAllowed($current)) {
                return null;
            }
        }

        return null;
    }

    private static function hopAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || ! self::hostAllowed($host)) {
            return false;
        }

        return ! self::resolvesToPrivateIp($host);
    }

    private static function hostAllowed(string $host): bool
    {
        $host = strtolower($host);

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pertahanan tambahan (defense in depth) terhadap DNS rebinding — host
     * sudah lolos allowlist tapi resolve ke IP privat/internal tetap ditolak.
     */
    private static function resolvesToPrivateIp(string $host): bool
    {
        $ips = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_filter(array_merge(array_column($ips, 'ip'), array_column($ips, 'ipv6')));

        if (empty($addresses)) {
            // Gagal resolve DNS sama sekali — anggap tidak aman, biar ditolak.
            return true;
        }

        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }

    private static function resolveRelative(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';

        return $scheme.'://'.$host.$location;
    }
}

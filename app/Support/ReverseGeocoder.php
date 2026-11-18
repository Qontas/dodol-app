<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class ReverseGeocoder
{
    /** @return array{address: string, source: string}|null */
    public function reverse(float $latitude, float $longitude): ?array
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Koordinat tidak valid.');
        }

        $provider = (string) config('reverse_geocoding.provider', 'nominatim');
        $userAgent = trim((string) config('reverse_geocoding.user_agent'));
        if ($userAgent === '' || preg_match('/[\r\n]/', $userAgent)) {
            throw new RuntimeException('Isi REVERSE_GEOCODING_USER_AGENT dengan nama aplikasi yang valid.');
        }
        if ($provider === 'nominatim') {
            $contact = trim((string) config('reverse_geocoding.contact_email'));
            if (! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Isi REVERSE_GEOCODING_CONTACT_EMAIL dengan kontak layanan publik yang boleh dikirim ke Nominatim.');
            }
            $userAgent .= ' (contact: '.$contact.')';
            $url = 'https://nominatim.openstreetmap.org/reverse';
            $query = ['format' => 'jsonv2', 'lat' => $latitude, 'lon' => $longitude, 'accept-language' => 'id', 'addressdetails' => 1];
        } elseif ($provider === 'google') {
            $key = trim((string) config('reverse_geocoding.google_api_key'));
            if ($key === '') {
                throw new RuntimeException('Isi GOOGLE_MAPS_API_KEY untuk provider google.');
            }
            $url = 'https://maps.googleapis.com/maps/api/geocode/json';
            $query = ['latlng' => $latitude.','.$longitude, 'language' => 'id', 'key' => $key];
        } else {
            throw new RuntimeException('REVERSE_GEOCODING_PROVIDER harus nominatim atau google.');
        }

        return $this->throttled(function () use ($url, $query, $userAgent, $provider): ?array {
            // No retry(), no fallback request, no redirects (including 301/302).
            $response = Http::acceptJson()->withUserAgent($userAgent)
                ->connectTimeout(max(0.1, min(2, (float) config('reverse_geocoding.connect_timeout', 2))))
                ->timeout(max(0.1, min(5, (float) config('reverse_geocoding.timeout', 5))))
                ->withoutRedirecting()->get($url, $query);

            if ($response->status() === 429) {
                throw new RuntimeException('Provider membatasi permintaan (429). Hentikan backfill tanpa retry.');
            }
            if (! $response->successful()) {
                return null;
            }
            if ($provider === 'google' && $response->json('status') !== 'OK') {
                return null;
            }
            $address = $provider === 'google'
                ? $this->googleAddress($response->json('results.0.address_components'))
                : $this->nominatimAddress($response->json('address'));

            return $address === '' ? null : ['address' => $address, 'source' => $provider];
        });
    }

    private function nominatimAddress(mixed $address): string
    {
        if (! is_array($address)) {
            return '';
        }
        $road = $this->firstPart($address, ['road', 'pedestrian', 'residential', 'footway', 'path']);
        $number = $this->firstPart($address, ['house_number']);

        return $this->compact([
            trim($road.' '.$number),
            $this->firstPart($address, ['quarter', 'suburb', 'village', 'hamlet', 'neighbourhood']),
            $this->firstPart($address, ['city_district', 'municipality', 'district']),
            $this->firstPart($address, ['city', 'town', 'county']),
        ]);
    }

    private function googleAddress(mixed $components): string
    {
        if (! is_array($components)) {
            return '';
        }
        $address = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['long_name'] ?? null)
                || ! is_array($component['types'] ?? null)) {
                continue;
            }
            foreach ($component['types'] as $type) {
                if (is_string($type)) {
                    $address[$type] = $component['long_name'];
                }
            }
        }

        return $this->compact([
            trim($this->firstPart($address, ['route']).' '.$this->firstPart($address, ['street_number'])),
            $this->firstPart($address, ['administrative_area_level_4', 'sublocality_level_2', 'sublocality', 'neighborhood']),
            $this->firstPart($address, ['administrative_area_level_3', 'sublocality_level_1']),
            $this->firstPart($address, ['administrative_area_level_2', 'locality']),
        ]);
    }

    private function firstPart(array $address, array $keys): string
    {
        foreach ($keys as $key) {
            if (is_string($address[$key] ?? null) && trim($address[$key]) !== '') {
                return trim($address[$key]);
            }
        }

        return '';
    }

    private function compact(array $parts): string
    {
        $unique = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
            if ($part !== '') {
                $unique[mb_strtolower($part)] ??= $part;
            }
        }

        // Never fall back to display_name/formatted_address: those include
        // country/postcode and cannot safely be stripped by comma position.
        return implode(', ', array_values($unique));
    }

    /**
     * File lock + persisted timestamp, NOT array cache or process-local sleeps.
     * Held through HTTP completion so concurrent commands cannot burst requests.
     * Fail closed if the shared throttle is unavailable; never unlink this file.
     */
    private function throttled(callable $request): mixed
    {
        $file = @fopen((string) config('reverse_geocoding.lock_path'), 'c+');
        if ($file === false) {
            throw new RuntimeException('Tidak dapat membuka lock reverse geocoding.');
        }
        try {
            $deadline = microtime(true) + 10;
            while (! flock($file, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Reverse geocoding sedang dipakai proses lain. Coba lagi nanti.');
                }
                usleep(50_000);
            }
            rewind($file);
            $stored = trim(stream_get_contents($file));
            if ($stored !== '' && (! is_numeric($stored) || ! is_finite((float) $stored))) {
                throw new RuntimeException('Timestamp lock reverse geocoding tidak valid.');
            }
            $wait = (float) $stored + 1 - microtime(true);
            if ($wait > 2) {
                throw new RuntimeException('Jam sistem berubah; tunggu sebelum mengulang reverse geocoding.');
            }
            while ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
                $wait = (float) $stored + 1 - microtime(true);
            }
            // Persist before sending (fail closed on disk error), then again
            // after completion, including exceptions. A full second AFTER the
            // last response avoids bursts even if HTTP preparation is slow.
            $mark = static function () use ($file): void {
                rewind($file);
                $timestamp = sprintf('%.6F', microtime(true));
                if (! ftruncate($file, 0) || fwrite($file, $timestamp) !== strlen($timestamp) || ! fflush($file)) {
                    throw new RuntimeException('Tidak dapat menyimpan timestamp reverse geocoding.');
                }
            };
            $mark();
            try {
                return $request();
            } finally {
                $mark();
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}

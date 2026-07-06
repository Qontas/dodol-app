<?php

namespace App\Support;

/**
 * Parser lokasi kios dari kolom "campuran" milik spreadsheet abang
 * (DMS / Google Maps link / teks biasa). Dipakai bersama oleh
 * App\Filament\Imports\KioskImporter dan command kios:import — satu sumber
 * kebenaran untuk math DMS, jangan diduplikasi.
 */
class KioskLocationParser
{
    /**
     * Parse koordinat DMS — mis. "3° 36' 15.5196" N 98° 39' 54.072" E"
     * menjadi desimal. Mengembalikan null kalau bukan format DMS.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function dmsToDecimal(string $value): ?array
    {
        $value = trim($value);

        if (! preg_match('/(\d+)°\s*(\d+)\'\s*([\d.]+)"\s*([NS])\s+(\d+)°\s*(\d+)\'\s*([\d.]+)"\s*([EW])/u', $value, $m)) {
            return null;
        }

        $lat = $m[1] + $m[2] / 60 + $m[3] / 3600;
        $lng = $m[5] + $m[6] / 60 + $m[7] / 3600;
        if ($m[4] === 'S') {
            $lat = -$lat;
        }
        if ($m[8] === 'W') {
            $lng = -$lng;
        }

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    /**
     * Parser utama untuk field "tempel link/koordinat Google Maps" di form kios
     * (owner Filament + operator Livewire). Coba semua format yang dikenal secara
     * berurutan, murni string parsing — TIDAK ada network call di sini (link
     * pendek goo.gl/maps.app.goo.gl butuh resolve redirect dulu, lihat
     * App\Services\GoogleMapsShortLinkResolver, baru hasilnya dilempar ke sini).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parse(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return self::parseCoordinatePair($value)
            ?? self::dmsToDecimal($value)
            ?? self::parseGoogleMapsUrl($value);
    }

    /**
     * Koordinat diketik langsung, mis. "3.5896, 98.6739" (spasi opsional).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parseCoordinatePair(string $value): ?array
    {
        $value = trim($value);

        if (! preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $value, $m)) {
            return null;
        }

        return self::validated((float) $m[1], (float) $m[2]);
    }

    /**
     * Link Google Maps PANJANG (browser/desktop) — koordinat sudah ada di URL,
     * tidak perlu network call. Dicoba berurutan dari yang paling presisi:
     *   1. !3dLAT!4dLNG — titik marker/place persis (dari param `data=`).
     *   2. @lat,lng,zoom — pusat viewport peta (dipakai kalau tidak ada data param).
     *   3. ?q=lat,lng / &q=lat,lng
     *   4. ?ll=lat,lng / &ll=lat,lng
     * Google selalu urutan lat,lng (bukan lng,lat) di semua pola ini.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parseGoogleMapsUrl(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/', $value, $m)) {
            return self::validated((float) $m[1], (float) $m[2]);
        }

        if (preg_match('/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $value, $m)) {
            return self::validated((float) $m[1], (float) $m[2]);
        }

        if (preg_match('/[?&]q=(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $value, $m)) {
            return self::validated((float) $m[1], (float) $m[2]);
        }

        if (preg_match('/[?&]ll=(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $value, $m)) {
            return self::validated((float) $m[1], (float) $m[2]);
        }

        return null;
    }

    /**
     * Guard batas lat/lng — jaring pengaman kalau regex ke-match tapi angkanya
     * di luar jangkauan valid (mis. data korup / salah tempel).
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function validated(float $lat, float $lng): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
    }
}

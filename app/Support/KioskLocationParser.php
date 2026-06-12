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
}

<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Tanggal berbahasa Indonesia. APP_LOCALE proyek 'en' & tak ada Carbon::setLocale(),
 * jadi translatedFormat() menghasilkan Inggris — nama bulan ditulis manual di sini.
 */
class IdDate
{
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function bulan(int $month): string
    {
        return self::BULAN[$month] ?? '';
    }

    /** "15 November 2026" */
    public static function tanggal(CarbonInterface $date): string
    {
        return $date->day.' '.self::bulan($date->month).' '.$date->year;
    }

    /** "November 2026" dari 'YYYY-MM' */
    public static function bulanTahun(string $period): string
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return self::bulan($m).' '.$y;
    }
}

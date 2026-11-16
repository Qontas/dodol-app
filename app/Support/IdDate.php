<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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

    private const BULAN_SINGKAT = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Indeks Carbon dayOfWeek: 0 = Minggu. */
    private const HARI_SINGKAT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    public static function hariSingkat(CarbonInterface $date): string
    {
        return self::HARI_SINGKAT[$date->dayOfWeek];
    }

    /** "16 Nov 2026" */
    public static function tanggalSingkat(CarbonInterface $date): string
    {
        return $date->day.' '.self::BULAN_SINGKAT[$date->month].' '.$date->year;
    }

    /** Singkatan zona Indonesia; zona lain memakai singkatan PHP-nya (mis. "UTC") — tidak pernah dipalsukan. */
    public static function zona(CarbonInterface $date): string
    {
        return match ($date->getTimezone()->getName()) {
            'Asia/Jakarta' => 'WIB',
            'Asia/Makassar' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => $date->format('T'),
        };
    }

    /**
     * "16 Nov 2026, 18:18 WIB" — dikonversi ke $timezone (default zona aplikasi), bukan ISO/UTC.
     * Menerima string ISO apa pun (mis. "2026-11-16T11:18:00+00:00").
     */
    public static function waktu(CarbonInterface|string $moment, ?string $timezone = null): string
    {
        $local = Carbon::parse($moment)->setTimezone($timezone ?: config('app.timezone'));

        return self::tanggalSingkat($local).', '.$local->format('H:i').' '.self::zona($local);
    }

    /** "20 Okt · Sen" — bulan & tahun sudah tertulis di baris Periode. */
    public static function tanggalHari(CarbonInterface $date): string
    {
        return $date->day.' '.self::BULAN_SINGKAT[$date->month].' · '.self::hariSingkat($date);
    }

    /** "November 2026" dari 'YYYY-MM' */
    public static function bulanTahun(string $period): string
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return self::bulan($m).' '.$y;
    }
}

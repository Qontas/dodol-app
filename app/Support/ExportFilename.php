<?php

namespace App\Support;

/**
 * Nama file export yang JELAS & MANUSIAWI (BRIEF 26 Okt 2026 item 1 — keluhan owner
 * "trip-report-2026-10-26.pdf" tak jelas isinya). Format baku:
 *   Laporan-Trip-{Y-m-d}-{Operator}.pdf/.xlsx
 *   Laporan-{BulanIndonesia}-{Tahun}-{NamaUsaha}.pdf/.xlsx
 *   Laporan-Periode-{LabelPeriode}-{NamaUsaha}.pdf/.xlsx
 * Nama (operator/usaha) di-slug: spasi -> "-", karakter non alfanumerik dibuang —
 * aman dipakai sebagai nama file di semua OS/browser (termasuk Content-Disposition).
 */
class ExportFilename
{
    public static function slug(string $value, string $fallback = 'Data'): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\s-]/', '', $value) ?? '';
        $clean = trim(preg_replace('/[\s-]+/', '-', $clean) ?? '', '-');

        return $clean !== '' ? $clean : $fallback;
    }

    public static function tripPdf(string $tripDate, ?string $operatorName): string
    {
        return self::tripName($tripDate, $operatorName, 'pdf');
    }

    public static function tripExcel(string $tripDate, ?string $operatorName): string
    {
        return self::tripName($tripDate, $operatorName, 'xlsx');
    }

    private static function tripName(string $tripDate, ?string $operatorName, string $ext): string
    {
        $operator = self::slug($operatorName ?? '', 'Operator');

        return "Laporan-Trip-{$tripDate}-{$operator}.{$ext}";
    }

    public static function monthlyPdf(string $month, string $businessName): string
    {
        return self::monthlyName($month, $businessName, 'pdf');
    }

    public static function monthlyExcel(string $month, string $businessName): string
    {
        return self::monthlyName($month, $businessName, 'xlsx');
    }

    private static function monthlyName(string $month, string $businessName, string $ext): string
    {
        $bulan = \Illuminate\Support\Carbon::parse($month.'-01')->translatedFormat('F');
        $tahun = \Illuminate\Support\Carbon::parse($month.'-01')->format('Y');
        $usaha = self::slug($businessName, 'CemilanQontas');

        return "Laporan-{$bulan}-{$tahun}-{$usaha}.{$ext}";
    }

    public static function periodePdf(string $periodeLabel, string $businessName): string
    {
        return self::periodeName($periodeLabel, $businessName, 'pdf');
    }

    public static function periodeExcel(string $periodeLabel, string $businessName): string
    {
        return self::periodeName($periodeLabel, $businessName, 'xlsx');
    }

    private static function periodeName(string $periodeLabel, string $businessName, string $ext): string
    {
        $label = self::slug($periodeLabel, 'Periode');
        $usaha = self::slug($businessName, 'CemilanQontas');

        return "Laporan-Periode-{$label}-{$usaha}.{$ext}";
    }
}

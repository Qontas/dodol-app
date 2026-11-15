<?php

namespace App\Support;

/** Angka → kata Indonesia ("Empat ratus dua puluh ribu tiga ratus rupiah"). */
class Terbilang
{
    private const SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function rupiah(int|float $amount): string
    {
        $n = (int) round(abs($amount));
        $words = $n === 0 ? 'nol' : self::say($n);

        return ucfirst(trim($words)).' rupiah';
    }

    private static function say(int $n): string
    {
        $rest = fn (int $r): string => $r > 0 ? ' '.self::say($r) : '';

        return match (true) {
            $n < 12 => self::SATUAN[$n],
            $n < 20 => self::say($n - 10).' belas',
            $n < 100 => self::say(intdiv($n, 10)).' puluh'.$rest($n % 10),
            $n < 200 => 'seratus'.$rest($n - 100),
            $n < 1000 => self::say(intdiv($n, 100)).' ratus'.$rest($n % 100),
            $n < 2000 => 'seribu'.$rest($n - 1000),
            $n < 1_000_000 => self::say(intdiv($n, 1000)).' ribu'.$rest($n % 1000),
            $n < 1_000_000_000 => self::say(intdiv($n, 1_000_000)).' juta'.$rest($n % 1_000_000),
            $n < 1_000_000_000_000 => self::say(intdiv($n, 1_000_000_000)).' miliar'.$rest($n % 1_000_000_000),
            default => self::say(intdiv($n, 1_000_000_000_000)).' triliun'.$rest($n % 1_000_000_000_000),
        };
    }
}

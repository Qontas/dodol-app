<?php

namespace App\Console\Commands;

use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Pastikan tiap owner punya tepat 1 kios sentinel walk-in (+ cluster sentinel).
 *
 * Sentinel dipakai untuk mencatat penjualan cash ke pembeli walk-in (bukan kios
 * terdaftar) — lihat ActiveTrip::saveWalkInCash(). Sentinel auto-dibuat saat
 * walk-in pertama, tapi command ini memprovisi owner yang sudah ada lebih dulu.
 * Idempoten: owner yang sentinel-nya sudah ada dilewati (firstOrCreate).
 */
class EnsureWalkInSentinel extends Command
{
    protected $signature = 'walkin:ensure-sentinel {--owner= : Batasi ke 1 ID owner saja}';

    protected $description = 'Pastikan tiap owner punya kios sentinel penjualan walk-in.';

    public function handle(): int
    {
        $owners = User::where('role', 'owner')
            ->when($this->option('owner'), fn ($q) => $q->whereKey($this->option('owner')))
            ->get();

        if ($owners->isEmpty()) {
            $this->warn('Tidak ada owner yang cocok.');
            return self::SUCCESS;
        }

        foreach ($owners as $owner) {
            $sentinel = Kiosk::walkInSentinelFor($owner->id);
            $this->line(sprintf('Owner #%d (%s) → sentinel kiosk #%d', $owner->id, $owner->name, $sentinel->id));
        }

        $this->info("Selesai. {$owners->count()} owner diproses.");

        return self::SUCCESS;
    }
}

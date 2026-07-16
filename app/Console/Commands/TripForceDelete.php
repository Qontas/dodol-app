<?php

namespace App\Console\Commands;

use App\Models\Trip;
use Illuminate\Console\Command;

/**
 * HAPUS PERMANEN trip beserta data anaknya (hard delete berantai via FK cascade).
 *
 * ⚠️ TIDAK BISA DI-UNDO. Sengaja HANYA tersedia lewat command eksplisit ini, JANGAN
 * dari tombol UI (tombol dashboard cuma mengARSIP). Dipakai untuk membersihkan data
 * UJI, bukan operasi harian. FK cascade DB akan ikut menghapus kiosk_visits, deliveries,
 * settlements, delivery_origins, dan commissions milik trip ini:
 *     php artisan trip:force-delete {id}
 */
class TripForceDelete extends Command
{
    protected $signature = 'trip:force-delete {id : ID trip yang mau dihapus permanen}';

    protected $description = 'HAPUS PERMANEN trip + data anak (cascade). Tidak bisa di-undo — hanya untuk data uji';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        // withTrashed() agar trip yang sudah diarsip pun bisa dihapus permanen.
        $trip = Trip::withTrashed()->find($id);

        if (! $trip) {
            $this->error("Trip #{$id} tidak ditemukan.");

            return self::FAILURE;
        }

        if (! $this->confirm("HAPUS PERMANEN Trip #{$id} beserta SEMUA data anaknya? Tidak bisa di-undo.")) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $trip->forceDelete();

        $this->info("Trip #{$id} dihapus permanen beserta data anaknya.");

        return self::SUCCESS;
    }
}

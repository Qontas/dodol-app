<?php

namespace App\Console\Commands;

use App\Models\Trip;
use Illuminate\Console\Command;

/**
 * Pulihkan (unarchive) trip yang diARSIP via tombol "Arsipkan" di dashboard.
 *
 * KENAPA ADA: tombol dashboard hanya mengARSIP (soft delete) trip — trip & seluruh
 * data anak (kiosk_visits, deliveries, settlements, commissions) tetap utuh, cuma
 * disembunyikan dari laporan/agregat. Command ini mengembalikannya (deleted_at = NULL)
 * sehingga muncul lagi di dashboard & laporan. UI restore boleh menyusul; untuk kini
 * ini jalur resmi memulihkan trip salah-arsip:
 *     php artisan trip:restore {id}
 */
class TripRestore extends Command
{
    protected $signature = 'trip:restore {id : ID trip yang mau dipulihkan}';

    protected $description = 'Pulihkan trip yang diarsip (soft delete) agar muncul lagi di laporan';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        // withTrashed() perlu karena trip terarsip disembunyikan global scope.
        $trip = Trip::withTrashed()->find($id);

        if (! $trip) {
            $this->error("Trip #{$id} tidak ditemukan.");

            return self::FAILURE;
        }

        if (! $trip->trashed()) {
            $this->warn("Trip #{$id} tidak sedang diarsip — tak ada yang dipulihkan.");

            return self::SUCCESS;
        }

        $trip->restore();

        $this->info("Trip #{$id} (tanggal {$trip->trip_date->format('d M Y')}) berhasil dipulihkan.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;

class TripDeleteController extends Controller
{
    public function destroy(Trip $trip): RedirectResponse
    {
        // Otorisasi: hanya owner pemilik trip atau super admin.
        abort_unless(
            $trip->owner_id === auth()->id() || auth()->user()->isSuperAdmin(),
            403
        );

        // Trip sentinel migrasi (saldo awal kedai lama) tak boleh diarsip: trip ini
        // disembunyikan dari Riwayat Trip (scopeRealOperational), bukan via arsip, dan
        // kolom unik (owner_id, ended_reason, operator_id) dibaca OpeningBalance::
        // migrationTripFor() lewat firstOrCreate() — kalau diarsip, SoftDeletes global
        // scope membuatnya tak ketemu lagi → firstOrCreate mencoba INSERT baris baru
        // dengan kombinasi sama → 500 (unique constraint). Lihat Trip::isMigrationSentinel().
        abort_if($trip->isMigrationSentinel(), 403, 'Trip migrasi saldo awal tidak bisa diarsip.');

        // ARSIP (soft delete), BUKAN hapus permanen. Trait SoftDeletes pada model Trip
        // membuat delete() hanya mengisi kolom deleted_at → trip disembunyikan dari
        // dashboard & laporan/agregat, TAPI TIDAK ada DB DELETE sehingga FK cascade
        // tidak jalan: kiosk_visits, deliveries, settlements, delivery_origins, dan
        // commissions tetap utuh. Pulihkan lewat `php artisan trip:restore {id}`.
        // Hard delete permanen HANYA lewat `php artisan trip:force-delete {id}`.
        $trip->delete();

        return redirect()->back()->with('status', 'Trip diarsipkan. Data disembunyikan dari laporan, tapi bisa dipulihkan.');
    }

    /**
     * PULIHKAN trip terarsip (kebalikan destroy: kosongkan deleted_at) → trip kembali
     * muncul & dihitung di laporan/dashboard/agregat. Route pakai withTrashed agar
     * trip terarsip bisa ter-bind. Guard sama: hanya owner pemilik / super admin.
     */
    public function restore(Trip $trip): RedirectResponse
    {
        abort_unless(
            $trip->owner_id === auth()->id() || auth()->user()->isSuperAdmin(),
            403
        );

        $trip->restore();

        return redirect()->back()->with('status', 'Trip dipulihkan. Kembali dihitung di laporan.');
    }
}

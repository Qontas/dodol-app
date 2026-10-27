<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TripDeleteController extends Controller
{
    /**
     * Filter yang dipertahankan saat redirect balik ke index (lihat FILTER_KEYS sama
     * di TripHistoryController) — SATU daftar whitelist, tak ada input bebas lain yang
     * ikut jadi query string.
     */
    private const FILTER_KEYS = ['status', 'operator_id', 'from', 'to'];

    public function destroy(Request $request, Trip $trip): RedirectResponse
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

        // BRIEF 27 Okt 2026 "KEJELASAN ARSIP" — pesan eksplisit + 'archived_trip_id' flash
        // supaya view bisa render tombol "Batalkan" (form mini restore) tepat di bawah
        // pesan, tanpa owner perlu cari-cari baris trip-nya lagi.
        return $this->redirectAfterAction($request, $trip)
            ->with('status', "Trip #{$trip->trip_number_of_day} diarsipkan. Lihat di Status → Diarsip.")
            ->with('archived_trip_id', $trip->id);
    }

    /**
     * PULIHKAN trip terarsip (kebalikan destroy: kosongkan deleted_at) → trip kembali
     * muncul & dihitung di laporan/dashboard/agregat. Route pakai withTrashed agar
     * trip terarsip bisa ter-bind. Guard sama: hanya owner pemilik / super admin.
     */
    public function restore(Request $request, Trip $trip): RedirectResponse
    {
        abort_unless(
            $trip->owner_id === auth()->id() || auth()->user()->isSuperAdmin(),
            403
        );

        $trip->restore();

        return $this->redirectAfterAction($request, $trip)
            ->with('status', "Trip #{$trip->trip_number_of_day} dipulihkan. Kembali dihitung di laporan.");
    }

    /**
     * Tujuan redirect SETELAH arsip/pulihkan — SELALU salah satu dari 2 rute tetap,
     * BUKAN redirect()->back()/url()->previous() (lihat BRIEF 27 Okt 2026 "KEMBALI
     * MENAMPILKAN sw.js" — rawan ketiban Referer/_previous.url dari request non-halaman
     * seperti /csrf-token & /kiosks/{id}/photo, lihat App\Http\Middleware\StartSession)
     * dan BUKAN URL bebas dari input (celah open-redirect) — 'redirect_to' cuma SAKLAR
     * dua rute tetap (whitelist in_array), tak pernah dipakai sbg URL itu sendiri.
     */
    private function redirectAfterAction(Request $request, Trip $trip): RedirectResponse
    {
        if ($request->get('redirect_to') === 'show') {
            return redirect()->route('owner.trips.show', $trip);
        }

        $filters = array_filter(
            $request->only(self::FILTER_KEYS),
            fn ($v) => $v !== null && $v !== ''
        );

        return redirect()->route('owner.trips.index', $filters);
    }
}

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

        // ARSIP (soft delete), BUKAN hapus permanen. Trait SoftDeletes pada model Trip
        // membuat delete() hanya mengisi kolom deleted_at → trip disembunyikan dari
        // dashboard & laporan/agregat, TAPI TIDAK ada DB DELETE sehingga FK cascade
        // tidak jalan: kiosk_visits, deliveries, settlements, delivery_origins, dan
        // commissions tetap utuh. Pulihkan lewat `php artisan trip:restore {id}`.
        // Hard delete permanen HANYA lewat `php artisan trip:force-delete {id}`.
        $trip->delete();

        return redirect()->back()->with('status', 'Trip diarsipkan. Data disembunyikan dari laporan, tapi bisa dipulihkan.');
    }
}

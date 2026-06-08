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

        // Hapus permanen. FK cascade DB menghapus kiosk_visits, deliveries,
        // settlements, delivery_origins, dan commissions terkait.
        $trip->delete();

        return redirect()->back()->with('status', 'Trip berhasil dihapus.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppFeeInvoice;
use App\Models\User;
use App\Support\AppFeeInvoicePdf;
use App\Support\AppFeeInvoiceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unduh PDF invoice fee aplikasi — SUPER ADMIN SAJA (route di-group middleware
 * role:super_admin; owner/operator → 403). Fee aplikasi tak boleh terlihat owner/operator.
 */
class AppFeeInvoiceController extends Controller
{
    /** Invoice tersimpan (terbit / lunas / dibatalkan) — PDF dari snapshot beku. */
    public function show(AppFeeInvoice $invoice): Response
    {
        return AppFeeInvoicePdf::download(AppFeeInvoicePdf::dataFromInvoice($invoice));
    }

    /**
     * Owner + bulan: invoice aktif kalau ada, kalau belum → DRAFT live.
     * Dipakai tombol "Unduh Invoice" di widget & form "Buat / Unduh Draft".
     */
    public function forOwnerPeriod(Request $request, int $owner, string $period): Response
    {
        $request->merge(['owner_id' => $owner, 'period' => $period])->validate([
            'owner_id' => ['required', 'integer'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $ownerUser = User::query()->where('role', 'owner')->findOrFail($owner);

        abort_unless(AppFeeInvoiceService::periodNotFuture($period), 422, 'Periode di masa depan belum bisa dibuat invoice.');

        if ($invoice = AppFeeInvoiceService::activeFor($ownerUser->id, $period)) {
            return AppFeeInvoicePdf::download(AppFeeInvoicePdf::dataFromInvoice($invoice));
        }

        $draft = AppFeeInvoicePdf::dataForDraft($ownerUser, $period);
        abort_if($draft['total_mika'] <= 0 || $draft['total_fee'] <= 0, 422, 'Fee owner ini di periode tersebut Rp 0 — tidak perlu invoice.');

        return AppFeeInvoicePdf::download($draft);
    }
}

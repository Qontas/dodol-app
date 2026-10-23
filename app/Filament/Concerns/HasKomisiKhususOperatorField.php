<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Forms\Get;

/**
 * BRIEF 2 Section 3 (UserResource, 21 Okt 2026) + BRIEF D9 (OperatorResource,
 * 23 Okt 2026) — SATU SUMBER KEBENARAN untuk rumus tarif default usaha yang
 * ditampilkan di helper text field komisi_per_mika_khusus pada KEDUA resource
 * (UserResource form super admin/owner & OperatorResource form owner). Field,
 * label, dan markup form boleh beda tampilan per resource (kebutuhan UI-nya
 * beda) — yang TIDAK boleh dobel adalah RUMUS resolusi tarifnya.
 */
trait HasKomisiKhususOperatorField
{
    /**
     * Tarif default usaha (owner) yang berlaku untuk operator yang SEDANG
     * diisi di form — owner viewer: selalu dirinya sendiri (operator yang
     * dibuat/diedit owner SELALU terikat ke owner itu, lihat CreateUser/
     * EditUser/ManageOperators mutate*BeforeSave). Super admin: owner yang
     * DIPILIH di field 'owner_id' (bisa berubah sebelum disimpan, makanya
     * field itu ->live()). Fallback 1000 kalau owner belum dipilih (form
     * create kosong).
     */
    protected static function resolveOwnerDefaultKomisiRate(Get $get): float
    {
        if (auth()->user()?->isOwner()) {
            return auth()->user()->getKomisiPerMikaValue();
        }

        $ownerId = $get('owner_id');

        return $ownerId
            ? (float) (User::find($ownerId)?->getKomisiPerMikaValue() ?? 1000)
            : 1000.0;
    }
}

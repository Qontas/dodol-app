<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerSettingsController extends Controller
{
    public function index(): View
    {
        return view('owner.settings', ['user' => auth()->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        // hpp_per_mika DIHAPUS dari form ini — HPP sekarang per-varian produk
        // (ProductVariant::cost_per_pack), bukan satu angka global per owner.
        // Kolom tabel readonly super-admin di UserResource DIBIARKAN (informasional,
        // audit data lama) — bukan jalur kalkulasi aktif.
        // Enam pengaturan di bawah sudah punya kolom DB dan accessor dengan fallback;
        // Settings ini melengkapi jalur edit milik owner. Nilai default kedai hanya
        // dipakai saat membuat kios baru — kios/trip yang sudah ada tidak disentuh.
        // komisi_kios_baru_per_mika DIHAPUS dari form (23 Okt 2026) — komisi sudah flat
        // (Trip::getKomisiKiosBaruAttribute() hardcode 0.0), isian ini tak berpengaruh ke
        // perhitungan apa pun. Kolom DB SENGAJA tidak didrop; tak lagi divalidasi/ditulis
        // dari sini. Lihat NEXT_SESSION.md.
        $data = $request->validate([
            'business_name' => 'nullable|string|max:60',
            'harga_mika' => 'required|numeric|min:0',
            'komisi_per_mika' => 'required|numeric|min:0',
            'default_target_visit_interval_days' => 'required|integer|min:1|max:365',
            'default_warning_visit_interval_days' => 'required|integer|min:1|max:365|gte:default_target_visit_interval_days',
            'default_fast_mover_threshold_days' => 'required|integer|min:1|max:365',
            'kios_baru_days' => 'required|integer|min:1|max:365',
            'default_qty_mika' => 'required|integer|min:1|max:100',
        ], [
            'business_name.max' => 'Nama usaha maksimal 60 karakter.',
            'harga_mika.required' => 'Harga mika wajib diisi.',
            'harga_mika.min' => 'Harga mika minimal Rp 0.',
            'komisi_per_mika.required' => 'Komisi reguler per mika wajib diisi.',
            'komisi_per_mika.min' => 'Komisi reguler per mika minimal Rp 0.',
            'default_target_visit_interval_days.required' => 'Ideal dikunjungi tiap (hari) wajib diisi.',
            'default_target_visit_interval_days.min' => 'Ideal dikunjungi tiap (hari) minimal 1 hari.',
            'default_target_visit_interval_days.max' => 'Ideal dikunjungi tiap (hari) maksimal 365 hari.',
            'default_warning_visit_interval_days.required' => 'Peringatan belum dikunjungi (hari) wajib diisi.',
            'default_warning_visit_interval_days.min' => 'Peringatan belum dikunjungi (hari) minimal 1 hari.',
            'default_warning_visit_interval_days.max' => 'Peringatan belum dikunjungi (hari) maksimal 365 hari.',
            'default_warning_visit_interval_days.gte' => 'Peringatan belum dikunjungi (hari) harus lebih besar atau sama dengan Ideal dikunjungi tiap (hari).',
            'default_fast_mover_threshold_days.required' => 'Batas kios laris (hari) wajib diisi.',
            'default_fast_mover_threshold_days.min' => 'Batas kios laris (hari) minimal 1 hari.',
            'default_fast_mover_threshold_days.max' => 'Batas kios laris (hari) maksimal 365 hari.',
            'kios_baru_days.required' => 'Masa "kios baru" (hari) wajib diisi.',
            'kios_baru_days.min' => 'Masa "kios baru" (hari) minimal 1 hari.',
            'kios_baru_days.max' => 'Masa "kios baru" (hari) maksimal 365 hari.',
            'default_qty_mika.required' => 'Default jatah titipan kedai baru (mika) wajib diisi.',
            'default_qty_mika.min' => 'Default jatah titipan kedai baru (mika) minimal 1 mika.',
            'default_qty_mika.max' => 'Default jatah titipan kedai baru (mika) maksimal 100 mika.',
        ]);

        // Nama usaha kosong → simpan null, bukan string kosong, supaya
        // User::getBusinessNameValue() tetap jatuh ke fallback "Cemilan Qontas".
        $businessName = trim((string) ($data['business_name'] ?? ''));
        $data['business_name'] = $businessName !== '' ? $businessName : null;

        // Tidak menerima owner_id dari request: hanya baris owner yang login yang diubah.
        auth()->user()->update($data);

        return redirect()->route('owner.settings')
            ->with('status', 'Pengaturan berhasil disimpan.');
    }
}

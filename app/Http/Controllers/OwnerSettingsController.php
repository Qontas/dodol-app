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
        $data = $request->validate([
            'hpp_per_mika' => 'required|numeric|min:1',
            'harga_mika' => 'required|numeric|min:0',
            'komisi_per_mika' => 'required|numeric|min:0',
            'komisi_kios_baru_per_mika' => 'required|numeric|min:0',
        ], [
            'hpp_per_mika.required' => 'HPP per mika wajib diisi.',
            'hpp_per_mika.min' => 'HPP per mika minimal Rp 1.',
            'harga_mika.required' => 'Harga mika wajib diisi.',
            'harga_mika.min' => 'Harga mika minimal Rp 0.',
            'komisi_per_mika.required' => 'Komisi reguler per mika wajib diisi.',
            'komisi_per_mika.min' => 'Komisi reguler per mika minimal Rp 0.',
            'komisi_kios_baru_per_mika.required' => 'Komisi kios baru per mika wajib diisi.',
            'komisi_kios_baru_per_mika.min' => 'Komisi kios baru per mika minimal Rp 0.',
        ]);

        auth()->user()->update([
            'hpp_per_mika' => $data['hpp_per_mika'],
            'harga_mika' => $data['harga_mika'],
            'komisi_per_mika' => $data['komisi_per_mika'],
            'komisi_kios_baru_per_mika' => $data['komisi_kios_baru_per_mika'],
        ]);

        return redirect()->route('owner.settings')
            ->with('status', 'Pengaturan berhasil disimpan.');
    }
}

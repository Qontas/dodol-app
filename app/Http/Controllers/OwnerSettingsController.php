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
        ], [
            'hpp_per_mika.required' => 'HPP per mika wajib diisi.',
            'hpp_per_mika.min' => 'HPP per mika minimal Rp 1.',
        ]);

        auth()->user()->update(['hpp_per_mika' => $data['hpp_per_mika']]);

        return redirect()->route('owner.settings')
            ->with('status', 'HPP berhasil disimpan.');
    }
}

# PROMPT_FIX_002.md

## Masalah

Halaman profile operator (route `/profile`) masih pakai layout Breeze default
(<x-app-layout>), jadi tampilannya beda total dari halaman operator lain
(tidak ada bottom-nav, header beda, styling tidak selaras).

## Tujuan

Buat halaman profile KHUSUS operator yang selaras dengan layouts/operator.blade.php.
JANGAN sentuh route /profile yang dipakai owner & super admin — mereka tetap
pakai profile.blade.php yang lama.

## Yang dikerjakan

### 1. Buat view baru: resources/views/operator/profile.blade.php

Pakai layout operator. Struktur:

<x-operator-layout> {{-- atau cara include layouts/operator yang dipakai project ini --}}
<div class="max-w-md mx-auto">
<div class="mb-6">
<h1 class="text-2xl font-bold text-slate-900">Profil Saya</h1>
<p class="text-slate-500 text-sm">Kelola informasi akun kamu</p>
</div>

        <div class="space-y-5">
            {{-- Info Profil --}}
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <livewire:profile.update-profile-information-form />
            </div>

            {{-- Ganti Password --}}
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <livewire:profile.update-password-form />
            </div>
        </div>
    </div>

</x-operator-layout>

CATATAN:

- Cek dulu bagaimana halaman operator lain (mis. create-kiosk) memanggil
  layouts/operator.blade.php. Ikuti pola yang SAMA persis (apakah pakai
  <x-operator-layout>, atau full-page Livewire component, atau @extends).
- JANGAN masukkan delete-user-form (operator tidak boleh hapus akun sendiri).

### 2. Buat route baru di routes/web.php

Di dalam grup middleware role:operator (yang sudah ada), tambahkan:

Route::view('/operator/profile', 'operator.profile')->name('operator.profile');

Pastikan ditempatkan di grup operator yang benar (prefix & middleware sesuai
grup operator existing).

### 3. Update bottom-nav di layouts/operator.blade.php

Cari array $items, pada baris 'Profil', ubah route-nya:
DARI: 'route' => 'profile'
KE: 'route' => 'operator.profile'

Dan ubah kondisi active:
DARI: 'active' => request()->routeIs('profile')
KE: 'active' => request()->routeIs('operator.profile')

### 4. Styling form Livewire (jika perlu)

Komponen update-profile-information-form & update-password-form mungkin masih
pakai styling Breeze default (tombol gelap, input default). Kalau setelah
dipasang tampilannya masih tidak selaras dengan tema amber operator, sesuaikan:

- Tombol "Save" → bg-amber-600 hover:bg-amber-700
- Input focus → focus:border-amber-500 focus:ring-amber-500

TAPI HATI-HATI: kedua komponen ini dipakai juga oleh /profile (owner & super admin).
Kalau ubah styling di file komponennya, owner/super admin ikut berubah.
Jadi: HANYA ubah styling kalau bisa di-scope ke operator saja. Kalau tidak bisa
tanpa mempengaruhi role lain, BIARKAN styling default dulu dan lapor ke saya.

## Setelah selesai

1. php artisan test
2. Pastikan tetap 155 PASS
3. Test manual: login operator → klik Profil di bottom-nav → harus tampil
   dengan layout operator (ada bottom-nav, header Cemilan Qontas, tema amber)
4. Login owner → buka /profile → harus TETAP seperti sebelumnya (tidak berubah)
5. git add -A
6. git commit -m "fix: halaman profil operator selaras dengan layout operator"

## Lapor ke saya

- Apakah styling form Livewire bisa di-scope ke operator tanpa mempengaruhi owner?
- Hasil test

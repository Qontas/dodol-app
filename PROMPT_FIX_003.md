# PROMPT_FIX_003.md

## Masalah

Form di /operator/profile (update-profile-information-form & update-password-form)
masih pakai styling Breeze default (tombol gelap bg-gray-800). Perlu diubah
khusus untuk operator tanpa mempengaruhi owner/super admin yang pakai /profile.

## Solusi

Duplikat kedua komponen Livewire menjadi versi khusus operator dengan tema amber.

## Yang dikerjakan

### 1. Duplikat komponen profile information

DARI: app/Livewire/Profile/UpdateProfileInformationForm.php
KE: app/Livewire/Operator/UpdateProfileForm.php

Isi sama persis, hanya ubah:

- namespace → App\Livewire\Operator
- class name → UpdateProfileForm

Duplikat view-nya:
DARI: resources/views/livewire/profile/update-profile-information-form.blade.php
KE: resources/views/livewire/operator/update-profile-form.blade.php

Ubah styling di view baru:

- Tombol Save: ganti class jadi bg-amber-600 hover:bg-amber-700 text-white
  font-bold py-3 px-6 rounded-xl transition
- Input fields: tambah focus:border-amber-500 focus:ring-amber-500
- Label: text-sm font-bold text-slate-900

### 2. Duplikat komponen update password

DARI: app/Livewire/Profile/UpdatePasswordForm.php
KE: app/Livewire/Operator/UpdatePasswordForm.php

Isi sama persis, hanya ubah:

- namespace → App\Livewire\Operator
- class name → UpdatePasswordForm

Duplikat view-nya:
DARI: resources/views/livewire/profile/update-password-form.blade.php
KE: resources/views/livewire/operator/update-password-form.blade.php

Ubah styling di view baru sama seperti nomor 1 (amber theme).

### 3. Update operator profile view

File: resources/views/livewire/operator/profile.blade.php

Ganti komponen yang dipanggil:
DARI:
<livewire:profile.update-profile-information-form />
<livewire:profile.update-password-form />

KE:
<livewire:operator.update-profile-form />
<livewire:operator.update-password-form />

## Setelah selesai

1. php artisan test
2. Pastikan tetap 155 PASS
3. Verifikasi: buka /operator/profile → form harus bertema amber
4. Verifikasi: buka /profile (login owner) → HARUS tetap seperti semula (tidak berubah)
5. git add -A
6. git commit -m "fix: form profil operator bertema amber, selaras layout operator"
7. Laporkan hasil

## Cleanup

Setelah commit, hapus semua file brief/prompt sementara di root project:

- PROMPT_FIX_001.md
- PROMPT_FIX_002.md
- PROMPT_FIX_003.md
- PROMPT_CUTOFF_KIOS.md
- PRD.md
- CLAUDE.md (cek dulu isinya, kalau isinya cuma instruksi sementara hapus,
  kalau berisi dokumentasi penting JANGAN hapus — lapor ke saya)

Setelah hapus, jalankan:
git add -A
git commit -m "chore: bersihkan file brief sementara dari root project"

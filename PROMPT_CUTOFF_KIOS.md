# Brief: Fitur Cut Off / Stop Titipan Kios

## KONTEKS

150 PASS. Tambah fitur cut off kios tanpa break existing.

## REQUIREMENT

### Tujuan

Operator atau owner bisa tandai kios sebagai "Stop Titipan" dengan alasan.
Kios yang di-stop tidak muncul di daftar kunjungan trip.
Data historis tetap ada. Kios bisa diaktifkan kembali (reaktivasi).

### Schema

Migration: tambah 2 kolom ke tabel kiosks:

```php
$table->timestamp('stopped_at')->nullable()->after('is_active');
$table->string('stop_reason')->nullable()->after('stopped_at');
$table->string('stopped_by')->nullable()->after('stop_reason'); // 'operator' atau 'owner'
```

is_active sudah ada — gunakan is_active=false untuk menandai kios stop.
stopped_at = waktu di-stop, stop_reason = alasan, stopped_by = siapa yang stop.

### Alasan Stop (pilihan)

- 'pemilik_minta_stop' → "Pemilik minta berhenti sementara"
- 'tutup_permanen' → "Kedai tutup permanen"
- 'kurang_laku' → "Penjualan kurang jalan"
- 'pindah_lokasi' → "Pindah lokasi"
- 'lainnya' → "Alasan lain"

### Operator — Modal Visit (active-trip.blade.php)

Di modal visit, tambah tombol "Stop Titipan" di section Opsi Khusus (sudah ada
accordion ⚙️ Opsi Khusus — taruh di sini agar tidak ganggu alur utama).

Saat operator klik "Stop Titipan":

- Muncul pilihan alasan (radio button, sama seperti alasan check_only)
- Konfirmasi singkat: "Kios ini akan dihentikan titipannya. Yakin?"
- Simpan: is_active=false, stopped_at=now(), stop_reason, stopped_by='operator'
- Kalau ada pending delivery (titipan aktif): tampilkan warning
  "Kios ini masih punya titipan aktif. Selesaikan dulu atau catat sebagai loss."
  Operator harus settle dulu atau konfirmasi loss sebelum stop.
- Setelah stop: modal tertutup, kios hilang dari daftar (atau tandai abu-abu
  dengan badge "Stop" tapi tidak bisa dikunjungi lagi)

### Owner — Panel (/owner-panel/kiosks atau /owner/dashboard)

Di KioskResource Filament:

- Tambah kolom status di tabel: badge "Aktif" (hijau) / "Stop" (merah)
- Tambah filter: Status = Aktif / Stop
- Tambah action per row: "Aktifkan Kembali" (set is_active=true, hapus
  stopped_at/stop_reason/stopped_by) + konfirmasi
- Tambah action per row: "Stop Titipan" (untuk owner yang mau stop dari panel)
  dengan pilihan alasan
- Di halaman detail kios (view/edit): tampilkan riwayat stop (stopped_at,
  stop_reason, stopped_by)

### Tracking & Laporan

Tambah section "Kios Berhenti" di owner dashboard:

- Tabel kios yang is_active=false milik owner
- Kolom: nama kios, alasan stop, tanggal stop, dihentikan oleh (operator/owner)
- Tombol "Aktifkan Kembali" per row
- Kalau kosong: tidak tampil (sama seperti section prediksi habis)

### Business Rules

- Kios is_active=false TIDAK muncul di loadKiosks() operator (sudah ada filter
  is_active=true — JANGAN ubah ini, cukup pastikan filter sudah ada)
- Kios stop TETAP bisa dilihat di owner panel (untuk tracking)
- Reaktivasi: set is_active=true, stopped_at=null, stop_reason=null,
  stopped_by=null
- Settlement pending yang ada saat stop: TIDAK otomatis dihapus — owner harus
  handle manual (bisa ditagih nanti kalau kios aktif lagi)

## TEKNIS

### Livewire ActiveTrip.php — tambah properties:

```php
public bool $showStopConfirm = false;
public string $stopReason = '';
```

### Method stopKios() di ActiveTrip:

```php
public function stopKios(): void
{
    if (empty($this->stopReason)) {
        $this->addError('stopReason', 'Pilih alasan dulu');
        return;
    }

    $this->selectedKiosk->update([
        'is_active' => false,
        'stopped_at' => now(),
        'stop_reason' => $this->stopReason,
        'stopped_by' => 'operator',
    ]);

    // Catat sebagai kiosk_visit check_only dengan alasan stop
    KioskVisit::create([
        'trip_id' => $this->trip->id,
        'kiosk_id' => $this->selectedKiosk->id,
        'visited_at' => now(),
        'visit_action' => 'check_only',
        'alasan_check' => 'stop_titipan',
        'extension_granted' => false,
    ]);

    $this->closeVisitModal();
    $this->loadKiosks();
    session()->flash('visit_saved', 'Kios '.$this->selectedKiosk->name.' dihentikan.');
}
```

### View active-trip.blade.php — tambah di Opsi Khusus:

```html
{{-- Stop Titipan --}}
<div class="border-t border-slate-100 pt-3 mt-3">
    @if(!$showStopConfirm)
    <button
        type="button"
        wire:click="$set('showStopConfirm', true)"
        class="w-full text-left p-3 rounded-xl border border-red-200 bg-red-50
                   text-sm font-medium text-red-700 active:bg-red-100"
    >
        🚫 Stop Titipan Kios Ini
    </button>
    @else
    <div class="space-y-3">
        <p class="text-sm font-bold text-red-700">Alasan Stop:</p>
        @foreach([ 'pemilik_minta_stop' => '🙏 Pemilik minta berhenti
        sementara', 'tutup_permanen' => '🔒 Kedai tutup permanen', 'kurang_laku'
        => '📉 Penjualan kurang jalan', 'pindah_lokasi' => '📍 Pindah lokasi',
        'lainnya' => '📝 Alasan lain', ] as $val => $label)
        <label
            class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer
                    {{ $stopReason === $val ? 'border-red-400 bg-red-50' : 'border-slate-200' }}"
        >
            <input
                type="radio"
                wire:model.live="stopReason"
                value="{{ $val }}"
                class="sr-only"
            />
            <span class="text-sm">{{ $label }}</span>
        </label>
        @endforeach @error('stopReason')
        <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror @if($selectedKiosk->pendingDelivery)
        <div
            class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs
                            text-amber-800"
        >
            ⚠️ Kios ini masih punya titipan aktif. Selesaikan tagihan dulu
            sebelum stop.
        </div>
        @endif
        <div class="flex gap-2">
            <button
                type="button"
                wire:click="$set('showStopConfirm', false)"
                class="flex-1 py-2 rounded-xl border border-slate-200 text-sm
                           text-slate-600 active:bg-slate-50"
            >
                Batal
            </button>
            <button
                type="button"
                wire:click="stopKios"
                wire:loading.attr="disabled"
                class="flex-1 py-2 rounded-xl bg-red-600 text-white text-sm
                           font-bold active:bg-red-700 disabled:opacity-60"
            >
                Konfirmasi Stop
            </button>
        </div>
    </div>
    @endif
</div>
```

### Reset showStopConfirm di closeVisitModal() / openVisitModal():

Tambah: $this->showStopConfirm = false; $this->stopReason = '';

### KioskResource Filament — tambah:

1. Kolom status di tabel (badge Aktif/Stop)
2. Filter status
3. Action "Stop Titipan" (owner) dengan form alasan
4. Action "Aktifkan Kembali"

### Owner Dashboard — tambah section kios berhenti:

Di OwnerDashboardController: ambil kios is_active=false milik owner (max 20)
Di dashboard.blade.php: tabel kios berhenti (nama, alasan, tanggal, oleh siapa)

- tombol Aktifkan Kembali (POST ke route baru)

## STEP EKSEKUSI

1. Migration: tambah stopped_at, stop_reason, stopped_by ke kiosks
2. php artisan migrate --force
3. Update Kiosk model: fillable + casts
4. Update ActiveTrip.php: properties + stopKios() method
5. Update active-trip.blade.php: section Stop di Opsi Khusus
6. Update KioskResource: kolom + filter + actions
7. Update OwnerDashboardController + dashboard.blade.php: section kios berhenti
8. Test: tambah test stopKios() — kios jadi is_active=false + kiosk_visit tercatat
9. php artisan test --compact (150 baseline)
10. Commit: feat(kiosk): fitur stop titipan + reaktivasi + tracking owner
11. Push origin main

## STOP POINTS

- loadKiosks() tidak filter is_active → cek dulu, jangan ubah kalau sudah ada
- Observer/constraint block update is_active → lapor
- Test turun dari 150 → rollback

Output ringkas. Mulai sekarang.

--- END OF BRIEF ---

# Brief: End Trip Flow — ActiveTrip Livewire

## KONTEKS

Day 6 dodol-app. saveVisit() sudah wired (commit 744bfba, 45 PASS).
Tugas: implement End Trip flow di ActiveTrip.php + view.
Commit target: feat(operator): end trip flow — summary + reason

## BUSINESS RULES (LOCKED)

- Operator WAJIB pilih alasan end trip (tidak bisa end tanpa pilih)
- 5 alasan: stock_habis, target_done, sakit, urgent_personal, other
- Sebelum confirm end, tampilkan SUMMARY trip:
    - Total kios dikunjungi (count kiosk_visits WHERE trip_id)
    - Total mika di-drop (sum deliveries.qty_delivered WHERE trip_id)
    - Total uang diterima (sum settlements.amount_paid via kiosk_visits JOIN)
- Setelah confirm: set trips.ended_at = now(), trips.ended_reason = alasan

## SCHEMA (verified)

trips: id, user_id, trip_date, trip_number_of_day, starting_cluster_id, started_at, ended_at, notes, created_at, updated_at

Cek apakah kolom ended_reason sudah ada:
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('trips'));"

Kalau belum ada → buat migration: add_ended_reason_to_trips_table
Kalau sudah ada → skip migration

## ENDED_REASON ENUM

5 nilai: stock_habis, target_done, sakit, urgent_personal, other
Simpan sebagai string (VARCHAR) — cukup untuk Phase 1.

## FLOW UI

### Step 1: Tombol "Akhiri Trip" di ActiveTrip page

- Posisi: di bawah list kios, sebelum tombol existing
- Style: merah/danger (bg-red-500 atau bg-red-600)
- wire:click="openEndTripModal"

### Step 2: Modal End Trip

- Tampilkan summary (computed dari DB):
    - "Kios Dikunjungi: X"
    - "Total Drop: X mika"
    - "Total Uang Diterima: Rp X"
- Radio button atau select: pilih alasan (5 opsi, label bahasa Indonesia)
    - stock_habis → "Stok Habis"
    - target_done → "Target Tercapai"
    - sakit → "Sakit"
    - urgent_personal → "Keperluan Mendadak"
    - other → "Lainnya"
- Tombol "Konfirmasi Akhiri Trip" (disabled kalau belum pilih alasan)
- Tombol "Batal" → tutup modal

### Step 3: Setelah confirm

- DB::transaction:
    - $trip->update(['ended_at' => now(), 'ended_reason' => $this->endReason])
- Redirect ke /operator/dashboard
- Session flash: "Trip berhasil diakhiri."

## PROPERTIES BARU DI ACTIVETRIIP.PHP

public bool $isEndTripModalOpen = false;
public string $endReason = '';
public array $tripSummary = [
'kios_visited' => 0,
'total_mika_drop' => 0,
'total_uang_diterima' => 0,
];

## METHODS BARU

### openEndTripModal()

- Hitung tripSummary dari DB (3 query):
    1. kios_visited = KioskVisit::where('trip_id', $trip->id)->count()
    2. total_mika_drop = Delivery::where('trip_id', $trip->id)->sum('qty_delivered')
    3. total_uang_diterima = Settlement::whereHas('delivery', fn($q) => $q->where('trip_id', $trip->id))->sum('amount_paid')
- Set isEndTripModalOpen = true
- Reset endReason = ''

### confirmEndTrip()

- Validate: endReason tidak kosong + masuk 5 nilai valid
- Kalau invalid: addError('endReason', 'Pilih alasan mengakhiri trip.')
- DB::transaction:
    - $this->trip->update(['ended_at' => now(), 'ended_reason' => $endReason])
- session()->flash('trip_ended', 'Trip berhasil diakhiri.')
- redirect()->route('operator.dashboard')

### closeEndTripModal()

- isEndTripModalOpen = false
- endReason = ''

## FINISHTRIP() EXISTING

Cek apakah finishTrip() sudah ada di ActiveTrip.php.
Kalau ada → REPLACE dengan openEndTripModal() + confirmEndTrip() pattern.
Kalau tidak ada → tambah methods baru saja.

## VALIDASI

- endReason wajib diisi (tidak bisa confirm kalau kosong)
- endReason harus salah satu dari 5 nilai valid
- Trip harus masih aktif (ended_at NULL) sebelum bisa end

## VIEW UPDATE (active-trip.blade.php)

1. Tambah tombol "Akhiri Trip" di bawah list kios
2. Tambah modal end trip dengan summary + radio alasan + 2 tombol
3. Tombol "Konfirmasi Akhiri Trip": disabled kalau endReason kosong
4. Loading state: wire:loading.attr="disabled" wire:target="confirmEndTrip"
5. Error display: @error('endReason')

## STEP EKSEKUSI

1. Cek schema trips (ended_reason ada atau belum)
2. Kalau belum: buat + run migration
3. Update ActiveTrip.php (properties + 3 methods baru)
4. Update active-trip.blade.php (tombol + modal)
5. php artisan test --compact (target 45+ PASS)
6. Commit:
   git add app/Livewire/Operator/ActiveTrip.php resources/views/livewire/operator/active-trip.blade.php database/migrations/
   git commit -m "feat(operator): end trip flow — summary + reason + redirect dashboard"

## STOP POINTS — TANYA ADVISOR KALAU

1. Schema trips punya ended_reason dengan tipe berbeda (enum vs varchar)
2. finishTrip() existing punya logic yang tidak di-brief ini
3. Test turun dari 45 PASS
4. trips.ended_reason migration conflict dengan existing migration

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

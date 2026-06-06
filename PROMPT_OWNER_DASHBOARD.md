# Brief: Owner Dashboard Widget

## KONTEKS

Day 7 dodol-app. 45 PASS, 113 assertions.
Tambah widget statistik di /owner/dashboard (OwnerDashboardController + view).
3 widget: Omset Hari Ini, Kios Overdue, Total Outstanding.

## BUSINESS RULES (LOCKED)

- Omset hari ini = sum(settlements.amount_paid) WHERE visit_date = today()
- Kios overdue = kios aktif yang last_visit > target_visit_interval_days hari lalu
  (last_visit = max(kiosk_visits.visited_at) per kios)
  Default overdue threshold = 10 hari kalau target_visit_interval_days null/0
- Total outstanding = sum(amount_due - amount_paid) dari settlements WHERE status = 'pending'
- Semua angka rupiah format: Rp X.XXX.XXX (number_format, titik sebagai pemisah ribuan)

## EXISTING STATE

File yang perlu diupdate:

- app/Http/Controllers/OwnerDashboardController.php (tambah query 3 widget)
- resources/views/owner/dashboard.blade.php (tambah widget cards)

Cek kedua file dulu sebelum modifikasi.

## STEP EKSEKUSI

1. Baca OwnerDashboardController.php + dashboard.blade.php (existing content)

2. Update OwnerDashboardController.php — tambah 3 query:

Query 1 — Omset hari ini:
$omsetHariIni = \App\Models\Settlement::whereDate('visit_date', today())
->sum('amount_paid');

Query 2 — Kios overdue:
$kioskIds = \App\Models\Kiosk::where('is_active', true)->pluck('id');
$overdueKiosks = \App\Models\Kiosk::where('is_active', true)
->get()
->filter(function($kiosk) {
        $lastVisit = \App\Models\KioskVisit::where('kiosk_id', $kiosk->id)
            ->max('visited_at');
        if (!$lastVisit) return true; // belum pernah dikunjungi = overdue
$threshold = $kiosk->target_visit_interval_days ?: 10;
        return now()->diffInDays($lastVisit) > $threshold;
    });
$overdueCount = $overdueKiosks->count();

Query 3 — Total outstanding:
$totalOutstanding = \App\Models\Settlement::where('status', 'pending')
->selectRaw('SUM(amount_due - amount_paid) as total')
->value('total') ?? 0;

Pass ke view: compact('omsetHariIni', 'overdueCount', 'totalOutstanding')

3. Update resources/views/owner/dashboard.blade.php

Tambah 3 widget cards SEBELUM tombol "Buka Admin Panel".
Style: light theme, consistent dengan Filament admin palette (amber accent).

Layout 3 cards (grid):

- Card 1: "Omset Hari Ini" — icon uang, nilai Rp X.XXX.XXX, warna hijau
- Card 2: "Kios Overdue" — icon warning, nilai X kios, warna merah kalau >0 amber kalau 0
- Card 3: "Outstanding" — icon clock, nilai Rp X.XXX.XXX, warna merah kalau >0 hijau kalau 0

4. php artisan test --compact (target 45+ PASS)

5. Commit:
   git add app/Http/Controllers/OwnerDashboardController.php resources/views/owner/
   git commit -m "feat(owner): dashboard widgets — omset, overdue, outstanding"

## STOP POINTS — TANYA ADVISOR KALAU

1. OwnerDashboardController tidak ada atau struktur berbeda
2. View owner/dashboard.blade.php tidak ada
3. Test turun dari 45 PASS
4. Schema settlements tidak punya kolom visit_date atau amount_paid

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

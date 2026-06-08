# Brief: Laporan Export PDF + Excel

## KONTEKS

77 PASS. Tambah fitur export laporan:

1. Trip report per trip → PDF + Excel
2. Laporan bulanan (rekap semua trip 1 bulan) → PDF + Excel
   Semua di-scope per owner (multi-tenant).

## PACKAGES YANG DIPAKAI

PDF: barryvdh/laravel-dompdf (paling stable untuk Laravel)
Excel: maatwebsite/excel (sudah umum di ekosistem Laravel)

Cek apakah kedua package sudah ada di composer.json.
Kalau belum: composer require barryvdh/laravel-dompdf maatwebsite/excel

## LAPORAN 1: TRIP REPORT PER TRIP

### Data yang ditampilkan:

- Header: nama bisnis owner, tanggal trip, nama operator, cluster
- Summary: mika dibawa, mika di-drop, mika sisa
- Finansial: omset, HPP, untung kotor, komisi reguler, komisi kios baru, total komisi, untung bersih
- Detail kunjungan: list kios yang dikunjungi (nama, visit_action, waktu)
- Footer: digenerate oleh sistem dodol-app

### Route:

GET /owner/trips/{trip}/export/pdf → download PDF
GET /owner/trips/{trip}/export/excel → download Excel

### Controller: app/Http/Controllers/Owner/TripExportController.php

```php
public function pdf(Trip $trip): Response
{
    // Authorize: trip harus milik owner yang login
    abort_if($trip->owner_id !== auth()->id() && !auth()->user()->isSuperAdmin(), 403);

    $data = $this->buildTripData($trip);
    $pdf = Pdf::loadView('exports.trip-report-pdf', $data);
    return $pdf->download("trip-report-{$trip->trip_date}.pdf");
}

public function excel(Trip $trip): Response
{
    abort_if($trip->owner_id !== auth()->id() && !auth()->user()->isSuperAdmin(), 403);
    return Excel::download(new TripReportExport($trip), "trip-report-{$trip->trip_date}.xlsx");
}
```

### View PDF: resources/views/exports/trip-report-pdf.blade.php

Simple HTML + inline CSS (dompdf tidak support Tailwind).
Layout: header → summary table → finansial table → detail kunjungan table → footer.

### Excel Export: app/Exports/TripReportExport.php

Implement FromArray + WithHeadings + WithTitle.
Sheet 1: Summary finansial
Sheet 2: Detail kunjungan per kios

## LAPORAN 2: LAPORAN BULANAN

### Data yang ditampilkan:

- Header: nama bisnis owner, bulan/tahun
- Per trip: tanggal, operator, kios dikunjungi, mika drop, omset, komisi, untung bersih
- Total bulan: sum semua kolom finansial
- Chart omset harian (di PDF: simple bar text, di Excel: data untuk chart)

### Analisis Kios (Laporan Bulanan tambahan):

- Total kios aktif di-settle bulan ini:
  Settlement::whereHas('delivery.kiosk.cluster', fn($q) => $q->where('owner_id', $ownerId))
  ->whereMonth('visit_date', $month)->distinct('delivery.kiosk_id')->count()

- Frekuensi per kios: group kiosk_visits by kiosk_id, count visits per bulan
  Tampil di tabel: Nama Kios | Cluster | Jumlah Kunjungan | Jumlah Settle

- Kios baru bulan ini:
  Kiosk::whereMonth('first_titip_date', $month)->whereYear('first_titip_date', $year)
  ->whereHas('cluster', fn($q) => $q->where('owner_id', $ownerId))->count()

### Route:

GET /owner/reports/monthly?month=2026-06 → halaman filter
GET /owner/reports/monthly/export/pdf?month=2026-06 → download PDF
GET /owner/reports/monthly/export/excel?month=2026-06 → download Excel

### Controller: app/Http/Controllers/Owner/MonthlyReportController.php

```php
public function index(Request $request): View
{
    $month = $request->get('month', now()->format('Y-m'));
    $ownerId = auth()->id();

    $trips = Trip::where('owner_id', $ownerId)
        ->whereNotNull('ended_at')
        ->whereYear('trip_date', substr($month, 0, 4))
        ->whereMonth('trip_date', substr($month, 5, 2))
        ->with(['operator', 'startingCluster', 'visits', 'deliveries.settlements'])
        ->orderBy('trip_date')
        ->get();

    return view('owner.reports.monthly', compact('trips', 'month'));
}
```

### View halaman monthly: resources/views/owner/reports/monthly.blade.php

- Filter bulan (input month)
- Tabel rekap per trip
- Total row di bawah
- Tombol "Export PDF" + "Export Excel"

## TOMBOL EXPORT DI OWNER DASHBOARD

Di halaman owner dashboard, setelah tabel completed trips:
Tambah link "Lihat Laporan Bulanan" → /owner/reports/monthly

Di setiap row completed trip:
Tambah tombol kecil "PDF" + "XLS" → download langsung

## STEP EKSEKUSI

1. Cek composer.json — apakah dompdf + maatwebsite/excel sudah ada
2. Kalau belum: composer require barryvdh/laravel-dompdf maatwebsite/excel
3. Buat TripExportController + routes
4. Buat view PDF trip report (simple HTML/CSS)
5. Buat TripReportExport (Excel)
6. Buat MonthlyReportController + routes
7. Buat view halaman monthly report
8. Buat MonthlyReportExport (Excel)
9. Buat view PDF monthly report
10. Update owner dashboard (link + tombol export di completed trips)
11. Update routes/web.php (semua route baru di owner group)
12. php artisan test --compact (target 77+ PASS)
13. Commit:
    git add .
    git commit -m "feat(owner): laporan export PDF + Excel — trip report + laporan bulanan"
    git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. dompdf atau maatwebsite/excel conflict dengan dependency existing
2. Trip model tidak punya data cukup untuk laporan (relasi missing)
3. Test turun dari 77 PASS
4. View PDF render error (CSS issue dompdf)

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

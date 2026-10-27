<?php

namespace App\Http\Controllers\Owner;

use App\Exports\TripReportExport;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Support\ExportFilename;
use App\Support\VisitRowBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class TripExportController extends Controller
{
    private function authorizeTrip(Trip $trip): void
    {
        abort_if(
            $trip->owner_id !== auth()->id() && ! auth()->user()->isSuperAdmin(),
            403
        );
    }

    /**
     * Bangun semua data laporan trip (dipakai PDF & sebagai referensi Excel).
     */
    public static function buildTripData(Trip $trip): array
    {
        $trip->loadMissing(array_merge(
            ['owner', 'operator', 'startingCluster', 'stockAdditions.addedBy'],
            VisitRowBuilder::eagerLoad(),
        ));

        $mikaDibawa = (int) ($trip->qty_carried_total ?? 0);
        // Hanya SATU trip per panggilan (bukan loop banyak trip) — aman pakai accessor
        // relation-method Trip::getBiji*Attribute() di sini, tak picu N+1.
        $mikaDrop = (int) $trip->deliveries()->sum('qty_delivered');

        return [
            'trip' => $trip,
            'businessName' => $trip->owner?->getBusinessNameValue() ?? 'Cemilan Qontas',
            'operatorName' => $trip->operator?->name ?? '—',
            'clusterName' => $trip->startingCluster?->name ?? 'Semua Kios',
            // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix D) —
            // dulu $trip->owner?->getKomisiPerMikaValue() (tarif owner LIVE): label "Rp X
            // × mika drop" bisa tak cocok dengan 'total_komisi' di bawah (yang SUDAH benar,
            // dari $trip->komisi_rian / snapshot) begitu owner ganti tarif setelah trip ini
            // selesai, atau kalau operatornya bertarif khusus. Baris ini IDENTIK (bukan
            // panggilan, Trip::getKomisiPerMika() private) Trip::getKomisiPerMika() —
            // X × mika_komisi SEKARANG selalu = total_komisi yang ditampilkan.
            'komisiPerMika' => (int) ($trip->komisi_per_mika_snapshot ?? $trip->owner?->komisi_per_mika ?? 1000),
            'summary' => [
                'mika_dibawa' => $mikaDibawa,
                'mika_dibawa_display' => $trip->mika_dibawa_display,
                'mika_tambahan' => (int) $trip->qty_added_total,
                'mika_drop' => $mikaDrop,
                // ✅ DIPERBAIKI BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — investigasi
                // BRIEF 26 Okt 2026 item 4 (trip "KualiStories" 26 Okt: dibawa 90, dititip
                // 107, sisa -17) TERNYATA keliru: trip itu TIDAK PUNYA bs_redistribution
                // sama sekali, jadi -17 BUKAN disebabkan Dodol Sisa. Akar masalahnya memang
                // tidak ada guard & tidak ada jalur tambah stok — keduanya sekarang ada.
                // mika_sisa SEKARANG pakai Trip::getStokDiMotor() (SATU sumber kebenaran,
                // = stok dibawa efektif − total drop REAL, exclude bs_redistribution yang
                // berasal dari KIOS bukan dari stok motor). Trip historis yang sudah
                // negatif TETAP tampil merah apa adanya (data lama, tidak diretro-fix).
                'mika_sisa' => $trip->getStokDiMotor(),
                'biji_drop' => (int) $trip->biji_diantar,
                // Riwayat "➕ Tambah Stok di Motor" (waktu, jumlah, oleh siapa) — kosong
                // kalau trip belum pernah pakai fitur ini.
                'stock_additions' => $trip->stockAdditions->map(fn ($a) => [
                    'created_at' => $a->created_at,
                    'qty' => (int) $a->qty,
                    'added_by' => $a->addedBy?->name ?? '—',
                ])->all(),
            ],
            'finansial' => [
                'omset' => (int) $trip->omset_val,
                'hpp' => (int) $trip->hpp_estimasi,
                'untung_kotor' => (int) $trip->untung_kotor,
                'mika_komisi' => (int) $trip->mika_komisi,
                'biji_komisi' => (int) $trip->biji_komisi,
                'total_komisi' => (int) $trip->komisi_rian,
                'untung_bersih' => (int) $trip->untung_bersih_owner,
                'biji_terjual' => (int) $trip->biji_terjual,
            ],
            // BRIEF 26 Okt 2026 item 2 — dulu 3 kolom (Kios/Aksi/Waktu) saja, "tanpa
            // angka" sesuai keluhan owner. Sekarang delegasi ke VisitRowBuilder (SATU
            // builder, dipakai juga TripHistoryController::show() & sheet "Data
            // Kunjungan" Laporan Periode) — baris lengkap termasuk Mika Titip, Biji
            // Terjual, BS, Tagihan, Uang Diterima, Piutang, Catatan/Janji Bayar.
            'visits' => VisitRowBuilder::forTrip($trip)->all(),
        ];
    }

    /** @deprecated Pindah ke VisitRowBuilder::actionLabel() — alias dipertahankan agar konsumen lama tak pecah. */
    public static function visitActionLabel(?string $action): string
    {
        return VisitRowBuilder::actionLabel($action);
    }

    public function pdf(Trip $trip): Response
    {
        $this->authorizeTrip($trip);

        $pdf = Pdf::loadView('exports.trip-report-pdf', self::buildTripData($trip));
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download(ExportFilename::tripPdf($trip->trip_date->format('Y-m-d'), $trip->operator?->name));
    }

    public function excel(Trip $trip): BinaryFileResponse
    {
        $this->authorizeTrip($trip);

        return Excel::download(
            new TripReportExport($trip),
            ExportFilename::tripExcel($trip->trip_date->format('Y-m-d'), $trip->operator?->name)
        );
    }
}

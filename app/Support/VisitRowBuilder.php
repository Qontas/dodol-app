<?php

namespace App\Support;

use App\Models\KioskVisit;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Baris LENGKAP satu kunjungan kios — SATU SUMBER dipakai PDF trip, sheet Excel
 * "Detail Kunjungan" trip, halaman web detail trip, DAN sheet "Data Kunjungan" Laporan
 * Periode (BRIEF 26 Okt 2026, item 2 & 3). Sebelum ini ada DUA builder yang beda &
 * berpotensi divergen: TripExportController::buildTripData() (3 kolom, PDF/Excel) vs
 * TripHistoryController::show() (6 kolom, web) — sekarang keduanya delegasi ke sini.
 *
 * MURNI pembacaan data mentah yang SUDAH ADA (KioskVisit/Delivery/Settlement) — tidak
 * ada rumus uang baru. Piutang per-baris pakai formula yang SAMA dengan widget
 * "Total Outstanding" OwnerDashboardController (status='pending' → amount_due -
 * amount_paid), cuma diterapkan per-baris bukan agregat.
 */
class VisitRowBuilder
{
    /** Prefix literal yang dipakai ActiveTrip saat menulis janji bayar ke notes. */
    public const JANJI_BAYAR_PREFIX = 'Janji bayar: ';

    /**
     * Eager-load minimal supaya forTrip()/fromVisit() tak N+1. Pemanggil tetap bebas
     * menambah eager-load lain (mis. 'operator') di atas ini.
     *
     * @return array<int|string, mixed>
     */
    public static function eagerLoad(): array
    {
        return [
            'visits' => fn ($q) => $q->active()->orderBy('visited_at'),
            'visits.kiosk:id,name,cluster_id',
            'visits.kiosk.cluster:id,name',
            'visits.newDelivery:id,qty_delivered',
            'visits.settledDelivery:id',
            'visits.settledDelivery.settlement',
        ];
    }

    /**
     * Semua baris kunjungan AKTIF trip ini, siap dipakai konsumen manapun. $trip HARUS
     * sudah eager-load relasi di eagerLoad() (atau setara) sebelum dipanggil.
     */
    public static function forTrip(Trip $trip): Collection
    {
        return $trip->visits->map(fn (KioskVisit $v) => self::fromVisit($v));
    }

    /**
     * @return array{
     *     visited_at: \Illuminate\Support\Carbon|null,
     *     time: string,
     *     kiosk: string,
     *     area: string,
     *     action: string,
     *     mika_titip: int,
     *     biji_terjual: int,
     *     bs_biji: int,
     *     tagihan: int,
     *     uang_diterima: int,
     *     piutang: int,
     *     janji_bayar: string,
     *     catatan: string,
     *     catatan_gabungan: string,
     * }
     */
    public static function fromVisit(KioskVisit $v): array
    {
        $settlement = $v->settledDelivery?->settlement;

        $amountDue = (int) ($settlement?->amount_due ?? 0);
        $amountPaid = (int) ($settlement?->amount_paid ?? 0);
        // Piutang baris ini: IDENTIK formula "Total Outstanding"
        // OwnerDashboardController (status pending → sisa due-paid). Settlement
        // is_writeoff selalu status='paid' (SettlementObserver memaksa amount_paid=
        // amount_due), jadi otomatis 0 di sini — tak perlu filter is_writeoff terpisah.
        $piutang = ($settlement?->status === 'pending') ? max(0, $amountDue - $amountPaid) : 0;

        [$janjiBayar, $catatan] = self::splitJanjiDanCatatan($v->notes, $settlement?->notes);

        $catatanGabungan = trim(
            ($janjiBayar !== '' ? 'Janji: '.$janjiBayar : '')
            .($catatan !== '' ? ($janjiBayar !== '' ? ' | ' : '').$catatan : '')
        );

        return [
            'visited_at' => $v->visited_at,
            'time' => $v->visited_at?->format('d M Y H:i') ?? '—',
            'kiosk' => $v->kiosk?->name ?? '—',
            'area' => $v->kiosk?->cluster?->name ?? '—',
            'action' => self::actionLabel($v->visit_action),
            'mika_titip' => (int) ($v->newDelivery?->qty_delivered ?? 0),
            'biji_terjual' => (int) ($settlement?->qty_sold ?? 0),
            'bs_biji' => (int) ($settlement?->qty_returned_expired ?? 0),
            'tagihan' => $amountDue,
            'uang_diterima' => $amountPaid,
            'piutang' => $piutang,
            'janji_bayar' => $janjiBayar,
            'catatan' => $catatan,
            'catatan_gabungan' => $catatanGabungan,
        ];
    }

    /**
     * Pisahkan teks "janji bayar" (prefix literal 'Janji bayar: ', lihat
     * ActiveTrip::confirmCheckOnly()/confirmSettle()) dari catatan biasa. Bisa muncul di
     * KioskVisit.notes (cek saja, belum bisa bayar) ATAU Settlement.notes (settle
     * kurang/stop tanpa tagih) — keduanya dicek, tak ada kolom DB khusus.
     *
     * @return array{0: string, 1: string} [janjiBayar, catatan]
     */
    private static function splitJanjiDanCatatan(?string $visitNotes, ?string $settlementNotes): array
    {
        $janji = '';
        $catatanParts = [];

        foreach ([$visitNotes, $settlementNotes] as $notes) {
            $notes = trim((string) $notes);
            if ($notes === '') {
                continue;
            }

            if (str_starts_with($notes, self::JANJI_BAYAR_PREFIX)) {
                $janji = $janji !== '' ? $janji : substr($notes, strlen(self::JANJI_BAYAR_PREFIX));
            } else {
                $catatanParts[] = $notes;
            }
        }

        return [$janji, implode(' ', $catatanParts)];
    }

    public static function actionLabel(?string $action): string
    {
        return match ($action) {
            'drop_and_settle' => 'Tagih + Titip Baru',
            'drop_only' => 'Titip Baru',
            'settle_only' => 'Tagih Saja',
            'check_only' => 'Cek Saja',
            'cash_sale' => 'Jual Cash',
            default => $action ?? '—',
        };
    }
}

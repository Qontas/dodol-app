<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\AppFeeCalculator;
use Filament\Widgets\Widget;

/**
 * TUGAS 1 (BRIEF 23 Okt 2026, lanjutan) — "Rincian Fee Aplikasi Harian":
 * rincian PER TANGGAL dari widget "Fee Aplikasi" (AppFeeWidget, per OWNER),
 * super admin saja (fee aplikasi = urusan owner partner ↔ pemilik aplikasi,
 * bukan urusan owner/operator — sama alasan AppFeeWidget).
 *
 * Widget KUSTOM (extends Widget polos, bukan TableWidget) — datanya berkunci
 * KOMPOSIT (tanggal, owner_id) + butuh baris TOTAL manual, tak pas dipaksakan
 * ke Filament\Tables\Table yang query()-nya mengharap satu model Eloquent per
 * baris (lihat juga OwnerOmsetChart yang pakai view kustom untuk alasan
 * serupa). wire:poll.30s ditulis manual di view (sama seperti AppFeeWidget).
 *
 * Mika Kena Fee & Fee: AppFeeCalculator::summaryByOwnerAndDate() — ATURAN
 * SAMA PERSIS widget "Fee Aplikasi" (lihat docblock AppFeeCalculator), TIDAK
 * ditulis ulang di sini. SATU query batch (lihat method itu) + SATU query
 * daftar owner (untuk dropdown filter + default "bertarif > 0") = query
 * KONSTAN berapa pun banyak owner/tanggal — lihat AppFeeDailyWidgetTest.
 *
 * BRIEF 26 Okt 2026: kolom "Mika Diantar" (terpisah dari "Mika Kena Fee")
 * DIHAPUS — sejak aturan "titipan pertama tidak dihitung" dihapus, kedua
 * metrik itu SELALU sama, jadi tak ada lagi gunanya ditampilkan dua kolom
 * identik (lihat docblock AppFeeCalculator::summaryByOwnerAndDate()).
 */
class AppFeeDailyWidget extends Widget
{
    protected static string $view = 'filament.widgets.app-fee-daily';

    protected static ?string $heading = 'Rincian Fee Aplikasi Harian';

    protected int | string | array $columnSpan = 'full';

    /** Format 'Y-m' (native <input type="month">), default bulan berjalan. */
    public string $month;

    /**
     * 0 = default ("Semua Owner (bertarif > 0)") — 0 BUKAN id owner valid
     * (auto-increment users.id mulai dari 1), dipakai sebagai sentinel biar
     * tak perlu properti nullable/empty-string yang rawan salah cocok tipe
     * waktu di-bind dari <select> (nilai HTML select SELALU string).
     */
    public int $ownerId = 0;

    // Sama seperti OwnerOmsetChart/OwnerDashboardController — APP_LOCALE proyek
    // ini 'en' & tak ada Carbon::setLocale(), jadi format('d M') bawaan jadi
    // Inggris ("5 Oct"). Array manual dipakai ulang jalur yang sama di beberapa
    // kelas (konvensi proyek, lihat kelas-kelas itu), bukan ditulis sekali di
    // sini karena tak ada util tanggal Indonesia terpusat di proyek ini.
    private const BULAN_SINGKAT = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    // Widget dasar (beda dari ChartWidget/TableWidget) TIDAK punya trait
    // HasHeading, jadi $this->getHeading() tak otomatis ada — method tipis
    // ini saja yang dibutuhkan view-nya (lihat app-fee-daily.blade.php).
    public function getHeading(): ?string
    {
        return static::$heading;
    }

    public function mount(): void
    {
        $this->month = today()->format('Y-m');
    }

    protected function getViewData(): array
    {
        [$year, $monthNum] = array_pad(explode('-', $this->month), 2, null);
        $year = (int) $year;
        $monthNum = (int) $monthNum;

        $allRows = AppFeeCalculator::summaryByOwnerAndDate($year, $monthNum);

        // SATU query — dipakai untuk isi dropdown filter Owner SEKALIGUS
        // menentukan default "bertarif > 0" (TUGAS 1a). Owner bertarif 0/null
        // TETAP ada di dropdown (bisa dipilih manual kalau perlu diperiksa),
        // cuma tak ikut tampil di tampilan default "Semua Owner".
        $owners = User::query()
            ->where('role', 'owner')
            ->orderBy('name')
            ->get(['id', 'name', 'app_fee_per_pack']);

        $defaultOwnerIds = $owners
            ->filter(fn (User $o): bool => $o->getAppFeePerPackValue() > 0)
            ->pluck('id')
            ->all();

        $rows = collect($allRows)
            ->filter(function (array $row) use ($defaultOwnerIds): bool {
                if ($this->ownerId !== 0) {
                    return $row['owner_id'] === $this->ownerId;
                }

                return in_array($row['owner_id'], $defaultOwnerIds, true);
            })
            ->map(function (array $row): array {
                $date = \Illuminate\Support\Carbon::parse($row['date']);
                $row['date_label'] = $date->day.' '.self::BULAN_SINGKAT[$date->month];

                return $row;
            })
            ->values();

        $total = [
            'mika_kena_fee' => (int) $rows->sum('mika_kena_fee'),
            'fee' => (float) $rows->sum('fee'),
        ];

        return [
            'rows' => $rows,
            'total' => $total,
            'owners' => $owners,
        ];
    }
}

<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\KioskResource;
use App\Models\Kiosk;
use App\Support\OpeningBalance;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateKiosk extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = KioskResource::class;

    /**
     * JENIS KEDAI saat create (radio "Jenis Kedai", form-only/dehydrated false):
     *  - cash_only  → set is_cash_only=true (tak ada titipan/jatah).
     *  - konsinyasi → kalau JATAH TITIPAN (default_qty_mika) >=1, otomatis buat titipan
     *    berjalan SEJUMLAH JATAH itu (1 delivery konsinyasi PENDING) via OpeningBalance —
     *    TANPA field mika terpisah — sehingga kios langsung bisa "Tagih + Titip Ulang" di
     *    kunjungan pertama; tagihannya dikoreksi otomatis oleh input sisa/BS operator.
     * Kegagalan titipan tak menggagalkan pembuatan kios.
     */
    protected function afterCreate(): void
    {
        $data = $this->data;

        $kiosk = $this->record;
        if (! $kiosk instanceof Kiosk) {
            return;
        }

        // URUTAN RUTE: kios baru SELALU jadi urutan TERAKHIR di area-nya (bukan NULL yang
        // jatuh ke bawah tanpa nomor & mengacaukan urutan rute). Sejak field "Urutan Rute"
        // dibuang dari form (15 Juli 2026), tak ada lagi jalur "owner isi angka eksplisit
        // saat create" — owner mengatur urutan BELAKANGAN di halaman daftar (drag per-Area
        // atau ketik angka di kolom "Urutan" → Kiosk::reorderWithinCluster). Sama persis
        // dengan perilaku operator create-kiosk.
        Kiosk::appendToClusterOrder($kiosk);

        $jenis = $data['jenis_kedai'] ?? 'konsinyasi';

        if ($jenis === 'cash_only') {
            // Cash-only: tak ada titipan/jatah.
            $kiosk->update(['is_cash_only' => true, 'default_qty_mika' => null]);

            return;
        }

        // Konsinyasi: titipan awal = JATAH (default_qty_mika). Satu angka, satu sumber.
        $jatah = (int) ($kiosk->default_qty_mika ?? 0);
        if ($jatah < 1) {
            return;
        }

        $delivery = OpeningBalance::create($kiosk, $jatah);

        if ($delivery === null) {
            Notification::make()
                ->title('Titipan berjalan belum tercatat')
                ->body('Kios tersimpan, tapi titipan awal tidak dibuat (kios sudah punya titipan, atau belum ada varian produk aktif). Cek Master Data → Produk.')
                ->warning()
                ->send();
        }
    }
}

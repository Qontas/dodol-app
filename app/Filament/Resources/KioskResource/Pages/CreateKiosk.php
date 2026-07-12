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
     *  - konsinyasi → kalau "titipan berjalan sekarang" >=1, buat titipan berjalan
     *    (1 delivery konsinyasi PENDING) via OpeningBalance — TANPA kolom flag —
     *    sehingga kios langsung bisa "Tagih + Titip Ulang" di kunjungan pertama.
     * Kegagalan titipan tak menggagalkan pembuatan kios.
     */
    protected function afterCreate(): void
    {
        $data = $this->data;

        $kiosk = $this->record;
        if (! $kiosk instanceof Kiosk) {
            return;
        }

        $jenis = $data['jenis_kedai'] ?? 'konsinyasi';

        if ($jenis === 'cash_only') {
            // Cash-only: tak ada titipan/jatah.
            $kiosk->update(['is_cash_only' => true, 'default_qty_mika' => null]);

            return;
        }

        // Konsinyasi: bikin titipan berjalan bila diisi.
        if ((int) ($data['opening_balance_mika'] ?? 0) < 1) {
            return;
        }

        $delivery = OpeningBalance::create($kiosk, (int) $data['opening_balance_mika']);

        if ($delivery === null) {
            Notification::make()
                ->title('Titipan berjalan belum tercatat')
                ->body('Kios tersimpan, tapi saldo awal tidak dibuat (kios sudah punya titipan, atau belum ada varian produk aktif). Cek Master Data → Produk.')
                ->warning()
                ->send();
        }
    }
}

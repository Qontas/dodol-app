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
     * KIOS LAMA (migrasi): kalau toggle "kios lama" dinyalakan, buat titipan berjalan
     * (1 delivery konsinyasi PENDING) via OpeningBalance — TANPA kolom flag. Field
     * kios_lama & opening_balance_mika form-only (dehydrated false), jadi dibaca dari
     * $this->data, bukan dari record. Kegagalan tak menggagalkan pembuatan kios.
     */
    protected function afterCreate(): void
    {
        $data = $this->data;

        if (empty($data['kios_lama']) || (int) ($data['opening_balance_mika'] ?? 0) < 1) {
            return;
        }

        $kiosk = $this->record;
        if (! $kiosk instanceof Kiosk) {
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

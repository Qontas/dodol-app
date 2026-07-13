<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Resources\KioskResource;
use App\Models\Kiosk;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKiosk extends EditRecord
{
    protected static string $resource = KioskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * PINDAH CLUSTER: kalau kios dipindah ke area lain, sort_order lama (relatif ke
     * cluster lama) tak relevan → jadikan URUTAN TERAKHIR di cluster baru, biar tak
     * menabrak nomor kios lain di sana. Kalau cluster tak berubah, sort_order dibiarkan.
     */
    protected function afterSave(): void
    {
        $kiosk = $this->record;

        if ($kiosk instanceof Kiosk && $kiosk->wasChanged('cluster_id')) {
            Kiosk::appendToClusterOrder($kiosk);
        }
    }
}

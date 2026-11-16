<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Imports\KioskImporter;
use App\Filament\Resources\KioskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKiosks extends ListRecords
{
    protected static string $resource = KioskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('kioskInsights')
                ->label('Unduh Laporan Kedai')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => route('kiosk-insights.index')),
            Actions\CreateAction::make(),
            Actions\ImportAction::make()
                ->importer(KioskImporter::class),
        ];
    }
}

<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Resources\KioskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKiosks extends ListRecords
{
    protected static string $resource = KioskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\ImportAction::make()
                ->importer(\App\Filament\Imports\KioskImporter::class),
        ];
    }
}

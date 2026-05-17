<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Resources\KioskResource;
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
}

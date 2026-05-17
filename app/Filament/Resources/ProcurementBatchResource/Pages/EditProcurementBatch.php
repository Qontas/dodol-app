<?php

namespace App\Filament\Resources\ProcurementBatchResource\Pages;

use App\Filament\Resources\ProcurementBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProcurementBatch extends EditRecord
{
    protected static string $resource = ProcurementBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

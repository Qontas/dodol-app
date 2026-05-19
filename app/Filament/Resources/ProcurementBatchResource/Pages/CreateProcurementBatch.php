<?php

namespace App\Filament\Resources\ProcurementBatchResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\ProcurementBatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProcurementBatch extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProcurementBatchResource::class;
}

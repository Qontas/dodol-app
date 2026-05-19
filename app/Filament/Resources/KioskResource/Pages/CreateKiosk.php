<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\KioskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKiosk extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = KioskResource::class;
}

<?php

namespace App\Filament\Resources\PriceTierResource\Pages;

use App\Filament\Resources\PriceTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePriceTiers extends ManageRecords
{
    protected static string $resource = PriceTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->mutateFormDataUsing(function (array $data): array {
                $data['owner_id'] = auth()->id();

                return $data;
            }),
        ];
    }
}

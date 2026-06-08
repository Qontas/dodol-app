<?php

namespace App\Filament\Resources\OperatorResource\Pages;

use App\Filament\Resources\OperatorResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageOperators extends ManageRecords
{
    protected static string $resource = OperatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['role'] = 'operator';
                    $data['owner_id'] = auth()->id();
                    return $data;
                }),
        ];
    }
}

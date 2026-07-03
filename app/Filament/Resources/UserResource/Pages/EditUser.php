<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Defense-in-depth (parity dengan CreateUser): owner tidak boleh mengubah
     * operatornya menjadi role lain atau memindahkannya ke owner lain, walau
     * payload di-tamper. Super admin tidak dipaksa.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()?->isOwner()) {
            $data['role'] = 'operator';
            $data['owner_id'] = auth()->id();
        }

        return $data;
    }
}

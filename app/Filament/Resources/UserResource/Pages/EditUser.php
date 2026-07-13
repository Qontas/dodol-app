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

        // Guard lockout (server-side, bukan cuma UI disabled): user tak boleh
        // mengunci dirinya sendiri keluar lewat form Edit — paksa is_active tetap
        // aktif & role tak berubah walau payload di-tamper.
        if (isset($this->record) && (int) $this->record->id === (int) auth()->id()) {
            $data['is_active'] = true;
            $data['role'] = $this->record->role;
        }

        return $data;
    }
}

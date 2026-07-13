<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Owner hanya boleh membuat operator miliknya sendiri.
        if (auth()->user()?->isOwner()) {
            $data['role'] = 'operator';
            $data['owner_id'] = auth()->id();
        }

        // Super Admin tunggal (server-side, bukan cuma UI): tolak pembuatan
        // super_admin kedua walau payload di-tamper.
        if (($data['role'] ?? null) === 'super_admin' && User::anotherSuperAdminExists()) {
            Notification::make()
                ->danger()
                ->title('Ditolak')
                ->body('Sistem hanya boleh punya satu Super Admin.')
                ->persistent()
                ->send();

            $this->halt();
        }

        return $data;
    }
}

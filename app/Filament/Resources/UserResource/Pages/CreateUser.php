<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\UserResource;
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

        return $data;
    }
}

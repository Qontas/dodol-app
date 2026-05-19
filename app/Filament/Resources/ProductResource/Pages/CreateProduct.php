<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProductResource::class;
}

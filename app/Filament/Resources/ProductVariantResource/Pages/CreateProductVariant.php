<?php

namespace App\Filament\Resources\ProductVariantResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\ProductVariantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductVariant extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProductVariantResource::class;
}

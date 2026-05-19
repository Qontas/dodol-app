<?php

namespace App\Filament\Resources\ClusterResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\ClusterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCluster extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ClusterResource::class;
}

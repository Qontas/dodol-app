<?php

namespace App\Filament\Imports;

use App\Models\Kiosk;
use App\Models\Cluster;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class KioskImporter extends Importer
{
    protected static ?string $model = Kiosk::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('Nama')
                ->guess(['nama', 'name'])
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),

            ImportColumn::make('owner_name')
                ->label('Pemilik')
                ->guess(['pemilik', 'owner_name'])
                ->rules(['nullable', 'string', 'max:255']),

            ImportColumn::make('cluster')
                ->label('Cluster')
                ->guess(['cluster'])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(function (Kiosk $record, ?string $state) {
                    $clusterName = trim($state ?? '');
                    $cluster = null;
                    if ($clusterName !== '') {
                        $cluster = Cluster::where('name', $clusterName)->first();
                    }
                    if (!$cluster) {
                        $cluster = Cluster::where('name', 'UNCATEGORIZED')->first();
                    }
                    $record->cluster_id = $cluster ? $cluster->id : null;
                }),

            ImportColumn::make('default_qty_mika')
                ->label('Qty Mika')
                ->guess(['qty_mika', 'default_qty_mika'])
                ->integer()
                ->rules(['nullable', 'integer']),

            ImportColumn::make('phone')
                ->label('Telepon')
                ->guess(['telepon', 'phone'])
                ->rules(['nullable', 'string', 'max:20']),

            ImportColumn::make('location_description')
                ->label('Alamat')
                ->guess(['alamat', 'location_description'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('latitude')
                ->label('Lat')
                ->guess(['lat', 'latitude'])
                ->numeric()
                ->rules(['nullable', 'numeric']),

            ImportColumn::make('longitude')
                ->label('Lng')
                ->guess(['lng', 'longitude'])
                ->numeric()
                ->rules(['nullable', 'numeric']),
        ];
    }

    public function resolveRecord(): ?Kiosk
    {
        return new Kiosk();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your kiosk import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}

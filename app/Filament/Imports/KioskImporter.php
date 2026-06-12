<?php

namespace App\Filament\Imports;

use App\Models\Cluster;
use App\Models\Kiosk;
use Carbon\Carbon;
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
                ->guess(['nama', 'nama_kios', 'name', 'd'])
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),

            ImportColumn::make('owner_name')
                ->label('Pemilik')
                ->guess(['pemilik', 'owner_name'])
                ->rules(['nullable', 'string', 'max:255']),

            // Virtual: di-resolve di resolveRecord() (butuh owner_id dari import).
            ImportColumn::make('cluster')
                ->label('Area')
                ->guess(['cluster', 'nomor_cluster', 'b'])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(function (): void {
                    // no-op — lihat resolveRecord()
                }),

            ImportColumn::make('default_qty_mika')
                ->label('Qty Mika')
                ->guess(['qty_mika', 'default_qty_mika', 'e'])
                ->integer()
                ->rules(['nullable', 'integer']),

            ImportColumn::make('phone')
                ->label('Telepon')
                ->guess(['telepon', 'phone'])
                ->rules(['nullable', 'string', 'max:20']),

            ImportColumn::make('location_description')
                ->label('Alamat')
                ->guess(['alamat', 'deskripsi_lokasi', 'location_description', 'f'])
                ->rules(['nullable', 'string']),

            // Kolom G abang: koordinat DMS / Google Maps link / teks. Di-parse di resolveRecord().
            ImportColumn::make('koordinat_atau_link')
                ->label('Koordinat / Link')
                ->guess(['koordinat_atau_link', 'koordinat', 'g'])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(function (): void {
                    // no-op — lihat resolveRecord()
                }),

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

            // Kolom I abang: DD/MM/YYYY. Di-parse di resolveRecord().
            ImportColumn::make('first_titip_date')
                ->label('Tanggal Pertama Titip')
                ->guess(['tanggal_pertama_titip', 'first_titip_date', 'i'])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(function (): void {
                    // no-op — lihat resolveRecord()
                }),
        ];
    }

    public function resolveRecord(): ?Kiosk
    {
        $ownerId = (int) $this->import->user_id;

        $kiosk = new Kiosk();
        $kiosk->is_active = true;

        // Cluster: owner-scoped, auto-create kalau belum ada.
        $kiosk->cluster_id = $this->resolveCluster((string) ($this->data['cluster'] ?? ''), $ownerId);

        // Koordinat / link (kolom G). lat/lng dari kolom langsung (kalau ada) menang.
        $coord = $this->parseKoordinat((string) ($this->data['koordinat_atau_link'] ?? ''));
        if ($coord['lat'] !== null) {
            $kiosk->latitude = $coord['lat'];
        }
        if ($coord['lng'] !== null) {
            $kiosk->longitude = $coord['lng'];
        }
        if ($coord['notes'] !== null) {
            $kiosk->notes = $coord['notes'];
        }

        // Tanggal pertama titip (kolom I). Kosong = null (BUKAN today()).
        $kiosk->first_titip_date = $this->parseFirstTitipDate($this->data['first_titip_date'] ?? null);

        return $kiosk;
    }

    /**
     * Parse kolom koordinat campur: DMS, Google Maps link, atau teks biasa.
     *
     * @return array{lat: float|null, lng: float|null, notes: string|null}
     */
    private function parseKoordinat(string $value): array
    {
        $value = trim($value);

        // Case 1: DMS format — "3° 36' 15.5196" N 98° 39' 54.072" E"
        if (($dms = \App\Support\KioskLocationParser::dmsToDecimal($value)) !== null) {
            return ['lat' => $dms['lat'], 'lng' => $dms['lng'], 'notes' => null];
        }

        // Case 2: Google Maps link — tidak bisa resolve tanpa API, simpan di notes.
        if (str_starts_with($value, 'http')) {
            return ['lat' => null, 'lng' => null, 'notes' => 'GPS: '.$value];
        }

        // Case 3 & 4: teks biasa / kosong → tidak ada koordinat.
        return ['lat' => null, 'lng' => null, 'notes' => null];
    }

    private function resolveCluster(string $value, int $ownerId): int
    {
        $value = trim($value);
        if ($value === '') {
            $value = 'Uncategorized';
        }

        $cluster = Cluster::where('owner_id', $ownerId)
            ->where(function ($q) use ($value) {
                $q->where('name', $value)
                    ->orWhere('name', 'LIKE', "%{$value}%");
            })
            ->first();

        if (! $cluster) {
            $name = is_numeric($value) ? "Cluster {$value}" : $value;
            $cluster = Cluster::create([
                'name' => $name,
                'owner_id' => $ownerId,
                'is_active' => true,
            ]);
        }

        return $cluster->id;
    }

    /**
     * Parse DD/MM/YYYY (format spreadsheet abang). Kosong/invalid → null.
     */
    private function parseFirstTitipDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
            return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
        }

        // Fallback: format ISO atau lain yang bisa di-parse Carbon.
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your kiosk import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use App\Support\KioskLocationParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import kios massal dari file "LIST TEMPAT TITIPAN" (format spreadsheet abang).
 *
 * Beda dari template KioskImporter: file ini punya header dekoratif (baris 1-2),
 * tidak punya baris header kolom, dan kolom lokasi (G) campuran DMS / link / teks.
 *
 * Indeks kolom 0-based (lihat hasil inspeksi file):
 *   A=0 no urut · B=1 grup(jarang) · D=3 nama · E=4 qty mika · F=5 alamat ·
 *   G=6 lokasi(DMS/link/teks) · H=7 screenshot · I=8 tanggal · J=9 sales.
 */
class ImportKiosk extends Command
{
    protected $signature = 'kios:import
        {file : Path file xlsx/csv}
        {--owner= : ID user owner pemilik kios (wajib untuk import asli)}
        {--dry-run : Parse & laporkan tanpa menyimpan}';

    protected $description = 'Import kios massal format "LIST TEMPAT TITIPAN" (xlsx/csv).';

    private const COL_NAME = 3;     // D
    private const COL_QTY = 4;      // E
    private const COL_ADDRESS = 5;  // F
    private const COL_LOCATION = 6; // G

    private const DEFAULT_QTY = 2;
    private const CHUNK = 100;
    private const IMPORT_CLUSTER = 'Tempat Titipan';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;

        $owner = null;
        if ($ownerId !== null) {
            $owner = User::find($ownerId);
            if (! $owner) {
                $this->error("Owner dengan id {$ownerId} tidak ditemukan.");

                return self::FAILURE;
            }
            if ($owner->role !== 'owner') {
                $this->warn("⚠ User {$ownerId} ({$owner->name}) role-nya '{$owner->role}', bukan 'owner'.");
            }
        }

        if (! $dryRun && $ownerId === null) {
            $this->error('Import asli butuh --owner=<id>. Gunakan --dry-run untuk pratinjau tanpa owner.');

            return self::FAILURE;
        }

        // --- Baca file (maatwebsite/excel; handle xlsx & csv) ---
        $this->info('Membaca '.basename($file).' ...');
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $file);
        $rows = $sheets[0] ?? [];

        // --- Parse ---
        $valid = [];                 // baris siap dibuat
        $skipped = [];               // ['row'=>n, 'reason'=>..]
        $emptyRows = 0;              // baris dekoratif / kosong (tanpa nama)
        $seen = [];                  // dedupe dalam-file (nama lowercase)
        $coord = ['dms' => 0, 'link' => 0, 'text' => 0, 'none' => 0];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 1; // 1-based untuk pelaporan

            $name = trim((string) ($row[self::COL_NAME] ?? ''));
            if ($name === '') {
                $emptyRows++; // baris dekoratif (DODOL / LIST TEMPAT TITIPAN) & kosong
                continue;
            }

            $rawQty = $row[self::COL_QTY] ?? null;
            $qty = (is_numeric($rawQty) && (int) $rawQty >= 1) ? (int) $rawQty : self::DEFAULT_QTY;

            $address = trim((string) ($row[self::COL_ADDRESS] ?? ''));
            $location = trim((string) ($row[self::COL_LOCATION] ?? ''));

            $lat = $lng = $notes = null;
            if ($location === '') {
                $coord['none']++;
            } elseif (($dms = KioskLocationParser::dmsToDecimal($location)) !== null) {
                [$lat, $lng] = [$dms['lat'], $dms['lng']];
                $coord['dms']++;
            } elseif (str_starts_with($location, 'http')) {
                $notes = 'GPS: '.$location; // link maps.app.goo.gl — tak bisa resolve tanpa API
                $coord['link']++;
            } else {
                // Teks nama tempat → gabung ke location_description, koordinat null.
                $address = $address !== '' ? "{$address} — {$location}" : $location;
                $coord['text']++;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $skipped[] = ['row' => $rowNum, 'reason' => "duplikat dalam file: \"{$name}\""];

                continue;
            }
            $seen[$key] = true;

            $valid[] = [
                'row' => $rowNum,
                'name' => $name,
                'default_qty_mika' => $qty,
                'location_description' => $address !== '' ? $address : null,
                'latitude' => $lat,
                'longitude' => $lng,
                'notes' => $notes,
            ];
        }

        // --- Duplikat vs DB (butuh owner) ---
        $dupDb = 0;
        if ($owner) {
            $existing = Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
                ->pluck('name')
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))
                ->flip();

            $valid = array_values(array_filter($valid, function ($k) use ($existing, &$skipped, &$dupDb) {
                if (isset($existing[mb_strtolower($k['name'])])) {
                    $skipped[] = ['row' => $k['row'], 'reason' => "sudah ada di DB: \"{$k['name']}\""];
                    $dupDb++;

                    return false;
                }

                return true;
            }));
        }

        $this->printSummary($rows, $emptyRows, $valid, $skipped, $coord, $owner, $dupDb, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: tidak ada data disimpan.');

            return self::SUCCESS;
        }

        // --- Import asli: cluster owner + insert per chunk dalam transaction ---
        $cluster = $this->resolveImportCluster($ownerId);
        $created = 0;

        foreach (array_chunk($valid, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $cluster, &$created) {
                foreach ($chunk as $k) {
                    Kiosk::create([
                        'name' => $k['name'],
                        'cluster_id' => $cluster->id,
                        'default_qty_mika' => $k['default_qty_mika'],
                        'location_description' => $k['location_description'],
                        'latitude' => $k['latitude'],
                        'longitude' => $k['longitude'],
                        'notes' => $k['notes'],
                        'is_active' => true,
                    ]);
                    $created++;
                }
            });
            $this->info("  ... {$created} dibuat");
        }

        $this->newLine();
        $this->info("SELESAI: {$created} dibuat, ".count($skipped).' dilewati (duplikat), masuk cluster "'.$cluster->name.'" (owner '.$owner->name.').');

        return self::SUCCESS;
    }

    private function resolveImportCluster(int $ownerId): Cluster
    {
        return Cluster::firstOrCreate(
            ['owner_id' => $ownerId, 'name' => self::IMPORT_CLUSTER],
            ['is_active' => true],
        );
    }

    private function printSummary(array $rows, int $emptyRows, array $valid, array $skipped, array $coord, ?User $owner, int $dupDb, bool $dryRun): void
    {
        $this->newLine();
        $this->line('===== RINGKASAN '.($dryRun ? 'DRY-RUN' : 'IMPORT').' =====');
        $this->table(['Metrik', 'Jumlah'], [
            ['Total baris di file', count($rows)],
            ['Baris dekoratif/kosong (dilewati)', $emptyRows],
            ['Baris data (punya nama)', count($rows) - $emptyRows],
            ['Valid (akan dibuat)', count($valid)],
            ['Dilewati — duplikat dalam file', count($skipped) - $dupDb],
            ['Dilewati — sudah ada di DB', $owner ? $dupDb : 'n/a (tanpa --owner)'],
        ]);

        $this->line('Jenis lokasi (kolom G):');
        $this->table(['Tipe', 'Jumlah'], [
            ['DMS → koordinat', $coord['dms']],
            ['Link maps → notes', $coord['link']],
            ['Teks → location_description', $coord['text']],
            ['Kosong', $coord['none']],
        ]);

        if ($skipped) {
            $this->newLine();
            $this->warn('Contoh baris dilewati (maks 15):');
            foreach (array_slice($skipped, 0, 15) as $s) {
                $this->line("  baris {$s['row']}: {$s['reason']}");
            }
            if (count($skipped) > 15) {
                $this->line('  ... +'.(count($skipped) - 15).' lagi');
            }
        }

        if (! $owner) {
            $this->newLine();
            $this->warn('Catatan: --owner tidak diisi → cek duplikat vs DB DILEWATI. Isi --owner untuk hasil lengkap.');
        }
    }
}

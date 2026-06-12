<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Seeding "saldo awal" titipan untuk kios hasil migrasi (lihat PROMPT_SALDO_AWAL).
 *
 * Kios import-an sudah eksis tapi belum punya delivery → operator melihatnya
 * sebagai "kios baru" & tak bisa menagih. Command ini membuat 1 delivery
 * konsinyasi pending per kios (fakta "ada X mika titipan belum dibayar") TANPA
 * settlement, dan menyetel first_titip_date dari spreadsheet supaya komisi kios
 * baru tidak terpicu. Idempoten: kios yang sudah punya delivery dilewati.
 */
class SeedKioskSaldoAwal extends Command
{
    protected $signature = 'kios:saldo-awal
        {file : Path file xlsx/csv sumber}
        {--owner= : ID user owner pemilik kios (wajib)}
        {--dry-run : Hitung & laporkan tanpa menyimpan}';

    protected $description = 'Seed saldo awal titipan (delivery pending) untuk kios hasil migrasi.';

    private const COL_NAME = 3;  // D
    private const COL_QTY = 4;   // E
    private const COL_DATE = 8;  // I (Excel serial)

    private const DEFAULT_QTY = 2;
    private const CHUNK = 100;

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $ownerId = $this->option('owner') !== null ? (int) $this->option('owner') : null;
        if ($ownerId === null) {
            $this->error('Wajib --owner=<id> (pencocokan kios di-scope per owner).');

            return self::FAILURE;
        }
        $owner = User::find($ownerId);
        if (! $owner) {
            $this->error("Owner id {$ownerId} tidak ditemukan.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Varian aktif tunggal — sama dengan yang dipakai alur operator (resolveActiveVariant).
        $variant = ProductVariant::where('is_active', true)->first();
        if (! $variant) {
            $this->error('Tidak ada varian produk aktif. Tidak bisa membuat delivery.');

            return self::FAILURE;
        }

        // Kios milik owner ini (scope via cluster.owner_id), index by nama lowercase.
        $kiosks = Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))->get();
        $byName = [];
        foreach ($kiosks as $k) {
            $byName[mb_strtolower(trim($k->name))] = $k;
        }

        // Kios yang SUDAH punya delivery → lewati (idempoten).
        $hasDelivery = Delivery::whereIn('kiosk_id', $kiosks->pluck('id'))
            ->distinct()
            ->pluck('kiosk_id')
            ->flip();

        // Baca file.
        $this->info('Membaca '.basename($file).' ...');
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $file);
        $rows = $sheets[0] ?? [];

        $toSeed = [];        // ['kiosk'=>Kiosk,'qty'=>int,'date'=>Carbon]
        $skipUnmatched = []; // ['row'=>n,'name'=>..]
        $skipHasDelivery = 0;
        $skipNoName = 0;
        $seededKioskIds = []; // dedupe baris dup yang menunjuk kios sama

        foreach ($rows as $i => $row) {
            $rowNum = $i + 1;
            $name = trim((string) ($row[self::COL_NAME] ?? ''));
            if ($name === '') {
                $skipNoName++;

                continue;
            }

            $kiosk = $byName[mb_strtolower($name)] ?? null;
            if (! $kiosk) {
                $skipUnmatched[] = ['row' => $rowNum, 'name' => $name];

                continue;
            }
            if (isset($seededKioskIds[$kiosk->id])) {
                continue; // sudah di-antrikan oleh baris lain
            }
            if (isset($hasDelivery[$kiosk->id])) {
                $skipHasDelivery++;
                $seededKioskIds[$kiosk->id] = true;

                continue;
            }

            $rawQty = $row[self::COL_QTY] ?? null;
            $qty = (is_numeric($rawQty) && (int) $rawQty >= 1)
                ? (int) $rawQty
                : (((int) $kiosk->default_qty_mika >= 1) ? (int) $kiosk->default_qty_mika : self::DEFAULT_QTY);

            $date = $this->parseExcelDate($row[self::COL_DATE] ?? null) ?? today()->subDays(30);

            $seededKioskIds[$kiosk->id] = true;
            $toSeed[] = ['kiosk' => $kiosk, 'qty' => $qty, 'date' => $date];
        }

        $totalMika = array_sum(array_column($toSeed, 'qty'));
        $earliest = null;
        foreach ($toSeed as $s) {
            if ($earliest === null || $s['date']->lt($earliest)) {
                $earliest = $s['date']->copy();
            }
        }

        $this->printSummary($rows, $skipNoName, $byName, $toSeed, $skipUnmatched, $skipHasDelivery, $totalMika, $earliest, $variant, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: tidak ada data disimpan.');

            return self::SUCCESS;
        }

        if (empty($toSeed)) {
            $this->newLine();
            $this->info('Tidak ada kios yang perlu di-seed (semua sudah punya delivery / tidak match).');

            return self::SUCCESS;
        }

        // Operator migrasi untuk owner ini (nonaktif: tidak untuk login operasional).
        $operator = User::firstOrCreate(
            ['email' => "operator.migrasi.owner{$ownerId}@cemilanqontas.id"],
            [
                'name' => "Operator Migrasi (owner {$owner->name})",
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
                'role' => 'operator',
                'owner_id' => $ownerId,
                'is_active' => false,
            ],
        );

        // Satu trip migrasi (langsung ended → tidak lewat flow end-trip → tidak ada komisi).
        $trip = Trip::create([
            'owner_id' => $ownerId,
            'operator_id' => $operator->id,
            'trip_date' => $earliest->toDateString(),
            'trip_number_of_day' => 1,
            'started_at' => $earliest,
            'ended_at' => $earliest,
            'ended_reason' => 'Migrasi saldo awal dari spreadsheet',
            'qty_carried_total' => min($totalMika, 32767), // smallint guard (display saja)
            'notes' => 'Trip migrasi saldo awal — bukan trip operasional. Delivery pending tanpa settlement.',
        ]);

        $created = 0;
        foreach (array_chunk($toSeed, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $trip, $variant, &$created) {
                foreach ($chunk as $s) {
                    /** @var Kiosk $kiosk */
                    $kiosk = $s['kiosk'];

                    // first_titip_date dari spreadsheet → cegah komisi kios baru.
                    $kiosk->first_titip_date = $s['date']->toDateString();
                    $kiosk->save();

                    $d = new Delivery();
                    $d->fill([
                        'kiosk_id' => $kiosk->id,
                        'trip_id' => $trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'consignment',
                        'qty_delivered' => $s['qty'],
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                        'notes' => 'Saldo awal migrasi (titipan lapangan sebelum sistem).',
                    ]);
                    // created_at = tanggal spreadsheet (set sebelum save → tidak ditimpa).
                    $d->created_at = $s['date'];
                    $d->updated_at = $s['date'];
                    $d->save();

                    $created++;
                }
            });
            $this->info("  ... {$created} delivery dibuat");
        }

        $this->newLine();
        $this->info("SELESAI: {$created} delivery saldo awal dibuat (trip #{$trip->id}, owner {$owner->name}). "
            .count($skipUnmatched).' baris tak match, '.$skipHasDelivery.' kios sudah punya delivery.');

        return self::SUCCESS;
    }

    private function parseExcelDate(mixed $raw): ?Carbon
    {
        $d = $this->rawToDate($raw);
        if ($d === null) {
            return null;
        }

        // Tolak tahun tak masuk akal (typo spreadsheet, mis. "0206", serial 6686280
        // → tahun 20206). null → pemanggil pakai fallback today-30.
        if ($d->year < 2000 || $d->year > (int) now()->year + 1) {
            return null;
        }

        return $d;
    }

    private function rawToDate(mixed $raw): ?Carbon
    {
        if (is_numeric($raw) && (float) $raw > 0) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $s = trim((string) ($raw ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            try {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function printSummary(array $rows, int $skipNoName, array $byName, array $toSeed, array $skipUnmatched, int $skipHasDelivery, int $totalMika, ?Carbon $earliest, ProductVariant $variant, bool $dryRun): void
    {
        $this->newLine();
        $this->line('===== RINGKASAN '.($dryRun ? 'DRY-RUN' : 'SEEDING').' SALDO AWAL =====');
        $this->table(['Metrik', 'Nilai'], [
            ['Total baris di file', count($rows)],
            ['Baris dekoratif/kosong (dilewati)', $skipNoName],
            ['Kios owner ini di DB', count($byName)],
            ['Akan di-seed (delivery baru)', count($toSeed)],
            ['Dilewati — kios sudah punya delivery', $skipHasDelivery],
            ['Dilewati — nama tak match kios', count($skipUnmatched)],
            ['Total mika saldo awal', $totalMika],
            ['Estimasi nilai tunggakan (Rp)', number_format($totalMika * 15 * 800, 0, ',', '.')],
            ['Tanggal trip migrasi (paling awal)', $earliest?->toDateString() ?? '—'],
            ['Varian dipakai', $variant->name.' @ Rp '.number_format((float) $variant->sale_price_per_pack, 0, ',', '.')],
        ]);

        if ($skipUnmatched) {
            $this->warn('Contoh nama tak match (maks 15):');
            foreach (array_slice($skipUnmatched, 0, 15) as $u) {
                $this->line("  baris {$u['row']}: \"{$u['name']}\"");
            }
            if (count($skipUnmatched) > 15) {
                $this->line('  ... +'.(count($skipUnmatched) - 15).' lagi');
            }
        }
    }
}

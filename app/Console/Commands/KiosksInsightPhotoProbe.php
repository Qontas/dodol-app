<?php

namespace App\Console\Commands;

use App\Models\Kiosk;
use App\Models\User;
use App\Support\KioskInsightThumbnail;
use Illuminate\Console\Command;

/**
 * READ-ONLY: ukur seberapa cepat foto asli kedai diambil dari disk media (R2 di produksi) dengan klien &
 * timeout yang SAMA seperti export laporan kedai. Tidak menulis apa pun (tanpa cache thumbnail, tanpa DB write).
 * Untuk dijalankan owner/pengelola di console Railway sebelum mencoba export lagi.
 */
class KiosksInsightPhotoProbe extends Command
{
    protected $signature = 'kiosks:insight-photo-probe {--owner= : ID owner wajib} {--limit=20 : Jumlah foto (1-100)} {--parallel : Ambil bersamaan seperti satu gelombang export}';

    protected $description = 'Ukur waktu ambil foto asli kedai dari disk media (read-only)';

    public function handle(): int
    {
        $owner = (string) ($this->option('owner') ?? '');
        if (! ctype_digit($owner) || (int) $owner < 1 || ! User::query()->whereKey((int) $owner)->where('role', 'owner')->exists()) {
            $this->error('--owner wajib berupa ID owner yang valid.');

            return self::FAILURE;
        }
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('--limit harus 1 sampai 100.');

            return self::FAILURE;
        }
        $kiosks = Kiosk::query()->excludeWalkInSentinel()
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', (int) $owner))
            ->whereNotNull('photo_path')->where('photo_path', '!=', '')
            ->orderBy('id')->limit((int) $limit)->get(['id', 'photo_path']);
        if ($kiosks->isEmpty()) {
            $this->warn('Owner ini belum punya kedai berfoto.');

            return self::SUCCESS;
        }
        $paths = [];
        foreach ($kiosks as $kiosk) {
            if (preg_match('~^kiosks/[a-zA-Z0-9_./-]+$~D', $kiosk->photo_path) && ! str_contains($kiosk->photo_path, '..')) {
                $paths[$kiosk->id] = $kiosk->photo_path;
            }
        }
        $settings = KioskInsightThumbnail::settings();
        $this->line(sprintf('disk=%s mode=%s timeout_foto=%ss connect=%ss anggaran_tick=%ss', config('app.media_disk', 'public'), $this->option('parallel') ? 'paralel' : 'berurutan', $settings['source_timeout'], $settings['connect_timeout'], KioskInsightThumbnail::budgetSeconds()));

        if ($paths === []) {
            $this->warn('Tidak ada path foto valid dalam sampel ini.');

            return self::SUCCESS;
        }
        try {
            // Never create more simultaneous requests than one export wave.
            $result = ['rows' => [], 'wall_ms' => 0];
            foreach (array_chunk(array_values($paths), $this->option('parallel') ? $settings['concurrency'] : 1) as $chunk) {
                $part = KioskInsightThumbnail::probe($chunk, (bool) $this->option('parallel'));
                array_push($result['rows'], ...$part['rows']);
                $result['wall_ms'] += $part['wall_ms'];
            }
        } catch (\Throwable $e) {
            // SDK exceptions may contain signed requests/credentials: do not print the exception.
            $this->error('Probe gagal menyiapkan klien media. Periksa konfigurasi disk media.');

            return self::FAILURE;
        }
        $ids = array_keys($paths);
        $times = [];
        $timeouts = $failures = 0;
        foreach ($result['rows'] as $n => $row) {
            $times[] = $row['ms'];
            $timeouts += $row['status'] === 'timeout' ? 1 : 0;
            $failures += $row['status'] === 'gagal' ? 1 : 0;
            $this->line(sprintf('kedai #%d  %6d ms  %s  %s', $ids[$n], $row['ms'], $row['bytes'] === null ? '       -' : str_pad(number_format($row['bytes'] / 1024, 1).' KB', 9, ' ', STR_PAD_LEFT), $row['status']));
        }
        sort($times, SORT_NUMERIC);
        $count = count($times);
        $at = fn (float $p) => $times[max(0, (int) ceil($p / 100 * $count) - 1)];
        $this->newLine();
        $this->line(sprintf('foto=%d sukses=%d timeout=%d gagal_lain=%d', $count, $count - $timeouts - $failures, $timeouts, $failures));
        $this->line(sprintf('median=%d ms  p95=%d ms  maks=%d ms  total_dinding=%d ms', $at(50), $at(95), $at(100), $result['wall_ms']));

        return self::SUCCESS;
    }
}

<?php

namespace App\Support;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils as Streams;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/**
 * Gambar kecil untuk export laporan kedai. Tidak pernah mengikuti URL dari kedai.
 *
 * Hotfix 16 Nov 2026: fatal 30s dilaporkan di produksi; penyebab tepatnya belum direproduksi.
 * Timeout per operasi saja tidak membatasi total satu polling. Gunakan admission per gelombang,
 * deadline monotonik, transfer terbatas, dan headroom untuk CPU/state IO (bukan klaim hard CPU timeout).
 *
 * Sekarang foto diproses dalam GELOMBANG paralel (S3/R2: AWS SDK executeAsync, satu gelombang = N foto
 * sekaligus) lewat fase berurutan dengan timeout keras per fase:
 *   1) GET thumbnail cache   (thumb_timeout)
 *   2) GET foto asli          (source_timeout)     ← hanya untuk yang belum punya thumbnail
 *   3) resize GD              (CPU, dibatasi ukuran)
 *   4) PUT thumbnail cache    (put_timeout)        ← kegagalan tidak fatal
 * Karena semua foto dalam satu fase berjalan bersamaan, terburuk satu gelombang =
 * worstCaseSeconds() (jumlah timeout fase + margin CPU), BUKAN dikali jumlah foto. Pemanggil hanya
 * boleh memulai gelombang baru bila sisa anggaran >= worstCaseSeconds().
 *
 * Kunci cache memakai path foto saja (path kios acak 40 karakter, berubah tiap upload) sehingga
 * tidak perlu HEAD (lastModified) lagi — satu round trip jaringan lebih hemat per foto.
 */
class KioskInsightThumbnail
{
    private const FAILED = 'Foto gagal dimuat';

    /** Satu foto, tanpa anggaran (dipakai test/skrip). Export memakai prepareBatch(). */
    public static function prepare(array $row): array
    {
        return self::prepareBatch([$row])[0];
    }

    /** @return array<string, int|float> */
    public static function settings(): array
    {
        $c = (array) config('kiosk_insights.photo', []);
        $clamp = static function ($value, float $default, float $min, float $max): float {
            $value = is_numeric($value) && is_finite((float) $value) ? (float) $value : $default;

            return max($min, min($max, $value));
        };
        // Reserve at least 1.5s outside the photo deadline for state/lock/request IO.
        $budget = $clamp($c['budget'] ?? null, 8.5, 3, 8.5);
        $limit = (float) ini_get('max_execution_time');
        if ($limit > 0) {
            $budget = min($budget, max(0.0, $limit - 2));
        }
        $thumb = $clamp($c['thumb_timeout'] ?? null, 1.5, 0.1, 1.5);
        $source = $clamp($c['source_timeout'] ?? null, 3, 0.1, 3);
        $put = $clamp($c['put_timeout'] ?? null, 1, 0.1, 1);
        // A bad env value must shorten operations, never inflate the request budget.
        $scale = min(1.0, max(0.0, $budget - 2.25) / ($thumb + $source + $put));

        return [
            'budget' => $budget,
            'concurrency' => (int) $clamp($c['concurrency'] ?? null, 10, 1, 10),
            'connect_timeout' => $clamp($c['connect_timeout'] ?? null, 1, 0.1, 1),
            'thumb_timeout' => $thumb * $scale,
            'source_timeout' => $source * $scale,
            'put_timeout' => $put * $scale,
            'cpu_margin' => 2.0,
            'max_source_bytes' => (int) $clamp($c['max_source_bytes'] ?? null, 5 * 1024 * 1024, 1024, 5 * 1024 * 1024),
            'max_source_pixels' => 4000000,
            'max_thumb_bytes' => 128 * 1024,
        ];
    }

    /** Admission estimate, including 2s for ten bounded, sequential GD decodes. */
    public static function worstCaseSeconds(): float
    {
        $s = self::settings();

        return $s['thumb_timeout'] + $s['source_timeout'] + $s['put_timeout'] + $s['cpu_margin'];
    }

    public static function budgetSeconds(): float
    {
        return self::settings()['budget'];
    }

    /** Monotonic seconds, shared by the caller and every phase/transfer. */
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * @param  array<int|string, array>  $rows  baris laporan (kunci dipertahankan)
     * @return array<int|string, array> baris dengan thumbnail_path + photo_status terisi
     */
    public static function prepareBatch(array $rows, ?float $deadline = null): array
    {
        $deadline ??= self::now() + self::budgetSeconds();
        $jobs = [];
        $diskName = (string) config('app.media_disk', 'public');
        foreach ($rows as $i => $row) {
            $row['thumbnail_path'] = null;
            $row['photo_status'] = 'Tidak ada foto';
            $path = $row['photo_path'] ?? null;
            if ($path) {
                $row['photo_status'] = self::FAILED;
                // Hanya path media aplikasi — bukan URL, traversal, atau path filesystem absolut.
                if (is_string($path) && preg_match('~^kiosks/[a-zA-Z0-9_./-]+$~D', $path) && ! str_contains($path, '..')) {
                    $key = hash('sha256', $diskName.'|'.$path.'|160-q65-v2');
                    $local = storage_path('app/private/kiosk-insight-thumbs/'.$key.'.jpg');
                    $cached = is_file($local) && filesize($local) <= self::settings()['max_thumb_bytes']
                        ? @file_get_contents($local, false, null, 0, self::settings()['max_thumb_bytes'] + 1) : false;
                    if (is_string($cached) && self::validThumbnail($cached)) {
                        $row['thumbnail_path'] = $local;
                        $row['photo_status'] = 'ok';
                    } else {
                        $jobs[$i] = ['path' => $path, 'key' => $key, 'local' => $local];
                    }
                }
            }
            $rows[$i] = $row;
        }
        if ($jobs === []) {
            return $rows;
        }

        $done = [];
        try {
            $options = (array) config("filesystems.disks.{$diskName}");
            foreach (array_chunk($jobs, self::settings()['concurrency'], true) as $wave) {
                if ($deadline - self::now() < self::worstCaseSeconds()) {
                    break;
                }
                $done += ($options['driver'] ?? null) === 's3'
                    ? self::fetchRemote($options, $wave, $deadline)
                    : self::fetchLocalDisk(Storage::disk($diskName), $wave, $deadline);
            }
        } catch (\Throwable $e) {
            // Foto yang hilang/rusak/tak terjangkau tidak pernah menggagalkan laporan; path privat tidak dilog.
        }
        foreach ($done as $i => $bytes) {
            try {
                File::ensureDirectoryExists(dirname($jobs[$i]['local']));
                File::put($jobs[$i]['local'], $bytes);
                $rows[$i]['thumbnail_path'] = $jobs[$i]['local'];
                $rows[$i]['photo_status'] = 'ok';
            } catch (\Throwable $e) {
                // tetap "gagal dimuat"
            }
        }

        return $rows;
    }

    /** Disk lokal (dev/test): tanpa jaringan, jadi sekuensial. */
    private static function fetchLocalDisk(Filesystem $disk, array $jobs, float $deadline): array
    {
        $done = [];
        foreach ($jobs as $i => $job) {
            if ($deadline - self::now() < self::settings()['cpu_margin']) {
                break;
            }
            try {
                $remote = 'thumbs/kiosk-insights/'.$job['key'].'.jpg';
                $bytes = self::readDisk($disk, $remote, self::settings()['max_thumb_bytes']);
                if ($bytes !== null && self::validThumbnail($bytes)) {
                    $done[$i] = $bytes;

                    continue;
                }
                $source = self::readDisk($disk, $job['path'], self::settings()['max_source_bytes']);
                $bytes = $source === null ? null : self::resize($source);
                if ($bytes === null) {
                    continue;
                }
                // Keep good bytes even when the optional cache write throws.
                $done[$i] = $bytes;
                try {
                    $disk->put($remote, $bytes, ['visibility' => 'private']);
                } catch (\Throwable $e) {
                }
            } catch (\Throwable $e) {
                // foto ini gagal, lanjut; no negative cache, so a future export retries it.
            }
        }

        return $done;
    }

    private static function readDisk(Filesystem $disk, string $path, int $max): ?string
    {
        $stream = null;
        try {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return null;
            }
            $bytes = stream_get_contents($stream, $max + 1);

            return is_string($bytes) && strlen($bytes) <= $max ? $bytes : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private static function validThumbnail(string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > self::settings()['max_thumb_bytes']) {
            return false;
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > 160 || $size[2] !== IMAGETYPE_JPEG) {
            return false;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return false;
        }
        imagedestroy($image);

        return true;
    }

    /** R2/S3: gelombang paralel dengan timeout keras per fase. */
    private static function fetchRemote(array $options, array $jobs, float $deadline): array
    {
        $s = self::settings();
        // Laravel forwards these options to the actual AWS client. Never inherit SDK retries.
        $options['http'] = ['connect_timeout' => $s['connect_timeout'], 'timeout' => $s['source_timeout']];
        $options['retries'] = 0;
        /** @var S3Client $client */
        $client = Storage::build($options)->getClient();
        $bucket = (string) $options['bucket'];
        $prefix = isset($options['root']) && $options['root'] !== '' ? trim((string) $options['root'], '/').'/' : '';
        $keys = [];
        foreach ($jobs as $i => $job) {
            $keys[$i] = $prefix.'thumbs/kiosk-insights/'.$job['key'].'.jpg';
        }
        $done = [];
        $cached = self::download($client, $bucket, $keys, $s['max_thumb_bytes'], min($deadline - $s['cpu_margin'], self::now() + $s['thumb_timeout']));
        foreach ($cached as $i => $stream) {
            $bytes = (string) $stream;
            if (self::validThumbnail($bytes)) {
                $done[$i] = $bytes;
            }
        }
        unset($cached);
        $keys = [];
        foreach (array_diff_key($jobs, $done) as $i => $job) {
            $keys[$i] = $prefix.$job['path'];
        }
        // Body goes to bounded temp files, not N full source strings in memory. Chunked and
        // missing/lying Content-Length responses are bounded by the sink's write callback too.
        $sources = self::download($client, $bucket, $keys, $s['max_source_bytes'], min($deadline - $s['cpu_margin'] - $s['put_timeout'], self::now() + $s['source_timeout']));
        foreach ($sources as $i => $stream) {
            if ($deadline - self::now() < 0.25) {
                break;
            }
            $thumb = self::resize((string) $stream);
            unset($sources[$i]);
            if ($thumb !== null) {
                $done[$i] = $thumb;
            }
        }
        unset($sources);

        $puts = [];
        $putDeadline = min($deadline - 0.1, self::now() + $s['put_timeout']);
        foreach ($done as $i => $thumb) {
            // Remote hits need no rewrite. Cache PUT is optional; a good local result survives it.
            if (isset($keys[$i]) && $putDeadline > self::now()) {
                $puts[$i] = $client->getCommand('PutObject', [
                    'Bucket' => $bucket, 'Key' => $prefix.'thumbs/kiosk-insights/'.$jobs[$i]['key'].'.jpg',
                    'Body' => $thumb, 'ContentType' => 'image/jpeg',
                    '@http' => self::http($putDeadline),
                ]);
            }
        }
        self::settle($client, $puts);

        return $done;
    }

    private static function http(float $deadline): array
    {
        $remaining = max(0.001, $deadline - self::now());

        return [
            'connect_timeout' => min(self::settings()['connect_timeout'], $remaining),
            'timeout' => $remaining,
        ];
    }

    /** @return array<int|string, StreamInterface> */
    private static function download(S3Client $client, string $bucket, array $keys, int $max, float $deadline, ?array &$measurements = null): array
    {
        $commands = $streams = $started = [];
        $measure = $measurements !== null;
        foreach ($keys as $i => $key) {
            if ($deadline <= self::now()) {
                break;
            }
            $file = tmpfile();
            if ($file === false) {
                continue;
            }
            $stream = Streams::streamFor($file);
            $sink = FnStream::decorate($stream, [
                'write' => static function (string $bytes) use ($stream, $max, $deadline): int {
                    if (self::now() >= $deadline || $stream->tell() + strlen($bytes) > $max) {
                        // A short write aborts cURL with WRITE_ERROR for this request only. Throwing
                        // from a cURL callback can unwind the entire multi-handler and lose good peers.
                        return 0;
                    }

                    return $stream->write($bytes);
                },
            ]);
            $streams[$i] = $sink;
            $started[$i] = self::now();
            $commands[$i] = $client->getCommand('GetObject', [
                'Bucket' => $bucket, 'Key' => $key,
                '@http' => self::http($deadline) + [
                    'sink' => $sink,
                    'on_headers' => static function ($response) use ($max) {
                        if ((float) $response->getHeaderLine('Content-Length') > $max) {
                            throw new \RuntimeException('Photo transfer limit exceeded.');
                        }
                    },
                ],
            ]);
        }
        $record = $measure ? static function ($i, $result, $error) use (&$measurements, $started): void {
            $message = strtolower($error ? $error->getMessage() : '');
            $timeout = str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'deadline') || str_contains($message, 'error 28');
            $measurements[$i] = [
                'ms' => (int) round((self::now() - $started[$i]) * 1000),
                'bytes' => $result ? (int) $result['Body']->getSize() : null,
                'status' => $result ? 'sukses' : ($timeout ? 'timeout' : 'gagal'),
            ];
        } : null;
        foreach (self::settle($client, $commands, $record) as $i => $result) {
            if ($result === null) {
                $streams[$i]->close();
                unset($streams[$i]);
            }
        }

        return $streams;
    }

    /**
     * Jalankan semua perintah bersamaan; hasil per kunci = Result, atau null bila gagal/timeout/404.
     * Tidak pernah melempar.
     *
     * @param  array<int|string, CommandInterface>  $commands
     * @return array<int|string, ResultInterface|null>
     */
    private static function settle(S3Client $client, array $commands, ?callable $record = null): array
    {
        $outcomes = [];
        foreach ($commands as $i => $command) {
            $outcomes[$i] = null;
        }
        if ($commands === []) {
            return $outcomes;
        }
        try {
            $promises = [];
            foreach ($commands as $i => $command) {
                $promises[$i] = $client->executeAsync($command)->then(
                    static function ($result) use ($record, $i) {
                        if ($record) {
                            $record($i, $result, null);
                        }

                        return $result;
                    },
                    static function ($error) use ($record, $i) {
                        if ($record) {
                            $record($i, null, $error);
                        }

                        return null;
                    },
                );
            }
            foreach (Utils::settle($promises)->wait() as $i => $state) {
                $outcomes[$i] = ($state['state'] ?? null) === 'fulfilled' ? $state['value'] : null;
            }
        } catch (\Throwable $e) {
            // sisa tetap null → foto ditandai gagal
        }

        return $outcomes;
    }

    /** JPEG 160px q65 dari bytes mentah, atau null bila rusak/terlalu besar. */
    private static function resize(string $source): ?string
    {
        if ($source === '' || strlen($source) > self::settings()['max_source_bytes']) {
            return null;
        }
        $size = @getimagesizefromstring($source);
        if (! $size || $size[0] < 1 || $size[1] < 1 || self::settings()['max_source_pixels'] < $size[0] * $size[1]) {
            return null;
        }
        $image = @imagecreatefromstring($source);
        if (! $image) {
            return null;
        }
        $scale = min(1, 160 / max($size[0], $size[1]));
        $small = imagecreatetruecolor(max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale)));
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $size[0], $size[1]);
        ob_start();
        imagejpeg($small, null, 65);
        $bytes = ob_get_clean();
        imagedestroy($image);
        imagedestroy($small);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * READ-ONLY: ukur pengambilan foto ASLI (tanpa menulis apa pun) untuk command probe.
     * Memakai klien/timeout yang sama dengan export, jadi hasilnya mewakili produksi.
     *
     * @param  list<string>  $paths
     * @return array{rows: list<array{path: string, ms: int, bytes: int|null, status: string}>, wall_ms: int}
     */
    public static function probe(array $paths, bool $parallel): array
    {
        $s = self::settings();
        $diskName = (string) config('app.media_disk', 'public');
        $options = (array) config("filesystems.disks.{$diskName}");
        $wall = hrtime(true);
        $rows = [];
        if (($options['driver'] ?? null) !== 's3') {
            $disk = Storage::disk($diskName);
            foreach ($paths as $path) {
                $t = hrtime(true);
                $bytes = null;
                try {
                    $body = self::readDisk($disk, $path, $s['max_source_bytes']);
                    $bytes = $body === null ? null : strlen($body);
                } catch (\Throwable $e) {
                }
                $rows[] = ['path' => $path, 'ms' => (int) round((hrtime(true) - $t) / 1e6), 'bytes' => $bytes, 'status' => $bytes === null ? 'gagal' : 'sukses'];
            }

            return ['rows' => $rows, 'wall_ms' => (int) round((hrtime(true) - $wall) / 1e6)];
        }

        $options['http'] = ['connect_timeout' => $s['connect_timeout'], 'timeout' => $s['source_timeout']];
        $options['retries'] = 0;
        $client = Storage::build($options)->getClient();
        $bucket = (string) $options['bucket'];
        $prefix = isset($options['root']) && $options['root'] !== '' ? trim((string) $options['root'], '/').'/' : '';
        $one = function (array $keys) use ($client, $bucket, $prefix, $s): array {
            $out = [];
            foreach (array_chunk($keys, $s['concurrency'], true) as $wave) {
                $remote = array_map(static fn ($path) => $prefix.$path, $wave);
                // Same bounded transient sink as export. No media/local thumbnail cache writes.
                $streams = self::download($client, $bucket, $remote, $s['max_source_bytes'], self::now() + $s['source_timeout'], $out);
                unset($streams);
            }
            foreach ($keys as $i => $path) {
                $out[$i] ??= ['ms' => 0, 'bytes' => null, 'status' => 'gagal'];
            }

            return $out;
        };
        if ($parallel) {
            foreach ($one($paths) as $i => $r) {
                $rows[] = ['path' => $paths[$i]] + $r;
            }
        } else {
            foreach ($paths as $i => $path) {
                $rows[] = ['path' => $path] + $one([$i => $path])[$i];
            }
        }

        return ['rows' => $rows, 'wall_ms' => (int) round((hrtime(true) - $wall) / 1e6)];
    }
}

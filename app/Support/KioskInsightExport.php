<?php

namespace App\Support;

use App\Exports\KioskInsightsExport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Request-driven state machine: no worker, no after-response work, no business writes. */
class KioskInsightExport
{
    public const TTL_HOURS = 24;

    public static function authorize(): User
    {
        $user = auth()->user();
        abort_unless($user && $user->is_active && ($user->isOwner() || $user->isSuperAdmin()), 403);

        return $user;
    }

    public static function start(string $format, ?int $ownerId): string
    {
        $user = self::authorize();
        abort_unless(in_array($format, ['excel', 'photos', 'pdf'], true), 422);
        abort_if($user->isOwner() && $ownerId !== (int) $user->id, 403);
        if ($ownerId !== null) {
            abort_unless(User::whereKey($ownerId)->where('role', 'owner')->exists(), 404);
        }
        self::prune();
        // Snapshot all business numbers once. Later photo ticks never query/update business data.
        $data = KioskInsights::build($ownerId);
        $id = (string) Str::uuid();
        File::ensureDirectoryExists(self::directory($id));
        self::save($id, [
            'creator_id' => $user->id,
            'owner_id' => $ownerId,
            'format' => $format,
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addHours(self::TTL_HOURS)->toIso8601String(),
            'stage' => $format === 'excel' ? 'write' : 'photos',
            'cursor' => 0,
            'part' => 0,
            'message' => $format === 'excel' ? 'Menyusun Excel…' : 'Menyiapkan foto 0/'.count($data['rows']).'…',
            'data' => $data,
        ]);

        return $id;
    }

    /** Only disclose public progress; never send report rows, file paths or owner IDs to Livewire. */
    public static function status(string $id): array
    {
        $state = self::state($id);

        return self::progress($state);
    }

    private static function progress(array $state): array
    {
        return [
            'stage' => $state['stage'],
            'message' => $state['message'],
            'ready' => $state['stage'] === 'ready',
            'failed' => $state['stage'] === 'failed',
        ];
    }

    public static function tick(string $id): array
    {
        self::authorize();
        self::directory($id); // Validate before using an identifier as a lock key.

        // Explicit file-store lock works across Octane workers, even if CACHE_STORE=array.
        // TTL 30 dtk (bukan 55): fatal PHP (mis. batas eksekusi) tidak melepas lock, dan tombol "Lanjutkan"
        // tidak boleh menunggu lock yatim lebih lama dari satu batas eksekusi.
        $tickStarted = KioskInsightThumbnail::now();

        return Cache::store('file')->lock('kiosk-insight-export-'.$id, 30)->get(function () use ($id, $tickStarted) {
            $state = self::state($id);
            if (in_array($state['stage'], ['ready', 'failed'], true)) {
                return self::progress($state);
            }
            try {
                if ($state['stage'] === 'photos') {
                    $count = count($state['data']['rows']);
                    // Includes time already spent loading state. Never bypass admission for a first wave;
                    // phase deadlines share this monotonic clock, leaving headroom for saving state.
                    $deadline = $tickStarted + KioskInsightThumbnail::budgetSeconds();
                    $worst = KioskInsightThumbnail::worstCaseSeconds();
                    $wave = KioskInsightThumbnail::settings()['concurrency'];
                    while ($state['cursor'] < $count && $deadline - KioskInsightThumbnail::now() >= $worst) {
                        $indexes = [];
                        $cursor = $state['cursor'];
                        // Baris tanpa foto murah: ikut lewat tanpa menghabiskan jatah gelombang.
                        while ($cursor < $count && count($indexes) < $wave) {
                            if (! empty($state['data']['rows'][$cursor]['photo_path'])) {
                                $indexes[] = $cursor;
                            } elseif ($state['data']['rows'][$cursor]['photo_status'] === 'pending') {
                                $state['data']['rows'][$cursor]['photo_status'] = 'Tidak ada foto';
                            }
                            $cursor++;
                        }
                        if ($indexes !== []) {
                            $batch = [];
                            foreach ($indexes as $index) {
                                $batch[$index] = $state['data']['rows'][$index];
                            }
                            foreach (KioskInsightThumbnail::prepareBatch($batch, $deadline) as $index => $row) {
                                $state['data']['rows'][$index] = $row;
                            }
                        }
                        $state['cursor'] = $cursor;
                    }
                    $state['message'] = 'Menyiapkan foto '.$state['cursor'].'/'.$count.'…';
                    if ($state['cursor'] >= $count) {
                        $state['stage'] = $state['format'] === 'pdf' ? 'summary' : 'write';
                        $state['cursor'] = 0;
                    }
                } elseif ($state['stage'] === 'summary') {
                    $data = $state['data'];
                    // Summary uses every row; card renderer receives no rows on this first part.
                    $data['summary_only'] = true;
                    KioskInsightsPdf::renderPart($data, true, false)->save(self::directory($id).'/part-0.pdf');
                    $state['part'] = 1;
                    $state['stage'] = 'cards';
                    $state['message'] = 'Menyusun kartu kedai 0/'.count($data['rows']).'…';
                } elseif ($state['stage'] === 'cards') {
                    $data = $state['data'];
                    $data['rows'] = array_slice($data['rows'], $state['cursor'], 18);
                    KioskInsightsPdf::renderPart($data, false, false)->save(self::directory($id).'/part-'.$state['part'].'.pdf');
                    $state['part']++;
                    $state['cursor'] += count($data['rows']);
                    $state['message'] = 'Menyusun kartu kedai '.$state['cursor'].'/'.count($state['data']['rows']).'…';
                    if ($state['cursor'] >= count($state['data']['rows'])) {
                        $state['stage'] = 'merge';
                    }
                } elseif ($state['stage'] === 'merge') {
                    self::mergePdf($id, $state['part']);
                    $state['stage'] = 'ready';
                } elseif ($state['stage'] === 'write') {
                    $stored = Excel::store(new KioskInsightsExport($state['data'], $state['format'] === 'photos'), 'kiosk-insight-exports/'.$id.'/report.xlsx', 'local');
                    if (! $stored) {
                        throw new \RuntimeException('Could not store private kiosk report.');
                    }
                    $state['stage'] = 'ready';
                }
                if ($state['stage'] === 'ready') {
                    $state['filename'] = KioskInsightsPdf::filename($state['data'], $state['format'] === 'pdf' ? 'pdf' : 'xlsx');
                    $state['message'] = 'Laporan siap diunduh. Berlaku 24 jam sejak dibuat.';
                    // Remove private business snapshot once output is ready.
                    unset($state['data']);
                }
            } catch (\Throwable $e) {
                report($e);
                $state['stage'] = 'failed';
                $state['message'] = 'Laporan gagal disiapkan. Silakan buat ulang atau hubungi pengelola.';
                unset($state['data']);
            }
            self::save($id, $state);
            $elapsed = KioskInsightThumbnail::now() - $tickStarted;
            if ($elapsed > KioskInsightThumbnail::budgetSeconds() + 3) {
                // Terlihat di Railway Logs (stderr). Tanpa path/nama/ID bisnis.
                Log::warning(sprintf('KIOSK_INSIGHT_TICK_SLOW stage=%s cursor=%d dur_ms=%d', $state['stage'], (int) ($state['cursor'] ?? 0), round($elapsed * 1000)));
            }

            return self::progress($state);
        }) ?? ['stage' => 'busy', 'message' => 'Sedang memproses…', 'ready' => false, 'failed' => false];
    }

    private static function mergePdf(string $id, int $parts): void
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $templates = [];
        for ($part = 0; $part < $parts; $part++) {
            $pages = $pdf->setSourceFile(self::directory($id).'/part-'.$part.'.pdf');
            for ($page = 1; $page <= $pages; $page++) {
                $templates[] = $pdf->importPage($page, \setasign\Fpdi\PdfReader\PageBoundaries::CROP_BOX, true, true);
            }
        }
        $total = count($templates);
        foreach ($templates as $index => $template) {
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(110, 100, 92);
            $pdf->SetXY($size['width'] - 45, $size['height'] - 12);
            $pdf->Cell(30, 5, 'Hal '.($index + 1).'/'.$total, 0, 0, 'R');
        }
        $pdf->Output('F', self::directory($id).'/report.pdf');
        for ($part = 0; $part < $parts; $part++) {
            File::delete(self::directory($id).'/part-'.$part.'.pdf');
        }
    }

    public static function download(string $id): BinaryFileResponse
    {
        $state = self::state($id);
        abort_unless($state['stage'] === 'ready', 409);
        $extension = $state['format'] === 'pdf' ? 'pdf' : 'xlsx';
        $path = self::directory($id).'/report.'.$extension;
        abort_unless(is_file($path), 404);

        return response()->download($path, $state['filename'], ['Cache-Control' => 'private, no-store']);
    }

    private static function state(string $id): array
    {
        $user = self::authorize();
        $path = self::directory($id).'/state.json';
        abort_unless(is_file($path), 404);
        $state = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        // Even a super-admin may only download files they created themselves.
        abort_unless((int) $state['creator_id'] === (int) $user->id, 404);
        abort_if($user->isOwner() && (int) ($state['owner_id'] ?? 0) !== (int) $user->id, 403);
        if (now()->greaterThanOrEqualTo($state['expires_at'])) {
            File::deleteDirectory(self::directory($id));
            abort(410, 'Laporan sudah kedaluwarsa. Buat laporan baru.');
        }

        return $state;
    }

    private static function save(string $id, array $state): void
    {
        $directory = self::directory($id);
        File::put($directory.'/state.tmp', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        File::move($directory.'/state.tmp', $directory.'/state.json');
    }

    private static function directory(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return storage_path('app/private/kiosk-insight-exports/'.$id);
    }

    /** Opportunistic expiry without a scheduler; also callable by the cleanup command. */
    public static function prune(): int
    {
        $root = storage_path('app/private/kiosk-insight-exports');
        if (! is_dir($root)) {
            return 0;
        }
        $deleted = 0;
        foreach (File::directories($root) as $directory) {
            $expired = filemtime($directory) < now()->subHours(self::TTL_HOURS)->timestamp;
            $statePath = $directory.'/state.json';
            if (is_file($statePath)) {
                $state = json_decode(File::get($statePath), true);
                $expired = $expired || (isset($state['expires_at']) && now()->greaterThanOrEqualTo($state['expires_at']));
            }
            if ($expired) {
                File::deleteDirectory($directory);
                $deleted++;
            }
        }
        $thumbRoot = storage_path('app/private/kiosk-insight-thumbs');
        if (is_dir($thumbRoot)) {
            foreach (File::files($thumbRoot) as $file) {
                if ($file->getMTime() < now()->subHours(self::TTL_HOURS)->timestamp) {
                    File::delete($file->getPathname());
                }
            }
        }

        return $deleted;
    }
}

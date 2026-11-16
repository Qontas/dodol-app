<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Small, reusable export images. Never follows a URL supplied by a kiosk. */
class KioskInsightThumbnail
{
    public static function prepare(array $row): array
    {
        $row['thumbnail_path'] = null;
        $row['photo_status'] = 'Tidak ada foto';
        $path = $row['photo_path'] ?? null;
        if (! $path) {
            return $row;
        }
        $row['photo_status'] = 'Foto gagal dimuat';
        // Only application media paths, never URLs, traversal or absolute filesystem paths.
        if (! preg_match('~^kiosks/[a-zA-Z0-9_./-]+$~D', $path) || str_contains($path, '..')) {
            return $row;
        }

        try {
            $diskName = config('app.media_disk', 'public');
            $options = config("filesystems.disks.{$diskName}");
            $disk = Storage::disk($diskName);
            if (($options['driver'] ?? null) === 's3') {
                // AWS defaults can wait/retry for minutes, breaking the short polling contract.
                $options['http'] = ['connect_timeout' => 2, 'timeout' => 4];
                $options['retries'] = 0;
                $disk = Storage::build($options);
            }
            $modified = $disk->lastModified($path);
            $key = hash('sha256', $diskName.'|'.$path.'|'.$modified.'|160-q65-v1');
            $local = storage_path('app/private/kiosk-insight-thumbs/'.$key.'.jpg');
            File::ensureDirectoryExists(dirname($local));
            if (! is_file($local)) {
                $remote = 'thumbs/kiosk-insights/'.$key.'.jpg';
                $bytes = $disk->get($remote);
                if (! $bytes) {
                    $source = $disk->get($path);
                    if (! $source || strlen($source) > 20 * 1024 * 1024) {
                        return $row;
                    }
                    $size = @getimagesizefromstring($source);
                    if (! $size || $size[0] * $size[1] > 24000000) {
                        return $row;
                    }
                    $image = @imagecreatefromstring($source);
                    if (! $image) {
                        return $row;
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
                    // Cache failure is nonfatal; the current export can still use its local copy.
                    $disk->put($remote, $bytes, ['visibility' => 'private']);
                }
                if (! @getimagesizefromstring($bytes)) {
                    return $row;
                }
                File::put($local, $bytes);
            }
            $row['thumbnail_path'] = $local;
            $row['photo_status'] = 'ok';
        } catch (\Throwable $e) {
            // Missing, corrupt or unavailable photos never fail the report; do not log private paths.
        }

        return $row;
    }
}

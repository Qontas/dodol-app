<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class ImageResizer
{
    /**
     * Kecilkan gambar tersimpan agar sisi terpanjang <= max (jaring pengaman server).
     *
     * Bekerja di disk LOCAL maupun CLOUD (R2/S3): baca via Storage::get() → GD resize
     * → tulis balik via Storage::put(). Menggantikan pendekatan lama yang hanya jalan
     * di disk lokal (Storage::path()). Aman dipanggil walau GD/format tak didukung
     * (diam-diam dilewati) — kompres di browser tetap jadi lapisan utama.
     */
    public static function fit(string $path, string $disk, int $maxW, int $maxH): void
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('getimagesizefromstring')) {
            return; // GD tak tersedia — biarkan file apa adanya.
        }

        $storage = Storage::disk($disk);

        try {
            $bytes = $storage->get($path);
        } catch (\Throwable $e) {
            return;
        }
        if ($bytes === null || $bytes === '') {
            return;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return; // bukan gambar yang bisa dibaca GD.
        }

        [$origW, $origH, $type] = [$info[0], $info[1], $info[2]];
        if ($origW <= 0 || $origH <= 0) {
            return;
        }
        if ($origW <= $maxW && $origH <= $maxH) {
            return; // sudah cukup kecil — jangan re-encode percuma.
        }

        // Jaring memory (Octane long-running): decode GD mengalokasikan ~W*H*4 byte truecolor.
        // Foto normal sudah early-return di atas (hasil kompres browser ≤ maxW/maxH) dan tak
        // pernah sampai sini. Yang sampai sini = fallback foto mentah besar (kompres browser
        // gagal). Tolak yang PATOLOGIS (>24MP ≈ >96MB decode / kemungkinan decompression bomb)
        // tanpa decode — file tetap tersimpan apa adanya (≤5MB per validasi upload).
        if (($origW * $origH) > 24_000_000) {
            return;
        }

        // Untuk foto besar yang wajar (mis. 12MP mentah ≈ 48MB decode), naikkan memory_limit
        // SEMENTARA agar worker tak OOM, lalu pulihkan. Foto normal tak sampai sini.
        $restoreMemoryLimit = self::raiseMemoryLimitFor((int) ($origW * $origH * 4) + 48 * 1024 * 1024);

        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return;
            }

            $ratio = min($maxW / $origW, $maxH / $origH);
            $newW = max(1, (int) round($origW * $ratio));
            $newH = max(1, (int) round($origH * $ratio));

            $dst = imagecreatetruecolor($newW, $newH);
            // Latar putih: cegah PNG/WEBP transparan jadi hitam bila disimpan sebagai JPEG.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

            ob_start();
            $ok = match ($type) {
                IMAGETYPE_PNG => imagepng($dst),
                IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, null, 85) : imagejpeg($dst, null, 85),
                default => imagejpeg($dst, null, 85),
            };
            $out = ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            if ($ok && is_string($out) && $out !== '') {
                $storage->put($path, $out);
            }
        } finally {
            if ($restoreMemoryLimit !== null) {
                @ini_set('memory_limit', $restoreMemoryLimit);
            }
        }
    }

    /**
     * Naikkan memory_limit sementara HANYA bila kurang dari kebutuhan decode, dibatasi 384MB.
     * Mengembalikan nilai lama untuk dipulihkan, atau null bila tak perlu diubah
     * (sudah unlimited / sudah cukup / gagal set).
     */
    private static function raiseMemoryLimitFor(int $needBytes): ?string
    {
        $current = ini_get('memory_limit');
        if ($current === false) {
            return null;
        }
        $trimmed = trim((string) $current);
        if ($trimmed === '-1') {
            return null; // unlimited — tak perlu diubah.
        }

        $currentBytes = self::parseBytes($trimmed);
        $target = min($needBytes, 384 * 1024 * 1024);
        if ($currentBytes >= $target) {
            return null; // sudah cukup.
        }

        if (@ini_set('memory_limit', (string) $target) === false) {
            return null;
        }

        return $trimmed; // nilai lama untuk dipulihkan.
    }

    /** Parse '128M' / '1G' / '512K' / bytes → integer byte. */
    private static function parseBytes(string $value): int
    {
        $value = trim($value);
        $num = (int) $value;
        $unit = strtolower($value[strlen($value) - 1] ?? '');

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}

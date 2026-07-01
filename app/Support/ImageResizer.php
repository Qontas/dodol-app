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
    }
}

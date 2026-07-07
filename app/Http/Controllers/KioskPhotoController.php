<?php

namespace App\Http\Controllers;

use App\Models\Kiosk;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxy same-origin foto kios (R2/S3 → app → browser).
 *
 * AKAR yang ditutup: browser Chromium di sebagian environment salah-tangani
 * koneksi HTTPS lintas-origin ke domain CDN cert-wildcard bersama (pub-*.r2.dev)
 * — net::ERR_CERT_COMMON_NAME_INVALID transien, terbukti BUKAN app/server/URL
 * (byte-identik, curl 100% bersih) & BUKAN device/jaringan user spesifik
 * (direproduksi di mesin lain juga, lalu hilang sendiri). Daripada terus kejar
 * environment browser yang tak bisa dikendalikan app, foto sekarang di-stream
 * lewat domain app sendiri (same-origin) — kelas bug ini otomatis lenyap.
 *
 * Bonus keamanan: Kiosk::booted() mendaftarkan OwnerScope global, jadi route
 * model binding di sini OTOMATIS menolak (404) akses ke kios milik owner lain
 * — isolasi tenant untuk foto yang TIDAK ada di public bucket R2 sebelumnya.
 */
class KioskPhotoController extends Controller
{
    public function show(Kiosk $kiosk): StreamedResponse
    {
        abort_if(! $kiosk->photo_path, 404);

        $disk = Storage::disk(config('app.media_disk', 'public'));

        try {
            return $disk->response($kiosk->photo_path, null, [
                // Content-Type dari ekstensi (bukan $disk->mimeType(), yang berarti
                // 1 round-trip API R2 lagi) — hemat satu panggilan server->R2 per foto.
                'Content-Type' => $this->guessMimeType($kiosk->photo_path),
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'ETag' => '"'.sha1($kiosk->photo_path.$kiosk->updated_at?->timestamp).'"',
            ]);
        } catch (\Throwable $e) {
            abort(404);
        }
    }

    private function guessMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}

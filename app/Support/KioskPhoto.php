<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Satu sumber kebenaran untuk FOTO KIOS: plafon ukuran, format yang diterima, dan
 * penyimpanan (konversi HEIC → kompres → simpan). Dipakai TIGA jalur yang dulu
 * masing-masing punya aturan sendiri dan karenanya berperilaku beda:
 *   - operator create-kiosk (App\Livewire\Operator\CreateKiosk)
 *   - operator active-trip  (App\Livewire\Operator\ActiveTrip::saveKioskPhoto)
 *   - owner Filament        (App\Filament\Resources\KioskResource)
 *
 * FILOSOFI (dari feedback lapangan): sistem MENGECILKAN foto, bukan MENOLAKnya.
 * Operator/owner tak perlu memikirkan ukuran file. Plafon di bawah ini cuma jaring
 * pengaman terhadap file ekstrem — bukan alat disiplin buat pengguna.
 *
 * LAPISAN:
 *   1. Browser  — kompres canvas ke ~1600px/JPEG 0.8 (resources/js/kiosk-photo.js);
 *                 hasil biasanya <1MB. HEIC dilewati (Android tak bisa decode).
 *   2. Server   — HEIC → JPG (HeicConverter), lalu ImageResizer::fit() sebagai lapis
 *                 kedua untuk upload dari perangkat yang tak menjalankan kompres
 *                 browser (mis. desktop lama).
 */
class KioskPhoto
{
    /**
     * Plafon aman 20MB. BUKAN ukuran yang diharapkan (foto normal sampai sini <1MB
     * karena sudah dikompres browser) — ini batas atas untuk file ekstrem/abuse.
     * Sengaja longgar supaya foto HEIC MENTAH (2–5MB, tak bisa dikompres di browser
     * Android) tetap lolos untuk dikonversi di server.
     *
     * Selaras dengan: config/livewire.php temporary_file_upload.rules (20480) dan
     * php-ini/uploads.ini (24M/28M — sengaja sedikit di ATAS ini supaya file kelewat
     * besar ditolak validasi Laravel dengan pesan jelas, bukan mati diam di level PHP).
     */
    public const MAKS_KB = 20480;

    /** Sisi terpanjang foto tersimpan. Foto ditampilkan kecil (thumbnail 50px,
     *  pratinjau max-h-36) → 1600px sudah lebih dari cukup untuk mengenali kedai. */
    public const MAKS_SISI = 1600;

    /** Mime yang diterima. HEIC/HEIF masuk HANYA supaya bisa DIKONVERSI di server —
     *  yang TERSIMPAN tetap selalu JPG. Lihat rules() untuk kasus server tanpa HEIF. */
    public const MIME_DITERIMA = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    /** Mime untuk FilePond/atribut accept di form owner. */
    public static function acceptedFileTypes(): array
    {
        return self::MIME_DITERIMA;
    }

    /**
     * Aturan validasi upload. `mimetypes` memeriksa ISI file (bukan ekstensi), jadi
     * tak bisa diakali dengan rename.
     *
     * CATATAN: HEIC tetap DITERIMA di sini walau server belum bisa mengonversi —
     * penolakannya ditangani terpisah (pesanHeicTakDidukung) supaya pengguna dapat
     * INSTRUKSI yang bisa ditindaklanjuti, bukan pesan mimetypes generik yang
     * membingungkan ("The foto must be a file of type: ...").
     */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', self::MIME_DITERIMA),
            'max:'.self::MAKS_KB,
        ];
    }

    public static function pesanValidasi(string $field): array
    {
        return [
            $field.'.mimetypes' => 'Format foto tidak didukung. Pakai JPG, PNG, atau WEBP.',
            $field.'.max' => 'Foto terlalu besar (maksimal 20MB). Coba foto ulang.',
            $field.'.file' => 'File foto tidak valid.',
        ];
    }

    /** Pesan saat HEIC diterima tapi server tak bisa mengonversinya. Actionable:
     *  sebut jalan keluar yang bisa dilakukan pengguna SEKARANG. */
    public static function pesanHeicTakDidukung(): string
    {
        return 'Format HEIC belum didukung di server ini. Ubah setelan kamera HP ke '
            .'JPG dulu — iPhone: Settings → Camera → Formats → "Most Compatible". '
            .'Android (Poco/Xiaomi): Kamera → Setelan → Format foto → JPEG.';
    }

    /**
     * Apakah file ini HEIC yang TIDAK bisa kita proses? Dipakai komponen untuk
     * menolak LEBIH DULU dengan pesan yang actionable.
     */
    public static function heicTakBisaDiproses(?UploadedFile $file): bool
    {
        if (! $file) {
            return false;
        }

        try {
            $kepala = (string) file_get_contents($file->getRealPath(), false, null, 0, 32);
        } catch (\Throwable $e) {
            return false;
        }

        return HeicConverter::isHeic($kepala) && ! HeicConverter::supported();
    }

    /**
     * Simpan foto kios: HEIC → JPG (kalau perlu) → kompres → path tersimpan.
     * Mengembalikan path, atau null kalau gagal (mis. HEIC di server tanpa HEIF).
     *
     * Titik sisip konversi ada DI SINI — SEBELUM ImageResizer::fit(). Itu wajib:
     * ImageResizer memakai GD, dan GD tak bisa membaca HEIC sama sekali
     * (getimagesizefromstring → false) sehingga fit() akan DIAM-DIAM melewatkannya dan
     * file HEIC mentah tersimpan → foto blank di mana-mana. Urutannya tak boleh dibalik.
     */
    public static function store(UploadedFile $file, ?string $disk = null): ?string
    {
        $disk ??= config('app.media_disk', 'public');

        $bytes = @file_get_contents($file->getRealPath());
        if ($bytes === false || $bytes === '') {
            return null;
        }

        if (HeicConverter::isHeic($bytes)) {
            $jpeg = HeicConverter::toJpeg($bytes);
            if ($jpeg === null) {
                return null; // JANGAN simpan HEIC mentah — foto blank buat pengguna lain.
            }

            $path = 'kiosks/'.\Illuminate\Support\Str::random(40).'.jpg';
            Storage::disk($disk)->put($path, $jpeg);
            ImageResizer::fit($path, $disk, self::MAKS_SISI, self::MAKS_SISI);

            return $path;
        }

        $path = $file->store('kiosks', $disk);
        if ($path === false || $path === '') {
            return null;
        }

        ImageResizer::fit($path, $disk, self::MAKS_SISI, self::MAKS_SISI);

        return $path;
    }
}

<?php

namespace App\Support;

use Imagick;

/**
 * Konversi foto HEIC/HEIF (iPhone & sebagian Android, mis. Poco/Xiaomi) → JPG.
 *
 * KENAPA DI SERVER, BUKAN DI BROWSER: HEIC hanya bisa di-decode browser WebKit
 * (Safari/iOS). Chrome di ANDROID — yang dipakai owner (Poco F7) — TIDAK bisa, jadi
 * jalur canvas di resources/js/kiosk-photo.js sengaja melewatkan HEIC dan mengirimnya
 * mentah ke sini. Alternatifnya decoder WASM (libheif ±2MB) di setiap muat halaman
 * lapangan — mahal untuk kasus yang jarang.
 *
 * KENAPA HARUS DIKONVERSI, BUKAN CUMA DIIZINKAN: HEIC tak bisa ditampilkan di
 * kebanyakan browser (Chrome Android/desktop). Kalau disimpan apa adanya, foto masuk
 * tapi BLANK saat dibuka operator lain atau di laptop. Yang tersimpan harus SELALU JPG.
 *
 * DUKUNGAN DI-DETEKSI SAAT RUNTIME, bukan diasumsikan: butuh ekstensi imagick DENGAN
 * delegate HEIF (libheif). Kalau tak ada → supported() false → validasi menolak HEIC
 * dengan pesan jelas (App\Support\KioskPhoto), TIDAK pernah menyimpan HEIC mentah yang
 * berujung foto blank. Cek status di server mana pun: `php artisan foto:diagnosa`.
 */
class HeicConverter
{
    /** Brand ISO-BMFF yang menandakan HEIF/HEIC. */
    private const BRAND_HEIF = [
        'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1',
    ];

    /**
     * Apakah server ini bisa mengonversi HEIC? (imagick ada DAN punya delegate HEIF)
     * Sengaja dicek dari kemampuan NYATA (queryFormats), bukan dari versi/nama distro.
     */
    public static function supported(): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(Imagick::class)) {
            return false;
        }

        try {
            return count(Imagick::queryFormats('HEI*')) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Deteksi HEIC dari ISI file (magic bytes), BUKAN dari ekstensi/mime kiriman klien.
     * Mime dari browser tak bisa dipercaya: Android kadang mengirim HEIC sebagai
     * application/octet-stream atau image/jpeg.
     */
    public static function isHeic(string $bytes): bool
    {
        // Struktur: [4 byte panjang box]["ftyp"][4 byte brand]
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(strtolower(substr($bytes, 8, 4)), self::BRAND_HEIF, true);
    }

    /**
     * HEIC → JPG. Mengembalikan byte JPG, atau null kalau tak didukung/gagal
     * (pemanggil WAJIB memperlakukan null sebagai gagal — jangan simpan yang asli).
     */
    public static function toJpeg(string $bytes, int $quality = 85): ?string
    {
        if (! self::supported()) {
            return null;
        }

        $imagick = null;

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($bytes);

            // HEIC bisa memuat BANYAK gambar (mis. burst/Live Photo) — ambil yang utama
            // saja, kalau tidak getImagesBlob() akan menyambung banyak JPEG jadi satu
            // file rusak.
            $imagick->setFirstIterator();

            self::terapkanOrientasi($imagick);

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage(); // buang EXIF (orientasi sudah "dipanggang" di atas)

            $jpeg = $imagick->getImageBlob();

            return ($jpeg !== '' && $jpeg !== false) ? $jpeg : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($imagick instanceof Imagick) {
                $imagick->clear();
            }
        }
    }

    /**
     * Panggang orientasi EXIF ke piksel lalu tandai normal. libheif biasanya sudah
     * menerapkan rotasi dari box 'irot', tapi tak selalu — tanpa ini foto HP bisa
     * tersimpan miring/terbalik, dan EXIF-nya kita buang setelah ini (stripImage)
     * sehingga tak ada lagi yang membetulkan di sisi tampilan.
     */
    private static function terapkanOrientasi(Imagick $imagick): void
    {
        try {
            $putih = new \ImagickPixel('white');

            switch ($imagick->getImageOrientation()) {
                case Imagick::ORIENTATION_TOPRIGHT:
                    $imagick->flopImage();
                    break;
                case Imagick::ORIENTATION_BOTTOMRIGHT:
                    $imagick->rotateImage($putih, 180);
                    break;
                case Imagick::ORIENTATION_BOTTOMLEFT:
                    $imagick->flopImage();
                    $imagick->rotateImage($putih, 180);
                    break;
                case Imagick::ORIENTATION_LEFTTOP:
                    $imagick->flopImage();
                    $imagick->rotateImage($putih, -90);
                    break;
                case Imagick::ORIENTATION_RIGHTTOP:
                    $imagick->rotateImage($putih, 90);
                    break;
                case Imagick::ORIENTATION_RIGHTBOTTOM:
                    $imagick->flopImage();
                    $imagick->rotateImage($putih, 90);
                    break;
                case Imagick::ORIENTATION_LEFTBOTTOM:
                    $imagick->rotateImage($putih, -90);
                    break;
                default:
                    return; // UNDEFINED / TOPLEFT → sudah benar.
            }

            $imagick->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        } catch (\Throwable $e) {
            // Orientasi gagal ≠ konversi gagal. Lebih baik foto tersimpan (mungkin
            // miring) daripada upload ditolak total.
        }
    }
}

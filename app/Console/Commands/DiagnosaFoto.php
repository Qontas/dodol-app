<?php

namespace App\Console\Commands;

use App\Support\HeicConverter;
use App\Support\KioskPhoto;
use Illuminate\Console\Command;

/**
 * Lapor kemampuan server memproses foto kios — terutama: BISA konversi HEIC atau tidak.
 *
 * KENAPA ADA: dukungan HEIC bergantung ekstensi imagick DENGAN delegate HEIF (libheif),
 * yang TAK BISA dipastikan dari mesin dev (imagick memang tak dipasang di sana). Daripada
 * menebak, jalankan ini di console Railway setelah deploy:
 *     php artisan foto:diagnosa
 */
class DiagnosaFoto extends Command
{
    protected $signature = 'foto:diagnosa';

    protected $description = 'Cek dukungan server untuk foto kios (imagick/HEIC, GD, batas upload)';

    public function handle(): int
    {
        $heic = HeicConverter::supported();

        $this->info('=== Foto Kios — Diagnosa Server ===');
        $this->newLine();

        $this->line('PHP           : '.PHP_VERSION);
        $this->line('GD (kompres)  : '.(extension_loaded('gd') ? '<fg=green>ADA</>' : '<fg=red>TIDAK ADA</>'));
        $this->line('Imagick       : '.(extension_loaded('imagick') ? '<fg=green>ADA</>' : '<fg=red>TIDAK ADA</>'));

        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $formats = \Imagick::queryFormats('HEI*');
            $this->line('Format HEI*   : '.($formats ? implode(', ', $formats) : '<fg=red>(kosong)</>'));
        }

        $this->newLine();
        $this->line('Konversi HEIC : '.($heic
            ? '<fg=green;options=bold>DIDUKUNG</> — HEIC otomatis jadi JPG saat upload.'
            : '<fg=red;options=bold>TIDAK DIDUKUNG</> — HEIC ditolak dengan pesan jelas ke pengguna.'));

        if (! $heic) {
            $this->newLine();
            $this->warn('Foto HEIC akan DITOLAK (bukan disimpan mentah — itu akan jadi foto blank).');
            $this->line('Pesan yang dilihat pengguna:');
            $this->line('  "'.KioskPhoto::pesanHeicTakDidukung().'"');
            $this->newLine();
            $this->line('Untuk mengaktifkan: tambahkan `imagick` ke install-php-extensions di Dockerfile,');
            $this->line('lalu redeploy dan jalankan perintah ini lagi.');
        }

        $this->newLine();
        $this->line('=== Batas upload (harus >= plafon aplikasi '.round(KioskPhoto::MAKS_KB / 1024).'MB) ===');
        $this->line('upload_max_filesize : '.ini_get('upload_max_filesize'));
        $this->line('post_max_size       : '.ini_get('post_max_size'));
        $this->line('memory_limit        : '.ini_get('memory_limit'));
        $this->line('plafon aplikasi     : '.round(KioskPhoto::MAKS_KB / 1024).'MB (validasi Laravel + Livewire)');

        return self::SUCCESS;
    }
}

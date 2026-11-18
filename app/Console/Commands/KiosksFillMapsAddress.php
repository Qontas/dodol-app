<?php

namespace App\Console\Commands;

use App\Models\Kiosk;
use App\Models\User;
use App\Support\ReverseGeocoder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class KiosksFillMapsAddress extends Command
{
    protected $signature = 'kiosks:fill-maps-address
        {--owner= : ID owner wajib}
        {--limit=20 : Maksimum kedai (1-1000)}
        {--force : Simpan hasil; tanpa opsi ini hanya dry-run (tetap memanggil geocoder)}';

    protected $description = 'Isi cache alamat Maps dari koordinat, hanya lewat CLI dan owner eksplisit';

    public function handle(ReverseGeocoder $geocoder): int
    {
        $owner = (string) ($this->option('owner') ?? '');
        if (! ctype_digit($owner) || (int) $owner < 1
            || ! User::query()->whereKey((int) $owner)->where('role', 'owner')->exists()) {
            $this->error('--owner wajib berupa ID owner yang valid.');

            return self::FAILURE;
        }
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('--limit harus 1 sampai 1000.');

            return self::FAILURE;
        }
        $persist = (bool) $this->option('force');
        $this->info($persist ? 'SIMPAN: hanya cache alamat Maps kosong.' : 'DRY-RUN: memanggil geocoder, tanpa perubahan database. Gunakan --force untuk menyimpan.');
        $kiosks = $this->eligible((int) $owner)->orderBy('id')->limit((int) $limit)
            ->get(['id', 'latitude', 'longitude']);
        $success = $saved = $failed = $stale = $attempted = 0;
        foreach ($kiosks as $kiosk) {
            $attempted++;
            try {
                $result = $geocoder->reverse((float) $kiosk->latitude, (float) $kiosk->longitude);
            } catch (\Throwable) {
                // Never print exceptions: request URLs can contain a Google key.
                $failed++;
                $this->error('Geocoder dihentikan (konfigurasi/koneksi/rate limit). Periksa REVERSE_GEOCODING_PROVIDER, REVERSE_GEOCODING_USER_AGENT, REVERSE_GEOCODING_CONTACT_EMAIL (Nominatim), GOOGLE_MAPS_API_KEY (Google), koneksi dan file lock. Tidak ada retry atau request lanjutan.');

                break;
            }
            if ($result === null) {
                $failed++;
                $this->warn('kedai #'.$kiosk->id.': alamat tidak tersedia (tanpa retry).');

                continue;
            }
            $success++;
            // Treat provider strings as data, not console markup/control codes.
            $example = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $result['address']);
            $this->output->writeln('kedai #'.$kiosk->id.' ['.$result['source'].'] '.mb_substr($example ?? '', 0, 300), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            if ($persist) {
                // Atomic compare-and-set: owner, coordinates and empty cache must
                // still match after network IO. Never overwrite a concurrent edit.
                $written = $this->eligible((int) $owner)->whereKey($kiosk->id)
                    ->where('latitude', $kiosk->latitude)->where('longitude', $kiosk->longitude)
                    ->toBase()->update([
                        'maps_address' => $result['address'],
                        'maps_address_at' => now(),
                        'maps_address_source' => $result['source'],
                    ]);
                $saved += $written;
                $stale += $written === 0 ? 1 : 0;
            }
        }
        $this->line(sprintf('kedai=%d sukses=%d tersimpan=%d gagal=%d berubah=%d', $attempted, $success, $saved, $failed, $stale));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function eligible(int $ownerId): Builder
    {
        // CLI has no authenticated owner; the explicit cluster constraint is
        // mandatory on BOTH the read and conditional write. Include inactive.
        return Kiosk::query()->excludeWalkInSentinel()
            ->whereHas('cluster', fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->whereBetween('latitude', [-90, 90])->whereBetween('longitude', [-180, 180])
            ->where(fn (Builder $query) => $query->whereNull('maps_address')->orWhereRaw("TRIM(maps_address) = ''"));
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OwnerDataReset;
use Illuminate\Console\Command;

class OwnerResetData extends Command
{
    protected $signature = 'owner:reset-data {ownerId : ID user owner (role=owner)} {--force : Eksekusi permanen; tanpa ini SELALU dry-run}';

    protected $description = 'HAPUS PERMANEN semua data operasional (kedai, trip, transaksi, katalog) milik satu owner. Dry-run tanpa --force. Tidak pernah menyentuh akun owner/operator-nya sendiri atau data owner lain.';

    public function handle(): int
    {
        $ownerId = (int) $this->argument('ownerId');

        // withoutGlobalScopes: command CLI tak punya auth() aktif → OwnerScope sudah
        // bypass total, tapi eksplisit di sini supaya niatnya jelas & tak bergantung konteks.
        $owner = User::withoutGlobalScopes()->find($ownerId);

        if (! $owner) {
            $this->error("User #{$ownerId} tidak ditemukan.");

            return self::FAILURE;
        }

        if ($owner->role !== 'owner') {
            $this->error("User #{$ownerId} ({$owner->name}) role-nya '{$owner->role}', bukan 'owner'. Command ini salah sasaran.");

            return self::FAILURE;
        }

        $summary = OwnerDataReset::summarize($ownerId);

        $this->info("Ringkasan data milik owner #{$ownerId} — {$owner->name} ({$owner->email}):");
        $this->table(['Tabel', 'Jumlah akan dihapus'], [
            ['clusters (area)', $summary['clusters']],
            ['kiosks (kedai)', $summary['kiosks']],
            ['suppliers', $summary['suppliers']],
            ['products', $summary['products']],
            ['product_variants', $summary['product_variants']],
            ['procurement_batches', $summary['procurement_batches']],
            ['trips (termasuk terarsip)', $summary['trips']],
            ['deliveries', $summary['deliveries']],
            ['delivery_origins', $summary['delivery_origins']],
            ['settlements', $summary['settlements']],
            ['kiosk_visits', $summary['kiosk_visits']],
            ['commissions', $summary['commissions']],
            ['fuel_logs', $summary['fuel_logs']],
        ]);

        $this->line('Foto kedai (R2) berpotensi jadi orphan: '.$summary['photo_paths_count'].' file (TIDAK dihapus oleh command ini — tidak ada mekanisme hapus file storage di codebase).');
        $this->line('Akun sistem operator.migrasi.owner'.$ownerId.'@...: '.($summary['migrasi_user_found'] ? 'DITEMUKAN, akan ikut dihapus.' : 'tidak ditemukan.'));

        if (! $this->option('force')) {
            $this->newLine();
            $this->info('Ini DRY-RUN. Tidak ada data yang diubah. Jalankan ulang dengan --force untuk eksekusi permanen.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('PERINGATAN: ini akan menghapus PERMANEN semua data di atas. TIDAK BISA DIBATALKAN. Pastikan backup sudah dibuat.');

        $confirmation = $this->ask("Ketik ulang email owner ({$owner->email}) untuk konfirmasi penghapusan PERMANEN");

        if ($confirmation !== $owner->email) {
            $this->error('Konfirmasi tidak cocok. Dibatalkan — tidak ada data yang diubah.');

            return self::FAILURE;
        }

        $result = OwnerDataReset::execute($ownerId);

        $this->info("Selesai. Data operasional owner #{$ownerId} ({$owner->name}) sudah dihapus permanen:");
        $this->table(['Tabel', 'Jumlah terhapus'], [
            ['clusters (area)', $result['clusters']],
            ['kiosks (kedai)', $result['kiosks']],
            ['suppliers', $result['suppliers']],
            ['products', $result['products']],
            ['product_variants', $result['product_variants']],
            ['procurement_batches', $result['procurement_batches']],
            ['trips (termasuk terarsip)', $result['trips']],
            ['deliveries', $result['deliveries']],
            ['delivery_origins', $result['delivery_origins']],
            ['settlements', $result['settlements']],
            ['kiosk_visits', $result['kiosk_visits']],
            ['commissions', $result['commissions']],
            ['fuel_logs', $result['fuel_logs']],
        ]);
        $this->line('Akun operator.migrasi: '.($result['migrasi_user_found'] ? 'terhapus.' : 'tidak ada, tidak ada yang dihapus.'));
        $this->line('Foto kedai (R2) berpotensi orphan: '.$result['photo_paths_count'].' file — hapus manual di R2 bila perlu.');
        $this->line("Akun owner #{$ownerId} ({$owner->email}) dan operator-operatornya TIDAK disentuh.");

        return self::SUCCESS;
    }
}

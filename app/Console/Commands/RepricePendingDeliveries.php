<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\User;
use App\Support\KioskPricing;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepricePendingDeliveries extends Command
{
    protected $signature = 'deliveries:reprice-pending {--owner= : Owner ID (required)} {--force : Apply changes; otherwise dry-run}';

    protected $description = 'Preview/apply current kiosk prices to consignment deliveries with no settlement of any status.';

    public function handle(): int
    {
        $ownerId = filter_var($this->option('owner'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $ownerId || ! User::where('role', 'owner')->whereKey($ownerId)->exists()) {
            $this->error('Wajib --owner=<id owner> yang valid.');

            return self::FAILURE;
        }
        $force = (bool) $this->option('force');
        $rows = [];
        // Serialize writers on each delivery, then re-check settlement existence while
        // locked. No Eloquent save: literal unit_price-only update, no updated_at change.
        foreach ($this->candidates($ownerId)->pluck('id') as $id) {
            $row = DB::transaction(function () use ($ownerId, $id, $force) {
                $delivery = $this->candidates($ownerId)->whereKey($id)->lockForUpdate()->first();
                if (! $delivery || $delivery->settlement()->lockForUpdate()->first(['id']) !== null) {
                    return null;
                }
                $delivery->load(['kiosk', 'productVariant']);
                if (! $delivery->kiosk || ! $delivery->productVariant) {
                    return null;
                }
                $oldPrice = (float) $delivery->unit_price;
                $newPrice = round((float) KioskPricing::pricePerPack($delivery->kiosk, $delivery->productVariant), 2);
                if (abs($oldPrice - $newPrice) < 0.005) {
                    return null;
                }
                $units = max(1, (int) ($delivery->units_per_pack_snapshot ?? 15));
                if ($force) {
                    DB::table('deliveries')->where('id', $delivery->id)->update(['unit_price' => $newPrice]);
                }

                return [
                    'id' => $delivery->id,
                    'kiosk' => $delivery->kiosk->name,
                    'date' => $delivery->created_at?->toDateString() ?? '—',
                    'qty' => (int) $delivery->qty_delivered,
                    'units' => $units,
                    'old_pack' => $oldPrice,
                    'new_pack' => $newPrice,
                    'old_piece' => $oldPrice / $units,
                    'new_piece' => $newPrice / $units,
                    'delta' => (int) $delivery->qty_delivered * ($newPrice - $oldPrice),
                ];
            });
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $money = fn ($value) => number_format($value, 2, ',', '.');
        $this->info(($force ? '[APPLY]' : '[DRY-RUN]').' '.count($rows).' delivery pending berubah harga.');
        $this->table(['ID', 'Kios', 'Tanggal', 'Mika', 'Isi Snapshot', 'Lama/Mika', 'Baru/Mika', 'Lama/Biji', 'Baru/Biji', 'Delta (Rp)'], array_map(fn ($r) => [
            $r['id'], $r['kiosk'], $r['date'], $r['qty'], $r['units'], $money($r['old_pack']), $money($r['new_pack']), $money($r['old_piece']), $money($r['new_piece']), $money($r['delta']),
        ], $rows));
        $this->line('TOTAL DELTA: Rp '.$money(array_sum(array_column($rows, 'delta'))));
        if (! $force) {
            $this->info('Dry-run saja — tidak ada perubahan data. Tambahkan --force untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    private function candidates(int $ownerId): Builder
    {
        return Delivery::query()->where('delivery_type', 'consignment')
            ->doesntHave('settlement')
            ->whereHas('trip', fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->whereHas('kiosk.cluster', fn (Builder $query) => $query->where('owner_id', $ownerId));
    }
}

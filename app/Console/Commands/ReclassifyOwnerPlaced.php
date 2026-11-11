<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReclassifyOwnerPlaced extends Command
{
    protected $signature = 'fee:reclassify-owner-placed {--owner= : Owner ID (required)} {--since= : Kiosk creation date YYYY-MM-DD (required)} {--force : Apply changes; otherwise dry-run}';

    protected $description = 'Reclassify manual opening balances as owner-placed stock with the current owner fee tariff.';

    public function handle(): int
    {
        $ownerId = filter_var($this->option('owner'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $owner = $ownerId ? User::where('role', 'owner')->find($ownerId) : null;
        $since = (string) $this->option('since');
        if (! $owner || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('Wajib --owner=<id owner> dan --since=YYYY-MM-DD yang valid.');

            return self::FAILURE;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $since, config('app.timezone'));
            if ($date->format('Y-m-d') !== $since) {
                throw new \InvalidArgumentException('Invalid date');
            }
        } catch (\Throwable) {
            $this->error('Tanggal --since tidak valid.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $rows = DB::transaction(function () use ($ownerId, $date, $force) {
            $ownerQuery = User::where('role', 'owner')->whereKey($ownerId);
            $owner = ($force ? $ownerQuery->lockForUpdate() : $ownerQuery)->firstOrFail();
            $rate = $owner->getAppFeePerPackValue();
            $query = $this->candidates($ownerId, $date->toDateTimeString());
            $deliveries = ($force ? $query->lockForUpdate() : $query)->get();
            $rows = [];
            foreach ($deliveries as $delivery) {
                if ($force) {
                    // Fee provenance only: leave billed prices, settlement and timestamps intact.
                    DB::table('deliveries')->where('id', $delivery->id)->update([
                        'source_type' => 'owner_placed',
                        'app_fee_per_pack_snapshot' => $rate,
                    ]);
                }
                $rows[] = [
                    $delivery->kiosk_name,
                    Carbon::parse($delivery->created_at, config('app.timezone'))->toDateString(),
                    (int) $delivery->qty_delivered,
                    (float) $delivery->qty_delivered * $rate,
                ];
            }

            return $rows;
        });
        $totalQty = array_sum(array_column($rows, 2));
        $totalFee = array_sum(array_column($rows, 3));
        $this->info(($force ? '[APPLY]' : '[DRY-RUN]').' '.count($rows).' delivery saldo awal manual. Tarif owner SAAT INI dibekukan per delivery.');
        $this->table(['Kios', 'Tanggal', 'Mika', 'Tambahan Fee (Rp)'], array_map(fn ($row) => [$row[0], $row[1], $row[2], number_format($row[3], 2, ',', '.')], $rows));
        $this->line('TOTAL: '.$totalQty.' mika; tambahan fee Rp '.number_format($totalFee, 2, ',', '.'));
        if (! $force) {
            $this->info('Dry-run saja — tidak ada perubahan data. Tambahkan --force untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    private function candidates(int $ownerId, string $since): Builder
    {
        return DB::table('deliveries as d')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->join('users as op', 'op.id', '=', 't.operator_id')
            ->join('kiosks as k', 'k.id', '=', 'd.kiosk_id')
            ->join('clusters as c', 'c.id', '=', 'k.cluster_id')
            ->where('t.owner_id', $ownerId)->where('c.owner_id', $ownerId)
            ->whereNull('t.deleted_at')
            ->where('op.email', 'like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%')
            ->where('t.ended_reason', 'Migrasi saldo awal (kios lama)')
            ->where('d.notes', 'Saldo awal (kios lama) — titipan berjalan sebelum masuk sistem.')
            ->whereIn('d.source_type', ['new_procurement', 'migration'])
            ->where('d.delivery_type', 'consignment')
            ->where('k.created_at', '>=', $since)
            // No settlement filter: this operation changes fee only, including settled rows.
            ->select('d.*', 'k.name as kiosk_name')->orderBy('d.id');
    }
}

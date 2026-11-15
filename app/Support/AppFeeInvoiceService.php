<?php

namespace App\Support;

use App\Models\AppFeeInvoice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aturan bisnis invoice fee aplikasi (super admin saja — pemanggil WAJIB sudah
 * menggerbang role; service ini tidak mengecek auth).
 *
 * SUMBER ANGKA TUNGGAL: AppFeeCalculator::summaryByOwnerAndDate() — fungsi yang SAMA
 * dengan widget "Rincian Fee Aplikasi Harian". Tidak ada rumus fee baru di sini.
 *
 * Nomor: CQ-INV-YYYYMM-NNN, YYYYMM = bulan PERIODE tagihan (bukan bulan terbit;
 * keputusan owner), NNN urut per periode lintas owner, nomor void TIDAK dipakai ulang.
 */
class AppFeeInvoiceService
{
    public static function validPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    /** Periode sudah lewat penuh menurut zona waktu app (today() = config app.timezone). */
    public static function periodHasEnded(string $period): bool
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return Carbon::create($y, $m, 1)->endOfMonth()->startOfDay()->lt(today());
    }

    /** Periode tidak di masa depan (draft boleh untuk bulan berjalan & sebelumnya). */
    public static function periodNotFuture(string $period): bool
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return Carbon::create($y, $m, 1)->startOfDay()->lte(today());
    }

    /**
     * @return array{lines: array<int, array{date: string, mika: int, fee: float, min_rate: float, max_rate: float}>, total_mika: int, total_fee: float}
     */
    public static function compute(User $owner, string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        $lines = collect(AppFeeCalculator::summaryByOwnerAndDate($y, $m))
            ->where('owner_id', $owner->id)
            ->filter(fn (array $r): bool => $r['mika_kena_fee'] > 0) // hari 0 mika tak ditampilkan
            ->map(fn (array $r): array => [
                'date' => $r['date'],
                'mika' => $r['mika_kena_fee'],
                'fee' => (float) $r['fee'],
                'min_rate' => $r['min_rate'],
                'max_rate' => $r['max_rate'],
            ])
            ->values();

        return [
            'lines' => $lines->all(),
            'total_mika' => (int) $lines->sum('mika'),
            'total_fee' => (float) $lines->sum('fee'),
        ];
    }

    public static function activeFor(int $ownerId, string $period): ?AppFeeInvoice
    {
        return AppFeeInvoice::active()->where('owner_id', $ownerId)->where('period', $period)->latest('id')->first();
    }

    /**
     * Terbitkan invoice. Satu invoice aktif per owner+periode dijaga di sini:
     * transaksi + lock baris owner (bukan unique index — lihat migrasi).
     * Dua penerbitan owner BERBEDA bisa berebut nomor → unique(number) menolak yang
     * kalah, lalu diulang dengan nomor baru (maks 5x).
     */
    public static function issue(User $owner, string $period, User $by): AppFeeInvoice
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): AppFeeInvoice => self::issueOnce($owner, $period, $by));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    private static function issueOnce(User $owner, string $period, User $by): AppFeeInvoice
    {
        if (! self::validPeriod($period)) {
            throw new InvoiceException('Periode tidak valid.');
        }
        if (! self::periodHasEnded($period)) {
            throw new InvoiceException('Invoice hanya bisa diterbitkan untuk bulan yang sudah lewat. Untuk bulan berjalan gunakan draft.');
        }

        // Kunci baris owner: penerbitan paralel owner+periode yang sama jadi berurutan.
        $locked = User::query()->whereKey($owner->id)->where('role', 'owner')->lockForUpdate()->first();
        if (! $locked) {
            throw new InvoiceException('Owner tidak ditemukan.');
        }

        if ($existing = self::activeFor($locked->id, $period)) {
            throw new InvoiceException("Invoice {$existing->number} untuk periode ini sudah terbit. Batalkan dulu bila ingin menerbitkan ulang.");
        }

        $data = self::compute($locked, $period);
        if ($data['total_mika'] <= 0 || $data['total_fee'] <= 0) {
            throw new InvoiceException('Fee owner ini di periode tersebut Rp 0 — tidak perlu invoice.');
        }

        $issuer = InvoiceSettings::get();

        return AppFeeInvoice::create([
            'owner_id' => $locked->id,
            'period' => $period,
            'number' => self::nextNumber($period),
            'status' => AppFeeInvoice::STATUS_ISSUED,
            'issued_at' => now(),
            'due_date' => today()->addDays($issuer['due_days']),
            'total_mika' => $data['total_mika'],
            'total_fee' => $data['total_fee'],
            'lines' => $data['lines'],
            'issuer' => $issuer,
            'billed_to' => [
                'business' => $locked->business_name ?: $locked->name,
                'owner_name' => $locked->name,
            ],
            'created_by' => $by->id,
        ]);
    }

    private static function nextNumber(string $period): string
    {
        $prefix = 'CQ-INV-'.str_replace('-', '', $period).'-';

        $max = AppFeeInvoice::query()
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $n): int => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function void(AppFeeInvoice $invoice, string $reason): AppFeeInvoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvoiceException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($invoice, $reason): AppFeeInvoice {
            $fresh = AppFeeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($fresh->status === AppFeeInvoice::STATUS_VOID) {
                throw new InvoiceException('Invoice ini sudah dibatalkan.');
            }

            $fresh->update([
                'status' => AppFeeInvoice::STATUS_VOID,
                'voided_at' => now(),
                'void_reason' => mb_substr($reason, 0, 500),
            ]);

            return $fresh;
        });
    }

    public static function markPaid(AppFeeInvoice $invoice, Carbon $paidAt, ?string $note): AppFeeInvoice
    {
        if ($paidAt->startOfDay()->gt(today())) {
            throw new InvoiceException('Tanggal bayar tidak boleh di masa depan.');
        }

        return DB::transaction(function () use ($invoice, $paidAt, $note): AppFeeInvoice {
            $fresh = AppFeeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($fresh->status !== AppFeeInvoice::STATUS_ISSUED) {
                throw new InvoiceException('Hanya invoice berstatus terbit yang bisa ditandai lunas.');
            }

            $note = trim((string) $note);
            $fresh->update([
                'status' => AppFeeInvoice::STATUS_PAID,
                'paid_at' => $paidAt->toDateString(),
                'paid_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            ]);

            return $fresh;
        });
    }

    /** Batalkan status lunas (salah klik) → kembali "terbit". */
    public static function unmarkPaid(AppFeeInvoice $invoice): AppFeeInvoice
    {
        return DB::transaction(function () use ($invoice): AppFeeInvoice {
            $fresh = AppFeeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($fresh->status !== AppFeeInvoice::STATUS_PAID) {
                throw new InvoiceException('Invoice ini belum ditandai lunas.');
            }

            $fresh->update([
                'status' => AppFeeInvoice::STATUS_ISSUED,
                'paid_at' => null,
                'paid_note' => null,
            ]);

            return $fresh;
        });
    }
}

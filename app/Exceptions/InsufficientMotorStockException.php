<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — dilempar App\Support\MotorStockGuard
 * saat sebuah aksi (titip/jual/koreksi) mau mengeluarkan mika lebih dari stok yang
 * tersisa di motor. Pesan EXACT sesuai brief, dipakai di semua tempat yang catch ini.
 */
class InsufficientMotorStockException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Stok di motor tinggal {$available} mika, kamu mau menaruh {$requested} mika. Kalau memang bawa lebih, tekan ➕ Tambah Stok di Motor dulu."
        );
    }

    /** Pesan operator EXACT (brief) — alias semantik untuk dipakai di addError()/flash. */
    public function operatorMessage(): string
    {
        return $this->getMessage();
    }
}

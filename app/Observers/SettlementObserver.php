<?php

namespace App\Observers;

use App\Models\Settlement;
use InvalidArgumentException;

class SettlementObserver
{
    public function creating(Settlement $settlement): void
    {
        $this->validateQtySum($settlement);
    }

    public function updating(Settlement $settlement): void
    {
        $this->validateQtySum($settlement);
    }

    public function saving(Settlement $settlement): void
    {
        if ($settlement->amount_paid >= $settlement->amount_due) {
            $settlement->status = 'paid';
            $settlement->paid_at = $settlement->paid_at ?? now();
        } else {
            $settlement->status = 'pending';
            $settlement->paid_at = null;
        }
    }

    private function validateQtySum(Settlement $settlement): void
    {
        $delivery = $settlement->delivery()->first();

        if (! $delivery) {
            return;
        }

        $actualSum = (int) $settlement->qty_sold
            + (int) $settlement->qty_returned_fresh
            + (int) $settlement->qty_returned_expired;

        $expected = (int) $delivery->qty_delivered;

        if ($actualSum !== $expected) {
            throw new InvalidArgumentException(
                "Settlement qty mismatch for delivery #{$delivery->id}: sum {$actualSum}, expected {$expected}"
            );
        }
    }
}

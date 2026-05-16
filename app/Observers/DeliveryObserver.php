<?php

namespace App\Observers;

use App\Models\Delivery;
use InvalidArgumentException;

class DeliveryObserver
{
    public function creating(Delivery $delivery): void
    {
        $this->validateSourceTypeConsistency($delivery);
    }

    public function updating(Delivery $delivery): void
    {
        $this->validateSourceTypeConsistency($delivery);
    }

    private function validateSourceTypeConsistency(Delivery $delivery): void
    {
        $id = $delivery->id ?? 'new';

        match ($delivery->source_type) {
            'new_procurement' => $this->requireProcurementBatch($delivery, $id),
            'fresh_return_redeploy' => $this->requireOriginSettlement($delivery, $id),
            default => null,
        };
    }

    private function requireProcurementBatch(Delivery $delivery, string|int $id): void
    {
        if (is_null($delivery->procurement_batch_id)) {
            throw new InvalidArgumentException(
                "Delivery source_type=new_procurement requires procurement_batch_id (delivery id: {$id})"
            );
        }
    }

    private function requireOriginSettlement(Delivery $delivery, string|int $id): void
    {
        if (is_null($delivery->origin_settlement_id)) {
            throw new InvalidArgumentException(
                "Delivery source_type=fresh_return_redeploy requires origin_settlement_id (delivery id: {$id})"
            );
        }
    }
}

<?php

namespace App\Observers;

use App\Models\Delivery;
use InvalidArgumentException;

class DeliveryObserver
{
    public function creating(Delivery $delivery): void
    {
        $this->validateFields($delivery);
    }

    public function updating(Delivery $delivery): void
    {
        $this->validateFields($delivery);
    }

    public function updated(Delivery $delivery): void
    {
        $delivery->validateOrigins();
    }

    private function validateFields(Delivery $delivery): void
    {
        $id = $delivery->id ?? 'new';

        match ($delivery->source_type) {
            'new_procurement' => $this->requireProcurementBatch($delivery, $id),
            'fresh_return_redeploy' => $this->forbidProcurementBatch($delivery, $id),
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

    private function forbidProcurementBatch(Delivery $delivery, string|int $id): void
    {
        if (! is_null($delivery->procurement_batch_id)) {
            throw new InvalidArgumentException(
                "Delivery source_type=fresh_return_redeploy must not have procurement_batch_id (delivery id: {$id})"
            );
        }
    }
}

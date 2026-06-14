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

    public function created(Delivery $delivery): void
    {
        $this->maybeSetFirstTitipDate($delivery);
    }

    /**
     * Tandai "mulai dititipi" saat titipan konsinyasi pertama tiba di kios yang
     * belum punya first_titip_date (mis. kios hasil bulk-import yang tak lewat
     * alur CreateKiosk). cash_sale / bs_redistribution bukan awal titipan →
     * tidak menyetel. Tidak meng-override tanggal yang sudah ada.
     */
    private function maybeSetFirstTitipDate(Delivery $delivery): void
    {
        if ($delivery->delivery_type !== 'consignment') {
            return;
        }

        $kiosk = $delivery->kiosk;
        if (! $kiosk || $kiosk->first_titip_date !== null) {
            return;
        }

        $kiosk->first_titip_date = ($delivery->created_at ?? now())->toDateString();
        $kiosk->save();
    }

    private function validateFields(Delivery $delivery): void
    {
        $id = $delivery->id ?? 'new';

        match ($delivery->source_type) {
            // new_procurement: procurement_batch_id boleh null (operasional bebas,
            // batch = catatan owner saja, tidak wajib di-link operator).
            'fresh_return_redeploy' => $this->forbidProcurementBatch($delivery, $id),
            default => null,
        };
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

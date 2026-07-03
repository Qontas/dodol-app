<?php

namespace App\Models\Scopes;

use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global read scope multi-tenant "secure-by-default".
 *
 * Setiap query baca model tenant otomatis ter-filter ke owner yang aktif, jadi
 * LUPA menambah where owner_id = data ke-filter (aman), BUKAN bocor. Ini menutup
 * akar kerapuhan: isolasi tak lagi bergantung developer ingat scope manual.
 *
 * Diterapkan pada:
 * - Model Level-1 (punya kolom owner_id langsung): clusters, suppliers, products,
 *   procurement_batches, trips → lewat trait BelongsToOwner.
 * - Kiosk (Level-2, owner via cluster) → lewat whereHas('cluster', owner_id).
 *
 * Resolver "owner aktif" (activeOwnerId) menentukan konteks; null = BYPASS
 * (tak di-filter) untuk super admin & konteks tanpa auth (seeder, factory, CLI,
 * queue, migration) — konsisten dengan guard 'creating' di BelongsToOwner.
 *
 * Query lintas-owner yang DISENGAJA (mis. jika suatu saat perlu) memakai
 * ->withoutGlobalScope(OwnerScope::class) di titik spesifik.
 */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ownerId = static::activeOwnerId();

        if ($ownerId === null) {
            return; // BYPASS: super admin / tanpa auth / operator tanpa owner_id (legacy)
        }

        if ($model instanceof Kiosk) {
            // Kiosk tak punya kolom owner_id → owner lewat rantai cluster.
            $builder->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId));

            return;
        }

        $builder->where($model->getTable() . '.owner_id', $ownerId);
    }

    /**
     * Owner yang sedang aktif untuk konteks request ini, atau null bila query
     * TIDAK boleh di-filter (lihat aturan bypass di dokumentasi kelas).
     */
    public static function activeOwnerId(): ?int
    {
        if (! auth()->check()) {
            return null; // seeder / factory / CLI / queue / migration
        }

        $user = auth()->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return null; // super admin lihat semua
        }

        if ($user->isOwner()) {
            return $user->id;
        }

        if ($user->isOperator()) {
            return $user->owner_id; // bisa null (operator legacy) → bypass, konsisten pola lama
        }

        return null;
    }
}

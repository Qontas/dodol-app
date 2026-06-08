# Brief: Batch Stok Tracking

## KONTEKS

73 PASS. Tambah fitur batch stok tracking.
Sisa stok per batch = qty_packs - sum(deliveries.qty_delivered) per batch.
Tampil di: (A) owner dashboard widget + (B) Filament ProcurementBatchResource.

## BUSINESS RULES (LOCKED)

- Sisa stok batch = qty_packs - sum(qty_delivered dari deliveries yang link ke batch ini)
- Batch "habis" = sisa stok <= 0
- Batch "hampir habis" = sisa stok <= 10 mika (warning)
- Semua query di-scope per owner (multi-tenant)
- Super admin lihat semua batch semua owner

## SCHEMA (verified)

procurement_batches: id, owner_id, product_variant_id, supplier_id, purchase_date,
qty_kg_raw, qty_packs, cost_raw_material, cost_packing, cost_packaging, cost_other,
total_cost, cost_per_pack, price_per_kg_raw, expiry_date, notes, created_at, updated_at

deliveries: id, kiosk_id, trip_id, product_variant_id, procurement_batch_id,
source_type, delivery_type, qty_delivered, ...

procurement_batch_id di deliveries = NULLABLE (operasional bebas).
Hanya deliveries dengan procurement_batch_id != null yang dihitung ke stok batch.

## PERUBAHAN MODEL

### ProcurementBatch model — tambah accessor stok tersisa:

```php
/**
 * Stok tersisa = qty_packs - total mika yang sudah di-drop dari batch ini.
 * Hanya delivery yang ter-link (procurement_batch_id != null) yang dihitung.
 */
public function getStokTersisaAttribute(): int
{
    $terpakai = $this->deliveries()->sum('qty_delivered');
    return max(0, (int) $this->qty_packs - (int) $terpakai);
}

public function getIsHabisAttribute(): bool
{
    return $this->stok_tersisa <= 0;
}

public function getIsHampisHabisAttribute(): bool
{
    return $this->stok_tersisa > 0 && $this->stok_tersisa <= 10;
}

public function deliveries(): HasMany
{
    // Pastikan relasi deliveries ada di model ini
    return $this->hasMany(Delivery::class);
}
```

Tambah ke $appends kalau perlu agar accessible di views.

### Cek apakah relasi deliveries() sudah ada di ProcurementBatch.

Kalau belum: tambahkan.

## PERUBAHAN FILAMENT

### ProcurementBatchResource — tambah kolom stok:

Di table():

```php
Tables\Columns\TextColumn::make('stok_tersisa')
    ->label('Stok Tersisa')
    ->getStateUsing(fn ($record) => $record->stok_tersisa.' mika')
    ->badge()
    ->color(fn ($record) => match(true) {
        $record->is_habis => 'danger',
        $record->is_hampis_habis => 'warning',
        default => 'success',
    })
    ->sortable(false),

Tables\Columns\TextColumn::make('qty_packs')
    ->label('Total Mika')
    ->suffix(' mika')
    ->sortable(),
```

Tambah juga summary di bawah tabel (pakai Filament footer atau summary widget):

- Total stok tersisa semua batch aktif owner ini

## OWNER DASHBOARD WIDGET

### OwnerDashboardController — tambah query stok:

```php
// Widget stok batch
$batchStok = ProcurementBatch::where('owner_id', $ownerId)
    ->with('deliveries')
    ->orderBy('created_at')
    ->get()
    ->map(fn ($batch) => [
        'id' => $batch->id,
        'purchase_date' => $batch->purchase_date,
        'qty_packs' => $batch->qty_packs,
        'stok_tersisa' => $batch->stok_tersisa,
        'is_habis' => $batch->is_habis,
        'is_hampis_habis' => $batch->is_hampis_habis,
        'cost_per_pack' => $batch->cost_per_pack,
    ]);

$totalStokTersisa = $batchStok->sum('stok_tersisa');
```

Pass ke view: compact(..., 'batchStok', 'totalStokTersisa')

### owner/dashboard.blade.php — tambah widget stok:

Tambah setelah widget outstanding (3 widget existing):

```html
{{-- Widget Total Stok Tersisa --}}
<div class="bg-white rounded-lg border border-slate-200 p-5">
    <div class="flex items-center gap-2 text-slate-500">
        <svg class="h-5 w-5 text-blue-600" ...> <!-- icon box/inventory -->
        <span class="text-xs uppercase tracking-wide font-medium">Total Stok</span>
    </div>
    <div class="mt-3 text-2xl font-bold text-blue-600">
        {{ $totalStokTersisa }} mika
    </div>
</div>
```

Tambah tabel batch stok di bawah widget cards:

```html
{{-- Tabel Stok Per Batch --}} @if($batchStok->count() > 0)
<div class="bg-white rounded-lg border border-slate-200 mt-6">
    <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-900">Stok Per Batch</h3>
    </div>
    <div class="divide-y divide-slate-100">
        @foreach($batchStok as $batch)
        <div class="px-5 py-3 flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-slate-900">
                    Batch {{
                    \Carbon\Carbon::parse($batch['purchase_date'])->format('d M
                    Y') }}
                </p>
                <p class="text-xs text-slate-500">
                    Total: {{ $batch['qty_packs'] }} mika
                </p>
            </div>
            <div class="text-right">
                <span
                    class="text-sm font-bold
                    {{ $batch['is_habis'] ? 'text-red-600' :
                       ($batch['is_hampis_habis'] ? 'text-amber-600' : 'text-green-600') }}"
                >
                    {{ $batch['stok_tersisa'] }} mika
                </span>
                @if($batch['is_habis'])
                <p class="text-xs text-red-500">Habis</p>
                @elseif($batch['is_hampis_habis'])
                <p class="text-xs text-amber-500">Hampir Habis</p>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif
```

## STEP EKSEKUSI

1. Cek ProcurementBatch model (relasi deliveries + existing methods)
2. Tambah accessor stok_tersisa, is_habis, is_hampis_habis ke ProcurementBatch
3. Update ProcurementBatchResource table (kolom stok tersisa + badge color)
4. Update OwnerDashboardController (query batchStok + totalStokTersisa)
5. Update owner/dashboard.blade.php (widget total stok + tabel per batch)
6. php artisan test --compact (target 73+ PASS)
7. Commit:
   git add .
   git commit -m "feat(owner): batch stok tracking — sisa mika per batch di dashboard + Filament"
   git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. ProcurementBatch tidak punya relasi deliveries
2. qty_packs nullable atau tipe berbeda dari int
3. Test turun dari 73 PASS
4. OwnerDashboardController sudah terlalu panjang (pertimbangkan extract ke service)

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

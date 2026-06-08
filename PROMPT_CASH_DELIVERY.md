# Brief: Cash Delivery — Kios Cash Only + Drop Extra Cash

## KONTEKS

86 PASS. Tambah 2 skenario delivery baru:

1. Kios Cash Only — tidak pakai konsinyasi, langsung bayar cash
2. Drop Extra Cash — drop lebih dari default, kelebihan bayar cash langsung

## BUSINESS RULES (LOCKED)

### Kios Cash Only (is_cash_only = true)

- Setiap drop = langsung cash, tidak ada modal dodol di kios
- visit_action = 'cash_sale' (tambah enum baru)
- Settlement dibuat langsung saat visit (status = 'paid', amount_paid = amount_due)
- Tidak ada pendingDelivery di kunjungan berikutnya
- default_qty_mika tetap sebagai suggestion, tapi operator bisa input bebas

### Drop Extra Cash (drop > default_qty_mika)

- Sistem deteksi otomatis: kalau dropBaru > kiosk.default_qty_mika
- Qty konsinyasi = default_qty_mika (modal dodol, bayar nanti)
- Qty cash = dropBaru - default_qty_mika (bayar cash langsung)
- Buat 2 delivery terpisah:
    1. Delivery konsinyasi: qty = default_qty_mika, delivery_type = 'consignment'
    2. Delivery cash: qty = extra_qty, delivery_type = 'cash_sale'
- Settlement untuk delivery cash dibuat langsung (status = 'paid')
- Settlement untuk delivery konsinyasi = pending (bayar nanti)
- Di modal visit: tampilkan info "X mika konsinyasi + Y mika cash"

## SCHEMA PERUBAHAN

### Migration 1: tambah is_cash_only ke kiosks

```php
Schema::table('kiosks', function (Blueprint $table) {
    $table->boolean('is_cash_only')->default(false)->after('is_active');
});
```

### Migration 2: tambah 'cash_sale' ke enum visit_action di kiosk_visits

Cek tipe kolom visit_action (enum atau string):
php artisan tinker --execute="echo DB::select('SHOW COLUMNS FROM kiosk_visits LIKE visit_action')[0]->Type;"

Kalau enum: ALTER TABLE kiosk_visits MODIFY visit_action ENUM('drop_and_settle','drop_only','settle_only','check_only','cash_sale')
Kalau string: tidak perlu migration, langsung pakai

### Migration 3: tambah 'cash_sale' ke enum delivery_type di deliveries

Cek tipe kolom delivery_type:
php artisan tinker --execute="echo DB::select('SHOW COLUMNS FROM deliveries LIKE delivery_type')[0]->Type;"

## PERUBAHAN MODEL

### Kiosk model:

Tambah is_cash_only ke $fillable + $casts:

```php
'is_cash_only' => 'boolean',
```

## PERUBAHAN FILAMENT

### KioskResource form:

Tambah toggle is_cash_only di section "Foto & Konfigurasi":

```php
Forms\Components\Toggle::make('is_cash_only')
    ->label('Kios Cash Only')
    ->helperText('Aktifkan jika kios ini selalu bayar cash langsung (tidak ada konsinyasi)')
    ->default(false),
```

### KioskResource table:

Tambah kolom badge is_cash_only:

```php
Tables\Columns\IconColumn::make('is_cash_only')
    ->label('Cash Only')
    ->boolean()
    ->toggleable(isToggledHiddenByDefault: true),
```

## PERUBAHAN ACTIVETRIIP.PHP (saveVisit)

### Properties baru:

public bool $isCashOnly = false; // dari kios yang dipilih

### Update openVisitModal():

Setelah load selectedKiosk:
$this->isCashOnly = (bool) $this->selectedKiosk->is_cash_only;

### Update resolveVisitAction():

```php
private function resolveVisitAction(): string
{
    if ($this->isCashOnly) {
        return 'cash_sale';
    }

    $drop = (int) $this->dropBaru;
    $hasPending = (bool) $this->pendingDelivery;

    if ($hasPending && $drop > 0) return 'drop_and_settle';
    if (!$hasPending && $drop > 0) return 'drop_only';
    if ($hasPending && $drop === 0) return 'settle_only';
    return 'check_only';
}
```

### Update saveVisit() — tambah 2 skenario baru:

#### Skenario Cash Only (isCashOnly = true):

```php
if ($this->isCashOnly) {
    DB::transaction(function () {
        // 1. Buat delivery cash_sale
        $variant = $this->resolveActiveVariant();
        $delivery = Delivery::create([
            'kiosk_id' => $this->selectedKiosk->id,
            'trip_id' => $this->trip->id,
            'product_variant_id' => $variant->id,
            'procurement_batch_id' => null,
            'source_type' => 'new_procurement',
            'delivery_type' => 'cash_sale',
            'qty_delivered' => (int) $this->dropBaru,
            'unit_price' => $variant->sale_price_per_pack,
            'cost_snapshot' => null,
        ]);

        // 2. Settlement langsung lunas
        $totalBiji = (int) $this->dropBaru * self::BIJI_PER_MIKA;
        $amountDue = $totalBiji * self::HARGA_PER_BIJI;
        Settlement::create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => $totalBiji,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => $amountDue,
            'amount_paid' => $amountDue, // langsung lunas
        ]);

        // 3. KioskVisit
        KioskVisit::create([
            'trip_id' => $this->trip->id,
            'kiosk_id' => $this->selectedKiosk->id,
            'visited_at' => now(),
            'visit_action' => 'cash_sale',
            'new_delivery_id' => $delivery->id,
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);
    });

    $this->loadKiosks();
    $this->closeVisitModal();
    session()->flash('visit_saved', 'Kunjungan cash berhasil disimpan.');
    return;
}
```

#### Skenario Drop Extra Cash (drop > default_qty_mika, bukan cash_only):

Deteksi setelah validasi normal, sebelum DB::transaction existing:

```php
$defaultQty = (int) ($this->selectedKiosk->default_qty_mika ?? 0);
$extraQty = max(0, $drop - $defaultQty);
$konsinyasiQty = $defaultQty > 0 && $extraQty > 0 ? $defaultQty : $drop;
$hasCashExtra = $extraQty > 0 && $defaultQty > 0;
```

Di dalam DB::transaction existing, kalau $hasCashExtra = true:
Buat 2 delivery:

1. Delivery konsinyasi: qty = $konsinyasiQty, delivery_type = 'consignment'
2. Delivery cash extra: qty = $extraQty, delivery_type = 'cash_sale'
    - Settlement langsung lunas untuk delivery cash extra

new_delivery_id di KioskVisit = delivery konsinyasi (yang jadi pendingDelivery berikutnya)

## VIEW UPDATE (active-trip.blade.php)

### Di modal visit:

1. Tampilkan badge "CASH ONLY" kalau isCashOnly = true
2. Kalau dropBaru > default_qty_mika (dan bukan cash_only):
   Tampilkan info: "{{ $dropBaru - $selectedKiosk->default_qty_mika }} mika extra (cash langsung)"
3. Label aksi: cash_sale → "Penjualan Cash"

### Update switch label aksi:

@case('cash_sale') Penjualan Cash @break

## STEP EKSEKUSI

1. Cek tipe kolom visit_action + delivery_type (enum atau string)
2. Migration: is_cash_only ke kiosks
3. Migration: tambah cash_sale ke enum (kalau enum)
4. Update Kiosk model ($fillable + cast)
5. Update KioskResource (form toggle + table column)
6. Update ActiveTrip.php (property + resolveVisitAction + saveVisit 2 skenario baru)
7. Update active-trip.blade.php (badge cash only + info extra cash)
8. php artisan test --compact (target 86+ PASS)
9. Commit:
   git add .
   git commit -m "feat(operator): cash delivery — kios cash only + drop extra cash"
   git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. visit_action atau delivery_type bukan enum (affects migration approach)
2. SettlementObserver reject settlement untuk cash_sale delivery
3. Test turun dari 86 PASS
4. resolveVisitAction conflict dengan existing 4 aksi

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

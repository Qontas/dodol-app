# Brief: Cascade Delete Kiosk

## KONTEKS

45 PASS. Kios tidak bisa dihapus dari Filament admin karena foreign key constraint
dari tabel deliveries, kiosk_visits, dll. Perlu cascade delete.

## GOAL

Saat owner hapus kios dari /admin/kiosks:

- Auto hapus: deliveries, kiosk_visits, settlements, delivery_origins terkait
- TIDAK hapus: clusters, suppliers, trips (trips tetap ada, kiosk_visit yang dihapus)

## APPROACH: Override handleRecordDeletion di KioskResource

Filament punya method handleRecordDeletion() di DeleteAction yang bisa di-override.
Alternatif lebih clean: tambah cascadeOnDelete() di migrations ATAU override di model boot.

PILIH: Override di KioskResource Pages/EditKiosk.php + Pages/ListKiosks.php via
custom DeleteAction — TAPI cara paling clean = tambah cascade di migration.

## STEP EKSEKUSI

### Step 1: Cek existing migration kiosks + deliveries

php artisan tinker --execute="
\$cols = \Schema::getColumnListing('kiosks');
echo 'KIOSKS: '.implode(',', \$cols).PHP_EOL;
"

Cek foreign key di deliveries + kiosk_visits yang reference kiosks.id:
Lihat file migration: database/migrations/2026_05_16_120009_create_deliveries_table.php
Lihat file migration: database/migrations/2026_05_16_120011_create_kiosk_visits_table.php

### Step 2: Buat migration cascade delete

Buat migration baru: add_cascade_delete_to_kiosk_relations

Migration ini DROP foreign key lama dan re-add dengan onDelete('cascade'):

Tabel yang perlu cascade dari kiosks.id:

1. deliveries.kiosk_id
2. kiosk_visits.kiosk_id

Tabel yang cascade dari deliveries.id (sudah atau perlu): 3. settlements.delivery_id 4. delivery_origins.delivery_id

Tabel yang cascade dari kiosk_visits.settled_delivery_id + new_delivery_id: 5. Cek apakah sudah cascade

Pattern migration:
Schema::table('deliveries', function (Blueprint $table) {
$table->dropForeign(['kiosk_id']);
$table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('cascade');
});

Schema::table('kiosk_visits', function (Blueprint $table) {
$table->dropForeign(['kiosk_id']);
$table->foreign('kiosk_id')->references('id')->on('kiosks')->onDelete('cascade');
});

Schema::table('settlements', function (Blueprint $table) {
$table->dropForeign(['delivery_id']);
$table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
});

Schema::table('delivery_origins', function (Blueprint $table) {
$table->dropForeign(['delivery_id']);
$table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
});

Tambah down() method yang reverse cascade ke restrict.

### Step 3: php artisan migrate

### Step 4: php artisan test --compact (target 45+ PASS)

### Step 5: Verify cascade kerja

php artisan tinker --execute="
// Buat kios test
\$kiosk = App\Models\Kiosk::create([
'name' => 'Test Cascade',
'owner_name' => 'Test',
'cluster_id' => App\Models\Cluster::first()->id,
'default_qty_mika' => 5,
'is_active' => true,
'latitude' => 3.5952,
'longitude' => 98.6722,
]);
echo 'Kiosk created: '.\$kiosk->id.PHP_EOL;

// Hapus kios
\$kiosk->delete();
echo 'Kiosk deleted OK'.PHP_EOL;
"

Kalau tidak error = cascade working.

### Step 6: Commit

git add database/migrations/
git commit -m "fix(db): cascade delete kiosk — auto hapus deliveries, visits, settlements"

## STOP POINTS

1. Foreign key name berbeda dari yang diasumsikan (dropForeign gagal)
2. Test turun dari 45 PASS
3. Migration error karena constraint order

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

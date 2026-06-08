# Brief: Multi-Tenant Architecture

## KONTEKS

58 PASS. Dodol-app saat ini single-tenant (1 owner).
Tugas: refactor ke multi-tenant — setiap owner punya data terpisah.

## HIERARCHY (LOCKED)

Super Admin (Ismi) → pantau semua owner, buat akun owner baru
Owner → lihat + manage data bisnis sendiri saja
Operator → terikat ke 1 owner, akses operator UI saja

## ROLES (existing + tambahan)

Existing roles di users table: 'owner', 'operator'
Tambah role baru: 'super_admin'

Super Admin:

- Akses Filament admin SEMUA data (tanpa filter owner)
- Bisa CRUD user (owner + operator)
- Bisa pantau semua trip, settlement, laporan semua owner

Owner:

- Akses Filament admin data SENDIRI saja (filter by owner_id)
- Bisa buat/hapus operator untuk bisnis sendiri
- TIDAK bisa lihat data owner lain

Operator:

- Akses operator UI (/operator/\*)
- Terikat ke owner_id tertentu
- TIDAK bisa akses Filament admin

## SKEMA PERUBAHAN DATABASE

### Tabel yang perlu owner_id (migration baru):

LEVEL 1 (root — langsung punya owner_id):

- clusters → tambah owner_id (FK ke users)
- suppliers → tambah owner_id
- products → tambah owner_id
- procurement_batches → tambah owner_id
- trips → tambah owner_id (sudah ada operator_id, tambah owner_id)

LEVEL 2 (child — owner diketahui lewat parent, TIDAK perlu owner_id):

- kiosks → owner diketahui lewat cluster.owner_id
- product_variants → owner lewat product.owner_id
- deliveries → owner lewat trip.owner_id
- settlements → owner lewat delivery → trip.owner_id
- kiosk_visits → owner lewat trip.owner_id
- commissions → owner lewat trip.owner_id

### Migration pattern untuk setiap tabel Level 1:

```php
Schema::table('clusters', function (Blueprint $table) {
    $table->foreignId('owner_id')->nullable()->constrained('users')->onDelete('cascade');
});
```

Nullable dulu untuk backward compatibility (data existing tidak rusak).

### Update data existing:

Setelah migration, set owner_id = user_id dari owner existing:

```php
$ownerId = \App\Models\User::where('role', 'owner')->first()->id;
\DB::table('clusters')->whereNull('owner_id')->update(['owner_id' => $ownerId]);
// repeat untuk suppliers, products, procurement_batches, trips
```

## PERUBAHAN MODEL

### User model:

Tambah helper methods:

```php
public function isSuperAdmin(): bool { return $this->role === 'super_admin'; }
public function isOwner(): bool { return $this->role === 'owner'; }
public function isOperator(): bool { return $this->role === 'operator'; }
```

### Semua model Level 1 (Cluster, Supplier, Product, ProcurementBatch, Trip):

Tambah:

```php
protected $fillable = [..., 'owner_id'];

// Global scope untuk auto-filter by owner (HANYA aktif untuk owner, bukan super_admin)
// Implementasi via scope di controller/resource, bukan global scope di model
// (global scope di model akan break super_admin view)
```

### Kiosk model:

Tambah accessor untuk owner_id via cluster:

```php
public function getOwnerIdAttribute(): ?int
{
    return $this->cluster?->owner_id;
}
```

## PERUBAHAN FILAMENT

### FilamentServiceProvider / PanelProvider:

Cek file: app/Providers/Filament/AdminPanelProvider.php

Tambah middleware atau policy untuk restrict access:

- Super admin: lihat semua, tanpa filter
- Owner: semua query di-scope by auth()->id()

### Setiap Filament Resource (7 resources):

Tambah getEloquentQuery() override di setiap resource:

```php
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    if (auth()->user()->isSuperAdmin()) {
        return $query; // lihat semua
    }

    return $query->where('owner_id', auth()->id());
}
```

Resources yang perlu override getEloquentQuery():

1. ClusterResource
2. SupplierResource
3. ProductResource
4. ProcurementBatchResource
5. TripResource (kalau ada)
6. KioskResource → filter via cluster: whereHas('cluster', fn($q) => $q->where('owner_id', auth()->id()))

### AnggotaResource (UserResource untuk operator):

Owner hanya bisa lihat + manage operator yang terikat ke dirinya.
Super admin bisa lihat semua user.

Filter:

```php
if (auth()->user()->isOwner()) {
    return $query->where('role', 'operator')
                 ->where('owner_id', auth()->id()); // operator.owner_id
}
return $query; // super_admin lihat semua
```

## PERUBAHAN USERS TABLE

Tambah owner_id ke users table untuk operator:

```php
// Operator punya owner_id yang menunjuk ke owner-nya
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('owner_id')->nullable()->constrained('users')->onDelete('set null');
});
```

Super admin dan owner: owner_id = null
Operator: owner_id = id dari owner-nya

## PERUBAHAN OPERATOR LIVEWIRE

ActiveTrip, StartTrip, Dashboard, CreateKiosk:

- Semua query yang melibatkan kios, cluster, trip harus di-scope ke owner operator
- Cara: lewat auth()->user()->owner_id → filter cluster, kios, dll

Contoh di ActiveTrip mount():

```php
// Pastikan trip ini milik owner yang sama dengan operator
$this->trip = Trip::where('operator_id', auth()->id())
    ->where('owner_id', auth()->user()->owner_id) // tambah ini
    ->whereNull('ended_at')
    ->first();
```

## SEEDER UPDATE

Tambah super_admin user di DatabaseSeeder atau UserSeeder:

```php
User::factory()->create([
    'name' => 'Super Admin',
    'email' => 'admin@cemilanqontas.id',
    'role' => 'super_admin',
    'password' => bcrypt('password'),
]);
```

Update owner existing (owner@cemilanqontas.id) → owner_id = null (dia owner, bukan operator).
Update operator existing (operator@cemilanqontas.id) → owner_id = id owner.

## STEP EKSEKUSI (URUTAN WAJIB)

1. Cek schema existing + semua Filament resources (audit dulu)
2. Tambah role 'super_admin' ke users (cek existing enum/string)
3. Migration: tambah owner_id ke users (untuk operator)
4. Migration: tambah owner_id ke clusters, suppliers, products, procurement_batches, trips
5. Update data existing (set owner_id dari owner yang ada)
6. Update User model (helper methods)
7. Update semua Level 1 models ($fillable)
8. Update Filament Resources (getEloquentQuery() per resource)
9. Update operator Livewire components (scope by owner)
10. Update seeder (super_admin user)
11. php artisan migrate + php artisan db:seed --class=UserSeeder (kalau ada)
12. php artisan test --compact (target 58+ PASS)
13. Commit:
    git add .
    git commit -m "feat(auth): multi-tenant — super_admin + owner scoping + operator owner_id"
    git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. Role column di users bukan string (enum atau cast berbeda)
2. Filament resource tidak punya getEloquentQuery() pattern yang bisa di-override
3. Test turun dari 58 PASS
4. Data existing rusak setelah migration
5. Operator Livewire query error karena owner_id null

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

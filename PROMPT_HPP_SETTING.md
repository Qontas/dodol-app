# Brief: Setting HPP Per Owner

## KONTEKS

68 PASS. Tambah fitur setting HPP per owner.
HPP disimpan di tabel users (kolom hpp_per_mika per owner).
Default: Rp 9.500 (flat). Owner bisa custom kapanpun.

## BUSINESS RULES (LOCKED)

- HPP default = 9500 (Rp 9.500/mika) untuk semua owner baru
- Owner bisa ubah HPP sendiri via Filament profile/settings
- HPP dipakai di trip report: HPP trip = mika_terjual × hpp_per_mika
- Untung kotor = mika_terjual × (12000 - hpp_per_mika)
- Super admin bisa lihat + ubah HPP semua owner
- Operator tidak bisa lihat/ubah HPP

## SCHEMA PERUBAHAN

### Migration: tambah hpp_per_mika ke users

```php
Schema::table('users', function (Blueprint $table) {
    $table->decimal('hpp_per_mika', 10, 2)->default(9500)->after('commission_rate');
});
```

### Update seeder (UserSeeder):

Tambah hpp_per_mika ke owner + super_admin:

- owner@cemilanqontas.id → hpp_per_mika = 9500
- admin@cemilanqontas.id → hpp_per_mika = 9500 (super admin pakai default)
- operator → hpp_per_mika tidak relevan (null atau default)

## PERUBAHAN MODEL

### User model:

Tambah hpp_per_mika ke $fillable.
Tambah helper:

```php
public function getHppPerMikaValue(): float
{
    return (float) ($this->hpp_per_mika ?? 9500);
}
```

## PERUBAHAN TRIP REPORT

### app/Models/Trip.php

Semua kalkulasi HPP saat ini pakai hardcode 9500.
Ubah ke: ambil dari owner trip.

Cek dulu apakah Trip model punya relasi ke owner (owner_id → users).
Kalau belum ada: tambah relasi owner() di Trip model.

Ganti semua konstanta 9500, 2500, 500 di Trip model:

```php
private function getHpp(): float
{
    return (float) ($this->owner?->hpp_per_mika ?? 9500);
}

private function getUntungPerMika(): float
{
    return 12000 - $this->getHpp();
}

private function getKomisiPerMika(): float
{
    // Komisi reguler = 20% dari untung bersih
    return $this->getUntungPerMika() * 0.2;
}
```

Update semua method yang pakai 9500/2500/500:

- getMikaKiosBaruAttribute
- getTripReportAttribute (atau equivalent)
- Semua kalkulasi finansial di Trip model

## FILAMENT — SETTING HPP

### Tambah field HPP di UserResource form:

Di section yang sesuai (Profile/Konfigurasi):

```php
Forms\Components\TextInput::make('hpp_per_mika')
    ->label('HPP per Mika (Rp)')
    ->numeric()
    ->default(9500)
    ->minValue(1)
    ->helperText('Harga Pokok Produksi per mika. Default: Rp 9.500')
    ->visible(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
    ->required(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
```

### Tambah kolom HPP di UserResource table:

```php
Tables\Columns\TextColumn::make('hpp_per_mika')
    ->label('HPP/Mika')
    ->money('IDR')
    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
    ->toggleable(isToggledHiddenByDefault: true),
```

## OWNER SELF-SERVICE — EDIT HPP

Owner harus bisa ubah HPP sendiri tanpa lewat super admin.
Tambah halaman settings sederhana di owner area:

Route: GET /owner/settings → OwnerSettingsController
View: resources/views/owner/settings.blade.php
Form: input hpp_per_mika + tombol save
Auth: hanya owner yang login (bukan super admin, bukan operator)

Atau: manfaatkan Filament edit profile page yang sudah ada.

Cek apakah Filament punya edit profile — kalau ada, tambahkan field hpp_per_mika di sana.
Kalau tidak ada: buat halaman /owner/settings sederhana.

## STEP EKSEKUSI

1. Cek schema users (kolom yang ada) + Trip model (semua konstanta HPP)
2. Cek apakah Filament punya edit profile feature
3. Migration: tambah hpp_per_mika ke users (decimal, default 9500)
4. Update User model ($fillable + helper method + relasi jika perlu)
5. Update Trip model (ganti hardcode 9500/2500/500 ke dynamic)
6. Update UserResource (form field + table column)
7. Setup owner self-service edit HPP
8. Update UserSeeder (hpp_per_mika untuk owner + super_admin)
9. php artisan migrate + php artisan db:seed --class=UserSeeder
10. php artisan test --compact (target 68+ PASS)
11. Commit:
    git add .
    git commit -m "feat(owner): setting HPP per owner — dynamic hpp_per_mika di trip report"
    git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. Trip model tidak punya relasi owner() atau owner_id
2. Konstanta HPP ada di tempat lain selain Trip model
3. Test turun dari 68 PASS
4. Filament profile page conflict dengan field baru

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---

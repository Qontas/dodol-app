# Day 6 Sesi 0c — Form Redirect After Save Fix

## KONTEKS

- Day 6 Sesi 0a (Unified Login) + Sesi 0b (Performance) selesai
- Sekarang fix Issue #2 dari Day 5 night: Form stay di Edit page after Create
- 7 Filament Resources punya CreateRecord default behavior yang redirect ke EditRecord setelah save
- Owner expect redirect ke list page (index) untuk bulk input data workflow

## PROBLEM YANG MAU DI-FIX

Current behavior (Filament v3 default):

- User klik "Create New Kios" di /admin/kiosks/create
- Isi form, klik Save
- Filament redirect ke /admin/kiosks/{id}/edit (edit page record yang baru dibuat)
- Owner expect redirect ke /admin/kiosks (list page)

Expected behavior after fix:

- User klik "Create New Kios"
- Isi form, klik Save
- Filament redirect ke /admin/kiosks (list page)
- Owner bisa langsung lihat data baru di table + klik "New Kios" untuk input berikutnya

Edit page TIDAK di-fix di sesi ini (stay di edit page setelah save = default Filament behavior, useful untuk review changes).

## GOAL SESI 0C

Implement Trait approach:

1. Create new Trait file: `app/Filament/Concerns/RedirectsToIndex.php`
2. Apply Trait ke 7 Create page files
3. Test 1-2 Resources manually
4. Commit

## NO SCHEMA CHANGES

Pure PHP code adjustments. No migration, no config changes.

## EXISTING STATE (Verified Day 6 Sesi 0c Audit)

7 Create page files semua VANILLA scaffold:

1. app/Filament/Resources/ClusterResource/Pages/CreateCluster.php
2. app/Filament/Resources/SupplierResource/Pages/CreateSupplier.php
3. app/Filament/Resources/KioskResource/Pages/CreateKiosk.php
4. app/Filament/Resources/ProductResource/Pages/CreateProduct.php
5. app/Filament/Resources/ProductVariantResource/Pages/CreateProductVariant.php
6. app/Filament/Resources/UserResource/Pages/CreateUser.php
7. app/Filament/Resources/ProcurementBatchResource/Pages/CreateProcurementBatch.php

Pattern existing semua sama:

class CreateKiosk extends CreateRecord
{
protected static string $resource = KioskResource::class;
}

No existing override:

- getRedirectUrl() tidak ada
- mutateFormDataBeforeCreate() tidak ada
- handleRecordCreation() tidak ada

Greenfield, tidak ada konflik.

## TUGAS 1: CREATE TRAIT FILE

File baru: `app/Filament/Concerns/RedirectsToIndex.php`

Folder `app/Filament/Concerns/` kemungkinan belum ada — create folder kalau perlu.

Trait content:

namespace App\Filament\Concerns;

trait RedirectsToIndex
{
protected function getRedirectUrl(): string
{
return $this->getResource()::getUrl('index');
}
}

Effect: Method getRedirectUrl() di trait override default Filament behavior. Return URL ke index page resource yang corresponding.

Mechanism: $this->getResource() return Filament Resource class name (e.g., KioskResource). Static method getUrl('index') return URL ke list/index page (e.g., /admin/kiosks).

## TUGAS 2: APPLY TRAIT KE 7 CREATE PAGES

Untuk setiap 7 file Create:

1. Add use statement: `use App\Filament\Concerns\RedirectsToIndex;`
2. Add trait di class body: `use RedirectsToIndex;`

Pattern result (example CreateKiosk.php):

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\KioskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKiosk extends CreateRecord
{
use RedirectsToIndex;

    protected static string $resource = KioskResource::class;

}

Apply sama pattern ke 7 file.

Optional cleanup: Hapus dead import `use Filament\Actions;` di file Create yang tidak pakai Actions (Sesi 0c audit reveal dead import di CreateKiosk.php). Cleanup ini optional — fokus utama redirect fix.

## TUGAS 3: VERIFY NO REGRESSION

Run test suite:

php artisan test

Expected: 42 PASS, no regression.

Reason: Trait redirect tidak affect existing functionality (auth, role middleware, model observer, dll). Pure UI behavior change.

## TUGAS 4: MANUAL TEST 1 RESOURCE

Advisor akan manual test di browser setelah commit. Tidak perlu Claude Code test ini.

Scenario manual test (untuk reference):

1. Akses /admin/clusters
2. Klik "New Cluster"
3. Isi nama: "Test Cluster Sesi 0c"
4. Klik Save
5. Expected: redirect ke /admin/clusters (list page), bukan /admin/clusters/{id}/edit

Kalau redirect ke list = SUCCESS.

## TUGAS 5: COMMIT

git add app/Filament/Concerns/ app/Filament/Resources/

git commit -m "feat(admin): redirect to list page after create

- Add RedirectsToIndex trait at app/Filament/Concerns/
- Apply trait to 7 Filament Create pages (Cluster, Supplier, Kios, Product, ProductVariant, User, ProcurementBatch)
- Override getRedirectUrl() return resource::getUrl('index')

Behavior change:

- Create new record -> redirect to list page (was: redirect to edit page)
- Edit existing record -> unchanged (stay at edit page, Filament default)

Edit pages NOT modified (stay-at-edit useful for review changes).

Fixes Issue #2 from Day 5 manual test."

## REPORT KE ADVISOR

Setelah commit, report:

1. File created: app/Filament/Concerns/RedirectsToIndex.php
2. List 7 file Create yang di-modify
3. Output php artisan test (expect 42 PASS)
4. Commit hash + git log -3

## STOP POINTS - TANYA ADVISOR KALAU

1. Folder app/Filament/Concerns/ tidak bisa di-create (permission issue)
2. Trait pattern reject by Filament v3.3.50 (unlikely, but report)
3. Test regression (42 jadi <42 PASS)
4. Ada existing override yang conflict dengan trait

JANGAN auto-decide kalau ada conflict. Tanya advisor.

## CATATAN PENTING

- NO SCHEMA CHANGES. Pure PHP refactoring.
- Trait approach pattern Laravel idiom, no Filament-specific magic.
- $this->getResource() di trait resolve correctly karena CreateRecord parent class punya method getResource().
- Method getUrl('index') = Filament Resource convention, return URL ke list page (e.g., /admin/kiosks).
- Edit pages TIDAK di-touch di sesi ini.
- Cache impact: tidak perlu re-cache, karena modify code = Laravel auto-detect (cache PHP class via opcache, bukan via artisan).

Mulai sekarang.

--- END OF BRIEF ---

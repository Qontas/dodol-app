# Day 6 Sesi 0a — Unified Login Fix

## KONTEKS

- Day 5 closed dengan 10 atomic commits (commit 59ac2bb)
- Owner panel 7 Resources ready, Operator Trip Foundation ready, 42 tests PASS
- 4 UX issues caught Day 5 night di NEXT_SESSION.md
- Sekarang fix Issue #1: Unified Login

## PROBLEM YANG MAU DI-FIX

Current state (dual login confusing):

- Breeze login di `/login` (Volt component) redirect ke route('dashboard') dispatch by role
- Filament login di `/admin/login` (Filament built-in) redirect ke `/admin`
- Owner logout dari `/admin` stuck di `/admin/login`
- Operator credential ditolak di `/admin/login` (canAccessPanel filter by role)
- User pengalaman confusing saat switch role

Expected behavior (locked decision with advisor):

- Strategy A: Hapus Filament `/admin/login`, unify ke Breeze `/login`
- Strategy 2: Owner login ke `/owner/dashboard` dulu (existing pattern), klik "Buka Admin Panel" untuk masuk `/admin`
- Strategy X: Logout dari mana pun kembali ke `/login`

## EXISTING SETUP (Verified Day 6 Audit)

routes/web.php existing pattern:

- Custom logout di /logout POST endpoint (redirect ke route('login'))
- /dashboard dispatcher route redirect by auth user role
- Owner route group: ['auth', 'verified', 'role:owner'] prefix owner
- Operator route group: ['auth', 'verified', 'role:operator'] prefix operator

Breeze authentication (Volt-based):

- Login form: resources/views/livewire/pages/auth/login.blade.php
- Login logic: $this->redirectIntended(default: route('dashboard'), navigate: true)
- Logout custom di routes/web.php line 9-15

Filament admin panel:

- app/Providers/Filament/AdminPanelProvider.php line 30: ->login() method registered
- This expose /admin/login page

EnsureUserHasRole middleware:

- Handle inactive user (redirect to login dengan flash status)
- Handle role mismatch (abort 403)
- Already production-ready

Email verification active dengan VerifyEmailController + verified middleware di semua role routes.

## GOAL SESI 0A

Implement 3 changes:

1. Disable Filament login page (/admin/login no longer accessible)
2. Customize Filament LoginResponse (redirect to /owner/dashboard instead of /admin)
3. Customize Filament logout redirect (redirect to /login instead of /admin/login)

After implementation:

- All login via /login (Breeze single source)
- Owner login redirect ke /owner/dashboard, klik "Buka Admin Panel" untuk masuk /admin
- Operator login redirect ke /operator/dashboard
- Owner logout dari /admin redirect ke /login
- All logout flow ke /login

## NO SCHEMA CHANGES

Pure config + middleware adjustments. No migration needed.

## TUGAS 1: REMOVE FILAMENT LOGIN PAGE

File: app/Providers/Filament/AdminPanelProvider.php

Action: Hapus line ->login() dari panel registration.

Before (current code):
return panel
default
id 'admin'
path 'admin'
brandName 'Cemilan Qontas'
login() <-- HAPUS LINE INI
colors primary Color Amber
rest of config

After (cleaned code):
return panel
default
id 'admin'
path 'admin'
brandName 'Cemilan Qontas'
colors primary Color Amber
rest of config

Effect: /admin/login route tidak lagi registered. Unauthenticated user yang akses /admin akan auto-redirect ke /login (Breeze) via Authenticate middleware default.

Verify: Setelah eksekusi, php artisan route:list | grep admin/login harus return 0 results.

## TUGAS 2: VERIFY AUTHENTICATE MIDDLEWARE REDIRECT

File: app/Http/Middleware/Authenticate.php (Laravel default)

Cek: Method redirectTo return route('login').

Laravel 11 default behavior: Authenticate middleware redirect unauthenticated request ke route('login').

Kalau ada custom Authenticate.php di app, verify method redirectTo return route('login') untuk non-JSON request.

Kalau file tidak ada di app/Http/Middleware/, biarin aja - Laravel default handle ini.

## TUGAS 3: CUSTOMIZE FILAMENT LOGOUT REDIRECT

Action: Implement custom LogoutResponse contract.

Step 1: Create new file app/Http/Responses/Auth/LogoutResponse.php

Class LogoutResponse di namespace App\Http\Responses\Auth, implements Filament Http Responses Auth Contracts LogoutResponse, dengan method toResponse(request) yang return redirect()->route('login').

Step 2: Register custom LogoutResponse di app/Providers/AppServiceProvider.php method register().

Bind Filament Http Responses Auth Contracts LogoutResponse::class ke App\Http\Responses\Auth\LogoutResponse::class via app->bind().

Effect: Pas owner klik logout di Filament admin panel, redirect ke /login (Breeze), bukan /admin/login.

## TUGAS 4: CUSTOMIZE FILAMENT LOGIN REDIRECT (Defensive)

Context: Sekarang Filament /admin/login di-disable, tapi add this just in case edge case.

Step 1: Create new file app/Http/Responses/Auth/LoginResponse.php

Class LoginResponse di namespace App\Http\Responses\Auth, implements Filament Http Responses Auth Contracts LoginResponse, dengan method toResponse(request) yang return redirect()->route('owner.dashboard').

Step 2: Register di AppServiceProvider:

Bind Filament Http Responses Auth Contracts LoginResponse::class ke App\Http\Responses\Auth\LoginResponse::class.

Effect: Defensive layer kalau ada edge case Filament try login, redirect ke /owner/dashboard.

## TUGAS 5: TEST AUTHENTICATION FLOWS

Run: php artisan test
Expected: 42 PASS, no regression.

Optional: Add new test file tests/Feature/Auth/UnifiedLoginTest.php dengan 4 test cases:

1. test_admin_login_route_does_not_exist - GET /admin/login assert status 404
2. test_unauthenticated_admin_access_redirects_to_login - GET /admin assert redirect to route('login')
3. test_owner_logout_from_admin_redirects_to_login - actingAs owner, POST /admin/logout, assert redirect to route('login')
4. test_owner_can_access_admin_after_breeze_login - actingAs verified owner, GET /admin, assert status 200

Use RefreshDatabase trait. User factory dengan role 'owner', is_active true, email_verified_at now() untuk test #4.

Optional skip kalau mau cepat - existing 42 tests cukup baseline.

## TUGAS 6: VERIFY ROUTES

Run: php artisan route:list | grep -E "(login|admin)" | head -20

Expected output:

- GET /login Breeze name 'login' (EXIST)
- POST /login Breeze submit (EXIST)
- POST /logout custom (EXIST)
- GET /admin Filament panel home (EXIST)
- GET /admin/login (SHOULD NOT EXIST - disabled via Tugas 1)
- POST /admin/logout Filament logout (EXIST - redirect ke /login via LogoutResponse)

## TUGAS 7: COMMIT

git add app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php app/Http/Responses/

git commit -m "feat(auth): unified login - disable filament login, redirect to breeze

- Remove ->login() from AdminPanelProvider (disable /admin/login)
- Custom LogoutResponse: filament logout redirects to /login (Breeze)
- Custom LoginResponse: defensive redirect to /owner/dashboard
- Bind both responses in AppServiceProvider

Flow:

- All login via /login (Breeze single source)
- Owner login redirect ke /owner/dashboard, Buka Admin Panel ke /admin
- Operator login redirect ke /operator/dashboard
- Filament logout redirect ke /login (not /admin/login)
- Unauthenticated /admin redirect ke /login

Fixes Issue #4 from Day 5 manual test."

## REPORT KE ADVISOR

Setelah commit, report:

1. File list yang dibuat/dimodifikasi (4-5 files)
2. Output php artisan test (42 PASS minimum)
3. Output php artisan route:list grep login admin
4. Commit hash + git log -3
5. Manual test scenarios yang verify:
    - Akses /admin/login expect 404
    - Akses /admin tanpa login expect redirect /login
    - Owner login via /login expect redirect /owner/dashboard
    - Owner klik Buka Admin Panel expect /admin accessible
    - Owner logout dari Filament admin expect redirect /login

## STOP POINTS - TANYA ADVISOR KALAU

1. File app/Providers/AppServiceProvider.php punya structure berbeda - gimana adapt
2. Filament LogoutResponse contract path beda di Filament v3.3.50
3. ->login() removal bikin Filament error / panel inaccessible total
4. Routes hilang yang seharusnya ada (admin atau login Breeze)
5. Test regression (42 tests jadi kurang dari 42 PASS)

JANGAN auto-decide untuk hal fundamental conflict. Tanya dulu.

## CATATAN PENTING

- NO SCHEMA CHANGES. Pure config + middleware.
- File LoginResponse.php dan LogoutResponse.php di folder baru app/Http/Responses/Auth/
- Custom contracts harus implement Filament Contracts (namespace match Filament v3.3.50)
- Existing /logout POST custom di web.php (Breeze flow) TETAP ADA. Filament punya /admin/logout POST sendiri (auto-handle by panel).
- Test test_admin_login_route_does_not_exist important - verify /admin/login truly disabled

Mulai sekarang.

--- END OF BRIEF ---

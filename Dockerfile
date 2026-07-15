# FASE 2 — Server produksi: Laravel Octane + FrankenPHP via image RESMI.
# Pendekatan deterministik (ganti download binary manual via nixpacks yang gagal 2x).
#
# Image dunglas/frankenphp:1.12-php8.4 = FrankenPHP 1.12 + PHP 8.4.x (BUKAN 8.5).
# Image resmi sudah menyiapkan FrankenPHP + Caddy + folder writable dengan benar.
#
# ROLLBACK: railway.toml `builder` balik ke "nixpacks" → pakai nixpacks.toml
# (artisan serve, known-good) lagi. File nixpacks.toml SENGAJA dipertahankan.

# --- Stage 1: build aset Vite (public/build di-gitignore → wajib di-build) ---
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- Stage 2: runtime FrankenPHP + PHP 8.4 ---
FROM dunglas/frankenphp:1.12-php8.4

# Ekstensi PHP yang dibutuhkan app (gd untuk foto kios, pdo_mysql, intl, dll).
# pcntl: disarankan Octane untuk penanganan sinyal worker.
#
# imagick: DIPAKAI HANYA untuk konversi HEIC → JPG (App\Support\HeicConverter). GD tak
# bisa baca HEIC sama sekali, dan browser Android (Poco F7 owner) juga tidak → satu-
# satunya jalan supaya foto HEIC tak berujung blank. Butuh delegate HEIF (libheif):
# install-php-extensions memasang libmagickwand-dev + libmagickcore-*-extra, dan paket
# libmagickcore Debian bookworm bergantung pada libheif1 → HEIC ikut terbawa.
# Kompres foto biasa TETAP pakai GD (ImageResizer) — imagick tidak menggantikannya.
# Kalau delegate HEIF ternyata tak ada, app TIDAK rusak: HeicConverter::supported()
# mendeteksinya saat runtime dan HEIC ditolak dengan pesan jelas. Pastikan dengan
# `php artisan foto:diagnosa` di console Railway setelah deploy.
RUN install-php-extensions \
    pdo_mysql \
    gd \
    imagick \
    intl \
    zip \
    bcmath \
    opcache \
    pcntl \
    mbstring

# Composer dari image resmi.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Kode app (vendor/node_modules/.env dikecualikan lewat .dockerignore).
COPY . /app
# Aset Vite hasil build stage 1.
COPY --from=assets /app/public/build /app/public/build

# Dependency PHP produksi.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# OPcache produksi (validate_timestamps=0 dll) — conf.d image resmi dibaca PHP.
COPY php-ini/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
# Batas upload foto kios. WAJIB eksplisit: image ini tak mengaktifkan php.ini apa pun
# → tanpa file ini PHP pakai default 2M/8M dan foto HEIC mentah gagal diam-diam.
COPY php-ini/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

# Pastikan folder runtime ADA + writable (storage/logs di-exclude .dockerignore).
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R ug+w storage bootstrap/cache

# CACHE config DI-RUNTIME (bukan build): di Railway+Docker, env (DB_*, APP_KEY)
# di-inject saat RUNTIME, tidak saat build. config:cache di build akan membekukan
# creds KOSONG → app gagal konek DB. Maka semua cache + migrate dijalankan di CMD
# setelah env tersedia. config/octane.php = DEFAULT (listener reset auth/session utuh).
ENTRYPOINT []
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache && php artisan migrate --force && (php artisan storage:link || true) && php artisan octane:frankenphp --host=0.0.0.0 --port=${PORT:-8000} --workers=auto --max-requests=500"]

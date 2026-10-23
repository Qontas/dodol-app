<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Manajemen User';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Akun')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Contoh: Rian Saputra'),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            // Simpan email selalu ternormalisasi (trim + lowercase) — konsisten
                            // dgn User::email mutator & jalur login. Cegah email tersimpan berspasi.
                            ->dehydrateStateUsing(fn (string $state): string => mb_strtolower(trim($state)))
                            ->placeholder('user@cemilanqontas.id'),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->minLength(8)
                            ->maxLength(255)
                            // ⚠️ autocomplete="new-password": tanpa ini Chrome MENGISI SENDIRI
                            // field ini dengan password tersimpan saat halaman Edit dibuka.
                            // Karena field terisi = filled() = dehydrated, sekali klik Simpan
                            // password user KETIMPA nilai autofill yang owner tak pernah lihat
                            // — di akun super admin itu artinya lockout. Helper "kosongkan
                            // kalau tak ingin mengubah" cuma benar kalau field BENAR-BENAR
                            // kosong, jadi mencegah autofill adalah syarat janji itu berlaku.
                            ->autocomplete('new-password')
                            ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $context): string => $context === 'create'
                                ? 'Min. 8 karakter'
                                : 'Kosongkan kalau tidak ingin mengubah password'),
                    ]),

                Section::make('Role & Akses')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->options(function (?User $record): array {
                                if (! auth()->user()?->isSuperAdmin()) {
                                    return ['operator' => 'Operator'];
                                }

                                $options = ['owner' => 'Owner', 'operator' => 'Operator'];

                                // Super Admin tunggal: opsi super_admin hanya muncul kalau
                                // belum ada super lain (atau record ini SENDIRI si super,
                                // agar tetap terpilih saat Edit dirinya).
                                if (! User::anotherSuperAdminExists($record?->getKey())) {
                                    $options = ['super_admin' => 'Super Admin'] + $options;
                                }

                                return $options;
                            })
                            ->default('operator')
                            ->required()
                            ->live()
                            // Guard lockout: user tak boleh menurunkan role dirinya sendiri
                            // (mis. super_admin → operator, langsung kehilangan akses panel).
                            ->disabled(fn (?User $record): bool => $record !== null && $record->id === auth()->id())
                            // Alasan disabled DITULIS, bukan diam-diam: sebelumnya user
                            // melihat dropdown mati tanpa penjelasan (dan kalau payload
                            // di-tamper, penolakan baru terasa setelah Simpan).
                            ->helperText(fn (?User $record): string => $record !== null && $record->id === auth()->id()
                                ? 'Tidak bisa mengubah role akun sendiri (mencegah kehilangan akses panel).'
                                : 'Owner = akses penuh bisnis sendiri. Operator = akses operasional lapangan'),

                        // Owner dari operator. Owner viewer: auto-set ke dirinya (lihat CreateUser).
                        // Super admin: pilih owner mana operator ini terikat.
                        Forms\Components\Select::make('owner_id')
                            ->label('Owner')
                            ->options(fn (): array => User::query()->where('role', 'owner')->pluck('name', 'id')->all())
                            ->searchable()
                            // ->live(): komisi_per_mika_khusus di bawah menampilkan tarif
                            // default OWNER YANG DIPILIH di helper text-nya — tanpa live()
                            // helper itu tak ikut berubah saat super admin ganti pilihan.
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) auth()->user()?->isSuperAdmin() && $get('role') === 'operator')
                            ->required(fn (Get $get): bool => (bool) auth()->user()?->isSuperAdmin() && $get('role') === 'operator')
                            ->helperText('Operator ini bekerja untuk owner yang dipilih'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            // Guard lockout: user tak boleh menonaktifkan akunnya sendiri
                            // (langsung ter-logout & tak bisa login lagi).
                            ->disabled(fn (?User $record): bool => $record !== null && $record->id === auth()->id())
                            ->helperText(fn (?User $record): string => $record !== null && $record->id === auth()->id()
                                ? 'Tidak bisa menonaktifkan akun sendiri.'
                                : 'Nonaktifkan untuk disable login user ini'),
                    ]),

                // BRIEF 2 Section 3 — tarif komisi KHUSUS untuk SATU operator, nullable.
                // Kosong = ikut tarif default usaha (owner-nya). Diisi di sini (owner
                // membuat/mengubah operatornya) ATAU form Edit super admin (owner_id
                // dipilih manual, lihat helper text di bawah yang baca owner TERSEBUT).
                //
                // Hanya SATU field (bukan "reguler"+"kios baru") — komisi sudah flat
                // satu tarif sejak sebelum BRIEF 2 (lihat Trip::getKomisiKiosBaruAttribute
                // yang hardcode 0.0), jadi override pun cukup satu angka.
                //
                // Field ini TIDAK menyentuh trip yang sudah berjalan — hanya dibaca saat
                // trip BARU dimulai (User::getEffectiveKomisiPerMikaValue() dipanggil dari
                // StartTrip::startTrip(), dibekukan ke trips.komisi_per_mika_snapshot).
                Section::make('Komisi Khusus Operator')
                    ->description('Opsional — kosongkan untuk ikut tarif default usaha owner.')
                    ->visible(fn (Get $get): bool => $get('role') === 'operator')
                    ->schema([
                        Forms\Components\TextInput::make('komisi_per_mika_khusus')
                            ->label('Komisi Khusus (Rp)')
                            ->numeric()
                            ->nullable()
                            ->minValue(0)
                            ->helperText(function (Get $get): string {
                                $defaultRate = static::resolveOwnerDefaultKomisiRate($get);
                                $rupiah = 'Rp '.number_format($defaultRate, 0, ',', '.');

                                return "Kosongkan untuk ikut tarif default usaha ({$rupiah})";
                            }),
                    ]),

                // Tarif bisnis DIPISAH dari section role, dan hanya untuk role OWNER.
                //
                // Dulu keempat field ini ikut section "Role & Komisi" dengan
                // visible(role in [owner, super_admin]) — jadi akun SUPER ADMIN pun
                // menampilkan "Komisi Reguler / Komisi Kios Baru", seolah super admin
                // punya tarif upah. Padahal komisi = upah OPERATOR per mika drop, dan
                // angkanya SELALU dibaca dari OWNER: laporan/riwayat/dashboard memakai
                // owner trip (Trip::owner_id, yang tak pernah super admin) — lihat
                // MonthlyReportController & OwnerDashboardController. Super admin juga
                // diblokir route 'role:owner' dari /owner/settings & /owner/dashboard,
                // jadi nilainya memang tak pernah dipakai membayar siapa pun.
                //
                // Aman disembunyikan: komponen tersembunyi tak ikut dehydrate, dan
                // keempat kolom punya DEFAULT di DB (9500/200/1000/1000) sehingga
                // membuat user non-owner tetap valid tanpa field ini.
                Section::make('Tarif & Komisi')
                    ->description('Tarif bisnis owner — dipakai menghitung untung & upah operator.')
                    ->visible(fn (Get $get): bool => $get('role') === 'owner')
                    ->schema([
                        // hpp_per_mika DIHAPUS dari form ini — HPP sekarang per-varian produk
                        // (ProductVariant::cost_per_pack), bukan satu angka global per owner.
                        // Kolom tabel readonly super-admin (di bawah) DIBIARKAN — informasional,
                        // audit data lama, bukan jalur kalkulasi aktif.
                        Forms\Components\TextInput::make('harga_mika')
                            ->label('Harga Mika (Rp)')
                            ->numeric()
                            ->default(200)
                            ->minValue(0)
                            ->helperText('Harga modal mika per kemasan. Default: Rp 200')
                            ->required(),

                        Forms\Components\TextInput::make('komisi_per_mika')
                            ->label('Komisi Reguler (Rp)')
                            ->numeric()
                            ->default(1000)
                            ->minValue(0)
                            ->helperText('Komisi per mika yang diletakkan operator (basis drop). Default: Rp 1.000')
                            ->required(),

                        // "Komisi Kios Baru (Rp)" DIHAPUS dari form ini (23 Okt 2026) — investigasi
                        // BRIEF 2 membuktikan komisi sudah FLAT (Trip::getKomisiKiosBaruAttribute()
                        // hardcode 0.0, bonus kios-baru sudah dilebur ke tarif reguler di atas);
                        // isian ini TIDAK berpengaruh ke perhitungan apa pun. Kolom DB
                        // komisi_kios_baru_per_mika SENGAJA TIDAK didrop, lihat NEXT_SESSION.md.
                    ]),
            ]);
    }

    /**
     * Tarif default usaha (owner) yang berlaku untuk operator yang SEDANG diisi di form —
     * owner viewer: selalu dirinya sendiri (operator yang dibuat/diedit owner SELALU
     * terikat ke owner itu, lihat CreateUser/EditUser mutate*BeforeSave). Super admin:
     * owner yang DIPILIH di field 'owner_id' (bisa berubah sebelum disimpan, makanya
     * field itu ->live()). Fallback 1000 kalau owner belum dipilih (form create kosong).
     */
    private static function resolveOwnerDefaultKomisiRate(Get $get): float
    {
        if (auth()->user()?->isOwner()) {
            return auth()->user()->getKomisiPerMikaValue();
        }

        $ownerId = $get('owner_id');

        return $ownerId
            ? (float) (User::find($ownerId)?->getKomisiPerMikaValue() ?? 1000)
            : 1000.0;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'owner' => 'success',
                        'operator' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'super_admin' => 'Super Admin',
                        'owner' => 'Owner',
                        'operator' => 'Operator',
                        default => $state,
                    })
                    ->sortable(),

                // BRIEF 2 Section 4 — ringkasan per OWNER di daftar user: jumlah kios
                // aktif & jumlah operator miliknya. "—" untuk baris operator/super admin
                // (tak relevan untuk mereka). Baca withCount('ownedKiosks'/'operators')
                // yang SUDAH dimuat getEloquentQuery() — NOL query tambahan per baris.
                Tables\Columns\TextColumn::make('owned_active_kiosks_count')
                    ->label('Kios')
                    ->state(fn (User $record): ?int => $record->role === 'owner' ? (int) $record->owned_active_kiosks_count : null)
                    ->placeholder('—')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('operators_count')
                    ->label('Operator')
                    ->state(fn (User $record): ?int => $record->role === 'owner' ? (int) $record->operators_count : null)
                    ->placeholder('—')
                    ->alignCenter(),

                // Komisi Operator/Mika = angka yang SUNGGUH dipakai membayar operator
                // (Rp per mika drop). Untuk operator → tarif EFEKTIF-nya (khusus ??
                // default owner — BRIEF 2 Section 3, lihat getEffectiveKomisiPerMikaValue).
                // Kolom persen 'commission_rate' lama SENGAJA dibuang: legacy & menyesatkan.
                //
                // Label lama "Komisi/Mika" + angka polos bikin baris OWNER terlihat
                // seolah owner MENERIMA komisi, padahal itu tarif yang owner BAYAR ke
                // operatornya — beda arti dgn baris operator (tarif yang operator
                // TERIMA, dibaca dari owner-nya ATAU tarif khususnya sendiri). Prefix
                // "Bayar"/"Terima" per baris meluruskan ini TANPA mengubah angka (fix
                // 20 Okt 2026); suffix "(khusus)"/"(ikut owner)" ditambah 21 Okt 2026
                // supaya owner/super admin bisa lihat sekilas siapa yang di-override.
                //
                // $record->owner sudah di-eager-load (->with('owner') di bawah) — baca
                // komisi_per_mika_khusus langsung dari $record (kolom sendiri), NOL
                // query tambahan.
                Tables\Columns\TextColumn::make('komisi_efektif_per_mika')
                    ->label('Komisi Operator /Mika')
                    ->state(fn (User $record): ?float => match ($record->role) {
                        'operator' => $record->getEffectiveKomisiPerMikaValue(),
                        'owner' => $record->getKomisiPerMikaValue(),
                        default => null,
                    })
                    ->formatStateUsing(function ($state, User $record) {
                        if ($state === null) {
                            return '—';
                        }

                        $rupiah = 'Rp '.number_format((float) $state, 0, ',', '.');

                        if ($record->role === 'owner') {
                            return "Bayar {$rupiah}";
                        }

                        if ($record->role === 'operator') {
                            $suffix = $record->komisi_per_mika_khusus !== null ? '(khusus)' : '(ikut owner)';

                            return "Terima {$rupiah} {$suffix}";
                        }

                        return $rupiah;
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('hpp_per_mika')
                    ->label('HPP/Mika')
                    ->money('IDR')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('harga_mika')
                    ->label('Harga Mika')
                    ->money('IDR')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('komisi_per_mika')
                    ->label('Komisi Reguler')
                    ->money('IDR')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                // Kolom tabel "Komisi Kios Baru" DIHAPUS (23 Okt 2026) — lihat komentar
                // di form Section('Tarif & Komisi') di atas; isian ini tak berfungsi.

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                // Kolom 'Verifikasi' (email_verified_at) DIBUANG: User tak implement
                // MustVerifyEmail → verifikasi email tak pernah dijalankan & tak memblokir
                // login. Sistem tertutup (akun dibuat manual) — kolom itu menyesatkan.

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options([
                        'owner' => 'Owner',
                        'operator' => 'Operator',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Nonaktif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    // Tooltip alasan dipasang di elemen PEMBUNGKUS. Tanpa ini tooltip
                    // pada tombol disabled TAK PERNAH muncul — Tippy menolak tampil
                    // selama atribut `disabled` menempel. Lihat catatan panjang di
                    // view-nya.
                    ->view('filament.actions.link-action-tooltip-wrapped')
                    // Lapisan UI di atas guard server-side (defense in depth tetap utuh):
                    // Super Admin & akun sendiri → tombol Delete DISEMBUNYIKAN total.
                    ->hidden(fn (User $record): bool => $record->isSuperAdmin() || (int) $record->id === (int) auth()->id())
                    // Owner/operator berdata → tombol tampil tapi DISABLED + tooltip alasan
                    // (owner perlu tahu KENAPA tak bisa dihapus).
                    // RENDER pakai versi ForDisplay (baca count hasil withCount di
                    // getEloquentQuery → 0 query per baris). disabled+tooltip memang
                    // memanggil dua kali, tapi kini dua-duanya cuma baca atribut.
                    ->disabled(fn (User $record): bool => static::deleteBlockReasonForDisplay($record) !== null)
                    ->tooltip(fn (User $record): ?string => static::deleteBlockReasonForDisplay($record))
                    // ⚠️ KEPUTUSAN hapus tetap REAL-TIME (deleteBlockReason), bukan angka
                    // hasil render yang bisa basi.
                    ->before(function (User $record, Tables\Actions\DeleteAction $action) {
                        if ($reason = static::deleteBlockReason($record)) {
                            Notification::make()->danger()->title('Tidak bisa dihapus')->body($reason)->persistent()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation()
                        // Bulk tak bisa disembunyikan per-baris → guard server-side dgn
                        // pesan yang menyebut SEMUA baris terblokir + alasannya.
                        ->before(function (Collection $records, Tables\Actions\DeleteBulkAction $action) {
                            $blocked = [];
                            foreach ($records as $record) {
                                if ($reason = static::deleteBlockReason($record)) {
                                    $blocked[] = "{$record->name}: {$reason}";
                                }
                            }
                            if ($blocked) {
                                Notification::make()->danger()
                                    ->title('Batal menghapus — ada baris terkunci')
                                    ->body(implode("\n", $blocked))
                                    ->persistent()->send();
                                $action->halt();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Alasan ramah kenapa $record tak boleh dihapus, atau null kalau boleh.
     * Gabungkan guard lockout (akun sendiri) + guard berdata/role (model).
     * REAL-TIME — untuk keputusan hapus (->before) & bulk.
     *
     * PUBLIC karena halaman Edit (EditUser) memakai guard yang SAMA persis untuk header
     * DeleteAction-nya. Satu sumber aturan — jangan duplikasi logikanya di sana.
     */
    public static function deleteBlockReason(User $record): ?string
    {
        return static::deleteBlockReasonSelf($record) ?? $record->deletionBlockReason();
    }

    /** Sama, tapi untuk RENDER tabel: baca count hasil withCount → 0 query per baris. */
    protected static function deleteBlockReasonForDisplay(User $record): ?string
    {
        return static::deleteBlockReasonSelf($record) ?? $record->deletionBlockReasonForDisplay();
    }

    private static function deleteBlockReasonSelf(User $record): ?string
    {
        return (int) $record->id === (int) auth()->id()
            ? 'Tidak bisa menghapus akun sendiri.'
            : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Akun sistem (operator.migrasi.*) selalu disembunyikan dari panel — tak bisa
        // dilihat/diedit/diaktifkan/dihapus dari UI (route edit/delete-nya jadi 404).
        $query = parent::getEloquentQuery()->excludeSystem();

        // Hitungan dependensi untuk guard delete (tombol disabled + tooltip) ikut
        // query utama = subquery berkorelasi, BUKAN query per baris. Tanpa ini tiap
        // baris menembak 2-3 COUNT × 2 pemanggil (->disabled + ->tooltip) = ~53 query
        // untuk 10 baris. Baris owner memakai 3 count pertama, baris operator 2
        // terakhir; yang tak terpakai tetap murah (masih satu query utama).
        // BRIEF 2 Section 4: kolom "Kios" di tabel butuh kios AKTIF saja (beda dari
        // owned_kiosks_count delete-guard di atas, yang sengaja TOTAL — kios nonaktif
        // tetap harus memblokir hapus owner). Alias terpisah ('owned_active_kiosks_count'),
        // qualified 'kiosks.is_active' (bukan 'is_active' polos) karena hasManyThrough
        // ini JOIN ke clusters yang JUGA punya kolom is_active — tanpa qualifier itu
        // ambigu. Masih SATU query utama (subquery correlated), bukan N+1.
        $query->withCount([
            'ownedKiosks',
            'ownedKiosks as owned_active_kiosks_count' => fn ($q) => $q->where('kiosks.is_active', true),
            'ownedTrips', 'operators', 'operatedTrips', 'commissions',
        ]);

        // Kolom "Komisi/Mika" baris OPERATOR membaca tarif OWNER-nya
        // ($record->owner?->getKomisiPerMikaValue()). Tanpa eager load ini, tiap baris
        // operator menembak `select * from users where id = ?` sendiri-sendiri — N+1
        // yang terbatas ukuran halaman (maks 10) sehingga tak pernah "meledak", tapi
        // NYATA: 1 query ekstra per operator yang tampil. Ini juga yang membuat
        // UserListQueryCountTest merah acak — jumlah baris operator di halaman 1
        // bergantung nama random, jadi query count ikut naik-turun (diukur 5..9).
        $query->with('owner');

        if (auth()->user()?->isSuperAdmin()) {
            return $query; // super admin lihat semua user (kecuali akun sistem)
        }

        // Owner: hanya operator yang terikat ke dirinya
        return $query->where('role', 'operator')
            ->where('owner_id', auth()->id());
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

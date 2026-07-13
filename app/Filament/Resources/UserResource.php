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
                            ->placeholder('user@cemilanqontas.id'),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->minLength(8)
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $context): string => $context === 'create'
                                ? 'Min. 8 karakter'
                                : 'Kosongkan kalau tidak ingin mengubah password'),
                    ]),

                Section::make('Role & Komisi')
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
                            ->helperText('Owner = akses penuh bisnis sendiri. Operator = akses operasional lapangan'),

                        // Owner dari operator. Owner viewer: auto-set ke dirinya (lihat CreateUser).
                        // Super admin: pilih owner mana operator ini terikat.
                        Forms\Components\Select::make('owner_id')
                            ->label('Owner')
                            ->options(fn (): array => User::query()->where('role', 'owner')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) auth()->user()?->isSuperAdmin() && $get('role') === 'operator')
                            ->required(fn (Get $get): bool => (bool) auth()->user()?->isSuperAdmin() && $get('role') === 'operator')
                            ->helperText('Operator ini bekerja untuk owner yang dipilih'),

                        Forms\Components\TextInput::make('hpp_per_mika')
                            ->label('HPP per Mika (Rp)')
                            ->numeric()
                            ->default(9500)
                            ->minValue(1)
                            ->helperText('Harga Pokok Produksi per mika. Default: Rp 9.500')
                            ->visible(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
                            ->required(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin'])),

                        Forms\Components\TextInput::make('harga_mika')
                            ->label('Harga Mika (Rp)')
                            ->numeric()
                            ->default(200)
                            ->minValue(0)
                            ->helperText('Harga modal mika per kemasan. Default: Rp 200')
                            ->visible(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
                            ->required(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin'])),

                        Forms\Components\TextInput::make('komisi_per_mika')
                            ->label('Komisi Reguler (Rp)')
                            ->numeric()
                            ->default(500)
                            ->minValue(0)
                            ->helperText('Komisi per mika untuk kios biasa. Default: Rp 500')
                            ->visible(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
                            ->required(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin'])),

                        Forms\Components\TextInput::make('komisi_kios_baru_per_mika')
                            ->label('Komisi Kios Baru (Rp)')
                            ->numeric()
                            ->default(1000)
                            ->minValue(0)
                            ->helperText('Komisi per mika untuk kios baru. Default: Rp 1.000')
                            ->visible(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin']))
                            ->required(fn (Get $get): bool => in_array($get('role'), ['owner', 'super_admin'])),

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
            ]);
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

                // Komisi/Mika = angka yang SUNGGUH dipakai membayar operator
                // (Rp per mika drop). Untuk operator → tarif owner-nya. Kolom persen
                // 'commission_rate' lama SENGAJA dibuang: legacy & menyesatkan.
                Tables\Columns\TextColumn::make('komisi_efektif_per_mika')
                    ->label('Komisi/Mika')
                    ->state(fn (User $record): ?float => match ($record->role) {
                        'operator' => $record->owner?->getKomisiPerMikaValue(),
                        'owner' => $record->getKomisiPerMikaValue(),
                        default => null,
                    })
                    ->formatStateUsing(fn ($state) => $state !== null ? 'Rp '.number_format((float) $state, 0, ',', '.') : '—')
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

                Tables\Columns\TextColumn::make('komisi_kios_baru_per_mika')
                    ->label('Komisi Kios Baru')
                    ->money('IDR')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

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
                        ->before(function (Collection $records, Tables\Actions\DeleteBulkAction $action) {
                            foreach ($records as $record) {
                                if ($reason = static::deleteBlockReason($record)) {
                                    Notification::make()->danger()->title('Batal menghapus')->body("{$record->name}: {$reason}")->persistent()->send();
                                    $action->halt();
                                }
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Alasan ramah kenapa $record tak boleh dihapus, atau null kalau boleh.
     * Gabungkan guard lockout (akun sendiri) + guard berdata/role (model).
     */
    protected static function deleteBlockReason(User $record): ?string
    {
        if ((int) $record->id === (int) auth()->id()) {
            return 'Tidak bisa menghapus akun sendiri.';
        }

        return $record->deletionBlockReason();
    }

    public static function getEloquentQuery(): Builder
    {
        // Akun sistem (operator.migrasi.*) selalu disembunyikan dari panel — tak bisa
        // dilihat/diedit/diaktifkan/dihapus dari UI (route edit/delete-nya jadi 404).
        $query = parent::getEloquentQuery()->excludeSystem();

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

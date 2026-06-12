<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
                            ->options(fn (): array => auth()->user()?->isSuperAdmin()
                                ? [
                                    'super_admin' => 'Super Admin',
                                    'owner' => 'Owner',
                                    'operator' => 'Operator',
                                ]
                                : ['operator' => 'Operator'])
                            ->default('operator')
                            ->required()
                            ->live()
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

                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Tarif Komisi')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(1)
                            ->suffix('(0.20 = 20%)')
                            ->placeholder('0.20')
                            ->helperText('Format desimal: 0.20 = 20%. Hanya untuk operator. Kosongkan untuk owner.')
                            ->visible(fn (Get $get) => $get('role') === 'operator'),

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
                            ->helperText('Nonaktifkan untuk disable login user ini'),
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

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Komisi')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 0).'%' : '—')
                    ->alignCenter()
                    ->sortable(),

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

                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label('Verifikasi')
                    ->dateTime('d M Y')
                    ->placeholder('Belum')
                    ->sortable()
                    ->toggleable(),

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
                    ->before(function ($record) {
                        if ($record->id === auth()->id()) {
                            throw new \Exception('Tidak bisa hapus akun sendiri');
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->isSuperAdmin()) {
            return $query; // super admin lihat semua user
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

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperatorResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OperatorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Operator';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Operator';

    protected static ?string $pluralModelLabel = 'Operator';

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
                            ->placeholder('operator@cemilanqontas.id'),

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

                Section::make('Komisi & Status')
                    ->schema([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Tarif Komisi')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(1)
                            ->default(0.20)
                            ->suffix('(0.20 = 20%)')
                            ->placeholder('0.20')
                            ->helperText('Format desimal: 0.20 = 20%')
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan untuk disable login operator ini'),
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

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Komisi')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 0).'%' : '—')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'operator';
                        $data['owner_id'] = auth()->id();
                        return $data;
                    }),
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
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'operator')
            ->where('owner_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageOperators::route('/'),
        ];
    }
}

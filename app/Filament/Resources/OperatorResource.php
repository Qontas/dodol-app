<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperatorResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                            // Simpan email selalu ternormalisasi (trim + lowercase) — konsisten
                            // dgn User::email mutator & jalur login. Cegah email tersimpan berspasi.
                            ->dehydrateStateUsing(fn (string $state): string => mb_strtolower(trim($state)))
                            ->placeholder('operator@cemilanqontas.id'),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->minLength(8)
                            ->maxLength(255)
                            // Cegah Chrome mengisi sendiri field ini saat modal Edit dibuka —
                            // terisi = dehydrated = password operator ketimpa diam-diam.
                            // Lihat penjelasan panjang di UserResource.
                            ->autocomplete('new-password')
                            ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $context): string => $context === 'create'
                                ? 'Min. 8 karakter'
                                : 'Kosongkan kalau tidak ingin mengubah password'),
                    ]),

                Section::make('Status')
                    ->schema([
                        // Field 'Tarif Komisi' persen DIBUANG: legacy, tak dipakai bayar.
                        // Komisi operator = Rp/mika drop dari setting owner (owner/settings).
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

                // Komisi/Mika = tarif Rp per mika drop dari setting owner (angka yang
                // SUNGGUH dipakai bayar). Kolom persen lama menyesatkan → dibuang.
                Tables\Columns\TextColumn::make('komisi_per_mika')
                    ->label('Komisi/Mika')
                    ->state(fn (): float => (float) (auth()->user()?->getKomisiPerMikaValue() ?? 1000))
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->alignCenter(),

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
                    // Tooltip alasan dipasang di elemen PEMBUNGKUS — tooltip pada tombol
                    // disabled tak pernah muncul (Tippy menolak selama atribut `disabled`
                    // ada). Lihat catatan panjang di view-nya.
                    ->view('filament.actions.link-action-tooltip-wrapped')
                    // Operator berdata (punya trip/komisi) → tombol DISABLED + tooltip alasan;
                    // operator bersih → tombol normal. Lapisan UI di atas guard server-side.
                    // RENDER pakai ForDisplay (baca count hasil withCount → 0 query/baris).
                    ->disabled(fn (User $record): bool => $record->deletionBlockReasonForDisplay() !== null)
                    ->tooltip(fn (User $record): ?string => $record->deletionBlockReasonForDisplay())
                    // ⚠️ KEPUTUSAN hapus tetap REAL-TIME, bukan angka render yang bisa basi.
                    ->before(function (User $record, Tables\Actions\DeleteAction $action) {
                        if ($reason = $record->deletionBlockReason()) {
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
                            $blocked = [];
                            foreach ($records as $record) {
                                if ($reason = $record->deletionBlockReason()) {
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

    public static function getEloquentQuery(): Builder
    {
        // Sembunyikan akun sistem (operator.migrasi.*) dari daftar operator owner.
        return parent::getEloquentQuery()
            ->excludeSystem()
            // Hitungan dependensi guard delete ikut query utama (subquery berkorelasi),
            // bukan 2 COUNT × 2 pemanggil (->disabled + ->tooltip) per baris. Baris di
            // sini SELALU operator → cukup dua count ini (tak perlu count owner).
            ->withCount(['operatedTrips', 'commissions'])
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

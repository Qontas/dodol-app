<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppFeeInvoiceResource\Pages;
use App\Models\AppFeeInvoice;
use App\Models\User;
use App\Support\AppFeeInvoiceService;
use App\Support\IdDate;
use App\Support\InvoiceException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Invoice Fee" — daftar & aksi invoice fee aplikasi per owner per bulan.
 * SUPER ADMIN SAJA: panel /admin juga bisa dimasuki owner (authMiddleware
 * owner,super_admin), jadi gerbang ada di canAccess() — owner/operator tak melihat
 * menu ini & URL-nya 403. Model tanpa OwnerScope by design (lihat AppFeeInvoice).
 */
class AppFeeInvoiceResource extends Resource
{
    protected static ?string $model = AppFeeInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Invoice Fee';

    protected static ?string $modelLabel = 'Invoice Fee';

    protected static ?string $pluralModelLabel = 'Invoice Fee';

    protected static ?string $slug = 'invoice-fee';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false; // invoice dibuat lewat aksi "Terbitkan", bukan form biasa
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // tak pernah dihapus — batalkan (void) saja
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('owner:id,name,business_name');
    }

    /** @return array<int, string> id => nama, owner saja */
    public static function ownerOptions(): array
    {
        return User::query()->where('role', 'owner')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Aksi form yang dipakai header "Terbitkan Invoice" & row "Terbitkan Ulang". */
    public static function issueAndNotify(User $owner, string $period): ?AppFeeInvoice
    {
        try {
            $invoice = AppFeeInvoiceService::issue($owner, $period, auth()->user());
        } catch (InvoiceException $e) {
            Notification::make()->title('Invoice tidak diterbitkan')->body($e->getMessage())->danger()->send();

            return null;
        }

        Notification::make()->title("Invoice {$invoice->number} terbit")->success()->send();

        return $invoice;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label('Periode')
                    ->formatStateUsing(fn (string $state): string => IdDate::bulanTahun($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('owner.name')->label('Owner')->searchable(),
                Tables\Columns\TextColumn::make('number')->label('Nomor')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('total_mika')
                    ->label('Mika')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state, 0, ',', '.')),
                Tables\Columns\TextColumn::make('total_fee')
                    ->label('Total (Rp)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.')),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AppFeeInvoice $r): string => $r->isOverdue() ? 'overdue' : $r->status)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'issued' => 'Terbit',
                        'paid' => 'Lunas',
                        'void' => 'Dibatalkan',
                        'overdue' => 'Lewat jatuh tempo',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'void' => 'gray',
                        'overdue' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->formatStateUsing(fn ($state): string => IdDate::tanggal(Carbon::parse($state)))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('owner_id')->label('Owner')->options(fn (): array => static::ownerOptions()),
                Tables\Filters\SelectFilter::make('period')
                    ->label('Periode')
                    ->options(fn (): array => AppFeeInvoice::query()->select('period')->distinct()->orderByDesc('period')->pluck('period')
                        ->mapWithKeys(fn (string $p): array => [$p => IdDate::bulanTahun($p)])->all()),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                    'issued' => 'Terbit',
                    'paid' => 'Lunas',
                    'void' => 'Dibatalkan',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Unduh PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (AppFeeInvoice $r): string => route('admin.invoice-pdf.show', $r)),

                Tables\Actions\Action::make('markPaid')
                    ->label('Tandai Lunas')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AppFeeInvoice $r): bool => $r->status === AppFeeInvoice::STATUS_ISSUED)
                    ->form([
                        Forms\Components\DatePicker::make('paid_at')->label('Tanggal bayar')->required()->default(today())->maxDate(today()),
                        Forms\Components\TextInput::make('paid_note')->label('Catatan (opsional)')->maxLength(255)->placeholder('Contoh: Transfer BCA'),
                    ])
                    ->action(function (AppFeeInvoice $r, array $data): void {
                        try {
                            AppFeeInvoiceService::markPaid($r, Carbon::parse($data['paid_at']), $data['paid_note'] ?? null);
                        } catch (InvoiceException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Ditandai lunas')->success()->send();
                    }),

                Tables\Actions\Action::make('unmarkPaid')
                    ->label('Batal Lunas')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (AppFeeInvoice $r): bool => $r->status === AppFeeInvoice::STATUS_PAID)
                    ->requiresConfirmation()
                    ->modalDescription('Status lunas dibatalkan; invoice kembali berstatus Terbit.')
                    ->action(function (AppFeeInvoice $r): void {
                        try {
                            AppFeeInvoiceService::unmarkPaid($r);
                        } catch (InvoiceException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Status lunas dibatalkan')->success()->send();
                    }),

                Tables\Actions\Action::make('void')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AppFeeInvoice $r): bool => $r->status !== AppFeeInvoice::STATUS_VOID)
                    ->modalDescription('Invoice dibatalkan permanen (PDF tetap bisa diunduh dengan cap DIBATALKAN). Setelah itu boleh diterbitkan ulang dengan nomor baru.')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Alasan pembatalan')->required()->maxLength(500)->rows(3),
                    ])
                    ->action(function (AppFeeInvoice $r, array $data): void {
                        try {
                            AppFeeInvoiceService::void($r, $data['reason']);
                        } catch (InvoiceException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Invoice dibatalkan')->success()->send();
                    }),

                Tables\Actions\Action::make('reissue')
                    ->label('Terbitkan Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (AppFeeInvoice $r): bool => $r->status === AppFeeInvoice::STATUS_VOID
                        && ! AppFeeInvoiceService::activeFor($r->owner_id, $r->period))
                    ->requiresConfirmation()
                    ->modalDescription('Akan terbit invoice BARU (nomor baru) dengan angka & pengaturan penerbit terkini.')
                    ->action(function (AppFeeInvoice $r): void {
                        if ($owner = User::query()->where('role', 'owner')->find($r->owner_id)) {
                            static::issueAndNotify($owner, $r->period);
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada invoice')
            ->emptyStateDescription('Gunakan "Terbitkan Invoice" untuk bulan yang sudah lewat, atau "Buat / Unduh Draft".');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppFeeInvoices::route('/'),
        ];
    }
}

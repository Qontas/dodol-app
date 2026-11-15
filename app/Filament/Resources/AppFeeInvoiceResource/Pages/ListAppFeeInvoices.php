<?php

namespace App\Filament\Resources\AppFeeInvoiceResource\Pages;

use App\Filament\Pages\InvoiceSettingsPage;
use App\Filament\Resources\AppFeeInvoiceResource;
use App\Models\User;
use App\Support\AppFeeInvoiceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAppFeeInvoices extends ListRecords
{
    protected static string $resource = AppFeeInvoiceResource::class;

    /** Recheck on every Livewire request, not just the initial mount. */
    public function boot(): void
    {
        abort_unless(AppFeeInvoiceResource::canAccess(), 403);
    }

    /** @var array<string, array{draft: bool, issue: bool, message: string}> */
    private array $availability = [];

    /** @return array{draft: bool, issue: bool, message: string} */
    private function invoiceAvailability(mixed $ownerId, mixed $period): array
    {
        if (! $ownerId || ! is_string($period) || ! AppFeeInvoiceService::validPeriod($period)) {
            return ['draft' => false, 'issue' => false, 'message' => 'Pilih owner dan bulan untuk melihat fee.'];
        }

        $key = $ownerId.':'.$period;
        if (isset($this->availability[$key])) {
            return $this->availability[$key];
        }

        $owner = User::query()->where('role', 'owner')->find($ownerId);
        if (! $owner || ! AppFeeInvoiceService::periodNotFuture($period)) {
            return $this->availability[$key] = ['draft' => false, 'issue' => false, 'message' => 'Owner tidak ditemukan atau bulan masih di masa depan.'];
        }

        // A frozen invoice remains downloadable even when today's live fee is zero.
        if ($invoice = AppFeeInvoiceService::activeFor($owner->id, $period)) {
            return $this->availability[$key] = ['draft' => true, 'issue' => false, 'message' => "Invoice {$invoice->number} sudah terbit dan bisa diunduh. Batalkan dulu untuk menerbitkan ulang."];
        }

        $data = AppFeeInvoiceService::compute($owner, $period);
        if ($data['total_mika'] <= 0 || $data['total_fee'] <= 0) {
            return $this->availability[$key] = ['draft' => false, 'issue' => false, 'message' => 'Fee owner ini di bulan itu Rp 0 — tidak perlu invoice.'];
        }

        $ended = AppFeeInvoiceService::periodHasEnded($period);

        return $this->availability[$key] = [
            'draft' => true,
            'issue' => $ended,
            'message' => 'Total fee: Rp '.number_format($data['total_fee'], 0, ',', '.').($ended ? '.' : '. Bulan berjalan hanya bisa dibuat draft.'),
        ];
    }

    private function canSubmitInvoiceAction(string $type): bool
    {
        $state = $this->getMountedActionForm()?->getRawState() ?? [];

        return $this->invoiceAvailability($state['owner_id'] ?? null, $state['period'] ?? null)[$type];
    }

    /** Field owner + bulan dipakai ulang oleh dua aksi header. */
    private function ownerPeriodForm(): array
    {
        return [
            Forms\Components\Select::make('owner_id')
                ->label('Owner')
                ->options(fn (): array => AppFeeInvoiceResource::ownerOptions())
                ->required()
                ->live()
                ->searchable(),
            Forms\Components\TextInput::make('period')
                ->label('Bulan')
                ->type('month')
                ->required()
                ->live()
                ->default(today()->format('Y-m'))
                ->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/'),
            Forms\Components\Placeholder::make('fee_info')
                ->label('Informasi Fee')
                ->content(fn (Forms\Get $get): string => $this->invoiceAvailability($get('owner_id'), $get('period'))['message']),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('draft')
                ->label('Buat / Unduh Draft')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->modalHeading('Buat / Unduh Draft')
                ->modalDescription('Kalau invoice bulan itu sudah terbit, yang diunduh invoice terbitnya. Kalau belum, draft sementara (tanpa nomor, watermark DRAFT).')
                ->modalSubmitActionLabel('Unduh')
                ->modalSubmitAction(fn (Actions\StaticAction $action): Actions\StaticAction => $action->disabled(! $this->canSubmitInvoiceAction('draft')))
                ->form($this->ownerPeriodForm())
                ->action(function (array $data) {
                    if (! AppFeeInvoiceService::periodNotFuture($data['period'])) {
                        Notification::make()->title('Bulan di masa depan belum bisa dibuat invoice')->danger()->send();

                        return;
                    }
                    $owner = User::query()->where('role', 'owner')->find($data['owner_id']);
                    if (! $owner) {
                        Notification::make()->title('Owner tidak ditemukan')->danger()->send();

                        return;
                    }
                    if (! AppFeeInvoiceService::activeFor($owner->id, $data['period'])) {
                        $summary = AppFeeInvoiceService::compute($owner, $data['period']);
                        if ($summary['total_mika'] <= 0 || $summary['total_fee'] <= 0) {
                            Notification::make()->title('Fee owner ini di bulan itu Rp 0')->body('Tidak perlu invoice.')->warning()->send();

                            return;
                        }
                    }

                    return redirect()->route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => $data['period']]);
                }),

            Actions\Action::make('issue')
                ->label('Terbitkan Invoice')
                ->icon('heroicon-o-paper-airplane')
                ->modalHeading('Terbitkan Invoice')
                ->modalDescription('Hanya untuk bulan yang SUDAH LEWAT. Angka & pengaturan penerbit dibekukan saat terbit; nomor tidak bisa dipakai ulang.')
                ->modalSubmitActionLabel('Terbitkan')
                ->modalSubmitAction(fn (Actions\StaticAction $action): Actions\StaticAction => $action->disabled(! $this->canSubmitInvoiceAction('issue')))
                ->form($this->ownerPeriodForm())
                ->action(function (array $data): void {
                    $owner = User::query()->where('role', 'owner')->find($data['owner_id']);
                    if (! $owner) {
                        Notification::make()->title('Owner tidak ditemukan')->danger()->send();

                        return;
                    }
                    AppFeeInvoiceResource::issueAndNotify($owner, $data['period']);
                }),

            Actions\Action::make('settings')
                ->label('Pengaturan Invoice')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(InvoiceSettingsPage::getUrl()),
        ];
    }
}

<?php

namespace App\Filament\Pages;

use App\Support\InvoiceSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pengaturan penerbit invoice fee aplikasi — SUPER ADMIN SAJA. Page ini di-discover
 * panel admin; owner yang masuk /admin tetap ditolak lewat canAccess().
 */
class InvoiceSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Pengaturan Invoice';

    protected static ?string $title = 'Pengaturan Invoice';

    protected static ?string $slug = 'pengaturan-invoice';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.invoice-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /** Block hydration/read endpoints as well as save after a role change. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(InvoiceSettings::get());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Penerbit')
                    ->description('Tampil di blok "Dari" pada invoice. Perubahan hanya berlaku untuk invoice yang terbit sesudahnya.')
                    ->schema([
                        Forms\Components\TextInput::make('issuer_name')->label('Nama penerbit')->required()->maxLength(120),
                        Forms\Components\Textarea::make('address')->label('Alamat')->rows(2)->maxLength(300),
                        Forms\Components\TextInput::make('phone')->label('No. WA')->tel()->maxLength(30)
                            ->regex('/^[0-9+\-\s()]*$/')->validationMessages(['regex' => 'Hanya angka, spasi, +, -, ( ).']),
                        Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(120),
                    ])->columns(2),
                Forms\Components\Section::make('Pembayaran')
                    ->schema([
                        Forms\Components\TextInput::make('bank_name')->label('Nama bank')->maxLength(60),
                        Forms\Components\TextInput::make('account_number')->label('No. rekening')->maxLength(40)
                            ->regex('/^[0-9\s\-]*$/')->validationMessages(['regex' => 'Hanya angka, spasi, dan tanda hubung.']),
                        Forms\Components\TextInput::make('account_holder')->label('Atas nama')->maxLength(120),
                        Forms\Components\TextInput::make('due_days')->label('Jatuh tempo (hari setelah terbit)')
                            ->numeric()->integer()->minValue(1)->maxValue(90)->required(),
                        Forms\Components\Textarea::make('footer_note')->label('Catatan kaki')->rows(3)->maxLength(500)->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        InvoiceSettings::save($this->form->getState());

        Notification::make()->title('Pengaturan invoice disimpan')->success()->send();
    }
}

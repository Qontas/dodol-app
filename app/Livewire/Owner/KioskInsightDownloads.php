<?php

namespace App\Livewire\Owner;

use App\Models\User;
use App\Support\KioskInsightExport;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Halaman "Unduh Laporan Kedai". Komponen SAMA dipakai dua tempat:
 *  - owner: halaman penuh /kiosk-insights di layout panel owner;
 *  - super admin: disematkan ke halaman Filament panel admin (/admin/laporan-kedai) dengan $embedded = true.
 * Super admin yang membuka /kiosk-insights dialihkan ke panel admin (sidebar owner tidak untuk dia).
 */
class KioskInsightDownloads extends Component
{
    public string $ownerId = '';

    #[Locked]
    public ?string $exportId = null;

    #[Locked]
    public array $progress = [];

    #[Locked]
    public bool $embedded = false;

    public function boot(): void
    {
        KioskInsightExport::authorize();
    }

    public function mount(bool $embedded = false)
    {
        $user = auth()->user();
        if ($user->isSuperAdmin() && ! $embedded) {
            $url = self::adminUrl();
            if (request()->query('export')) {
                $url .= '?'.http_build_query(['export' => (string) request()->query('export')]);
            }

            return $this->redirect($url, navigate: false);
        }
        $this->embedded = $embedded;
        if ($user->isOwner()) {
            $this->ownerId = (string) auth()->id();
        }
        // UUID in the URL is only a resume pointer, never authorization. status() checks creator,
        // current role, tenant and expiry before disclosing even progress.
        if (request()->query('export')) {
            $this->exportId = (string) request()->query('export');
            $this->progress = KioskInsightExport::status($this->exportId);
        }
    }

    public static function adminUrl(): string
    {
        return route('filament.admin.pages.laporan-kedai');
    }

    /** Link kembali sesuai peran: owner → Manajemen Kios; super admin → Dashboard admin. */
    public static function backLink(): array
    {
        return auth()->user()?->isSuperAdmin()
            ? ['url' => route('filament.admin.pages.dashboard'), 'label' => '← Dashboard']
            : ['url' => route('filament.owner.resources.kiosks.index'), 'label' => '← Manajemen Kios'];
    }

    public function generate(string $format): void
    {
        $user = KioskInsightExport::authorize();
        if ($user->isOwner()) {
            abort_unless($this->ownerId === (string) $user->id, 403);
        }
        $this->validate(['ownerId' => ['nullable', 'integer', 'min:1']]);
        $this->exportId = KioskInsightExport::start($format, $this->ownerId === '' ? null : (int) $this->ownerId);
        $this->progress = KioskInsightExport::status($this->exportId);
        // Plain Excel has no photo/network IO and is immediately ready on this request.
        if ($format === 'excel') {
            $this->progress = KioskInsightExport::tick($this->exportId);
        }
    }

    /** Satu langkah export. Dipanggil berulang oleh browser; juga tombol "Lanjutkan" setelah gangguan. */
    public function advance(): void
    {
        if ($this->exportId && ! ($this->progress['ready'] ?? false) && ! ($this->progress['failed'] ?? false)) {
            $next = KioskInsightExport::tick($this->exportId);
            // Tick lain masih berjalan (atau baru saja terputus): pertahankan pesan terakhir, coba lagi nanti.
            if (($next['stage'] ?? null) !== 'busy') {
                $this->progress = $next;
            }
        }
    }

    public function render()
    {
        $view = view('livewire.owner.kiosk-insight-downloads', [
            'owners' => auth()->user()->isSuperAdmin()
                ? User::where('role', 'owner')->orderBy('name')->get(['id', 'name', 'business_name'])
                : collect(),
            'back' => self::backLink(),
        ]);

        return $this->embedded ? $view : $view->extends('layouts.owner')->section('content');
    }
}

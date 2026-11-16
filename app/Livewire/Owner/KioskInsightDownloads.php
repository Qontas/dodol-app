<?php

namespace App\Livewire\Owner;

use App\Models\User;
use App\Support\KioskInsightExport;
use Livewire\Attributes\Locked;
use Livewire\Component;

class KioskInsightDownloads extends Component
{
    public string $ownerId = '';

    #[Locked]
    public ?string $exportId = null;

    #[Locked]
    public array $progress = [];

    public function boot(): void
    {
        KioskInsightExport::authorize();
    }

    public function mount(): void
    {
        if (auth()->user()->isOwner()) {
            $this->ownerId = (string) auth()->id();
        }
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

    public function advance(): void
    {
        if ($this->exportId && ! ($this->progress['ready'] ?? false) && ! ($this->progress['failed'] ?? false)) {
            $this->progress = KioskInsightExport::tick($this->exportId);
        }
    }

    public function render()
    {
        return view('livewire.owner.kiosk-insight-downloads', [
            'owners' => auth()->user()->isSuperAdmin()
                ? User::where('role', 'owner')->orderBy('name')->get(['id', 'name', 'business_name'])
                : collect(),
        ])->extends('layouts.owner')->section('content');
    }
}

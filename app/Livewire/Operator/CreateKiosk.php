<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.operator')]
class CreateKiosk extends Component
{
    use WithFileUploads;

    public string $namaKios = '';
    public string $namaPemilik = '';
    public string $telepon = '';
    public ?int $clusterId = null;
    public int $defaultQtyMika = 1;
    public ?float $latitude = null;
    public ?float $longitude = null;
    public $foto = null;

    public function mount(): void
    {
        // Pre-pilih cluster kalau cuma ada satu
        $clusters = $this->clusters;
        if ($clusters->count() === 1) {
            $this->clusterId = (int) $clusters->first()->id;
        }
    }

    public function getClustersProperty()
    {
        $ownerId = auth()->user()->owner_id;

        return Cluster::query()
            ->where('is_active', true)
            ->when($ownerId !== null, fn($q) => $q->where('owner_id', $ownerId))
            ->orderBy('name')
            ->get();
    }

    public function saveKiosk()
    {
        $validated = $this->validate([
            'namaKios' => 'required|string|max:255',
            'namaPemilik' => 'required|string|max:255',
            'clusterId' => 'nullable|exists:clusters,id',
            'defaultQtyMika' => 'required|integer|min:1',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'telepon' => 'nullable|string|max:20',
            'foto' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'namaKios.required' => 'Nama kios wajib diisi',
            'namaPemilik.required' => 'Nama pemilik wajib diisi',
            'clusterId.exists' => 'Area tidak valid',
            'defaultQtyMika.required' => 'Isi default jumlah mika',
            'defaultQtyMika.min' => 'Minimal 1 mika',
        ]);

        $kiosk = DB::transaction(function () {
            return Kiosk::create([
                'name' => $this->namaKios,
                'owner_name' => $this->namaPemilik,
                'phone' => $this->telepon ?: null,
                'cluster_id' => $this->clusterId ?: null,
                'default_qty_mika' => $this->defaultQtyMika,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'is_active' => true,
                // Kios dibuat operator di lapangan = titip pertama hari ini → kios baru.
                'first_titip_date' => today(),
                'notes' => 'Input lapangan oleh operator: '.auth()->user()->name.' (id='.auth()->id().')',
            ]);
        });

        // Foto opsional dari lapangan: disimpan ke disk media (configurable via
        // MEDIA_DISK — lokal default, R2/S3 saat siap). Kompres utama dilakukan di
        // browser sebelum upload; resize server-side hanya jaring pengaman & hanya
        // jalan di disk lokal (Storage::path() local-only).
        if ($this->foto) {
            $disk = config('app.media_disk', 'public');
            $path = $this->foto->store('kiosks', $disk);
            $this->resizeImageIfLocal($path, $disk, 1280, 1280);
            $kiosk->update(['photo_path' => $path]);
        }

        session()->flash('kios_saved', 'Kios baru berhasil ditambahkan.');

        $activeTrip = \App\Models\Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();

        if ($activeTrip) {
            return $this->redirect(route('operator.trip.active', $activeTrip->id), navigate: true);
        }

        return $this->redirect(route('operator.dashboard'), navigate: true);
    }

    /**
     * Resize server-side sebagai jaring pengaman. Hanya disk lokal (driver 'local')
     * yang mendukung Storage::path(); pada disk cloud (R2/S3) dilewati karena foto
     * sudah dikompres di browser sebelum upload.
     */
    private function resizeImageIfLocal(string $path, string $disk, int $maxW, int $maxH): void
    {
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return;
        }

        $fullPath = \Illuminate\Support\Facades\Storage::disk($disk)->path($path);
        $info = getimagesize($fullPath);
        if (!$info) {
            return;
        }
        [$origW, $origH, $type] = [$info[0], $info[1], $info[2]];
        if ($origW <= $maxW && $origH <= $maxH) {
            return;
        }
        $ratio = min($maxW / $origW, $maxH / $origH);
        $newW = (int) round($origW * $ratio);
        $newH = (int) round($origH * $ratio);
        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($fullPath),
            IMAGETYPE_PNG => imagecreatefrompng($fullPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($fullPath),
            default => null,
        };
        if (!$src) {
            return;
        }
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($dst, $fullPath, 85),
            IMAGETYPE_PNG => imagepng($dst, $fullPath),
            IMAGETYPE_WEBP => imagewebp($dst, $fullPath, 85),
            default => null,
        };
        imagedestroy($src);
        imagedestroy($dst);
    }

    public function render()
    {
        return view('livewire.operator.create-kiosk', [
            'clusters' => $this->clusters,
        ]);
    }
}

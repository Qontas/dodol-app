<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        $ownerId = auth()->user()->owner_id;

        $validated = $this->validate([
            'namaKios' => 'required|string|max:255',
            'namaPemilik' => 'required|string|max:255',
            // Area WAJIB (kios tanpa area = invisible di list owner karena di-filter
            // cluster.owner_id). exists di-scope ke area AKTIF milik owner operator →
            // cegah kios nyangkut & tolak clusterId owner lain walau di-utak-atik dari klien.
            'clusterId' => ['required', Rule::exists('clusters', 'id')->where(function ($q) use ($ownerId) {
                $q->where('is_active', true);
                if ($ownerId !== null) {
                    $q->where('owner_id', $ownerId);
                }
            })],
            'defaultQtyMika' => 'required|integer|min:1',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'telepon' => 'nullable|string|max:20',
            'foto' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'namaKios.required' => 'Nama kios wajib diisi',
            'namaPemilik.required' => 'Nama pemilik wajib diisi',
            'clusterId.required' => 'Pilih area dulu',
            'clusterId.exists' => 'Area tidak valid',
            'defaultQtyMika.required' => 'Isi default jumlah mika',
            'defaultQtyMika.min' => 'Minimal 1 mika',
        ]);

        $kiosk = DB::transaction(function () {
            return Kiosk::create([
                'name' => $this->namaKios,
                'owner_name' => $this->namaPemilik,
                'phone' => $this->telepon ?: null,
                'cluster_id' => $this->clusterId, // wajib & tervalidasi milik owner.
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
        // browser sebelum upload; ImageResizer jaring pengaman server-side yang kini
        // jalan di disk LOCAL maupun CLOUD (R2/S3).
        if ($this->foto) {
            $disk = config('app.media_disk', 'public');
            $path = $this->foto->store('kiosks', $disk);
            \App\Support\ImageResizer::fit($path, $disk, 1280, 1280);
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

    public function render()
    {
        return view('livewire.operator.create-kiosk', [
            'clusters' => $this->clusters,
        ]);
    }
}

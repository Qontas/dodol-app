<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Support\GoogleMapsShortLinkResolver;
use App\Support\KioskLocationParser;
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
    public ?string $mapsInput = null;
    public $foto = null;

    // JENIS KEDAI saat input (owner input kedai KARENA sudah titip di sana; operator
    // nemu kedai baru sambil naruh dodol). 'konsinyasi' = ada titipan berjalan + jatah;
    // 'cash_only' = beli putus, tak ada titipan/jatah.
    public string $jenisKedai = 'konsinyasi';

    // KONSINYASI: berapa mika titipan berjalan SAAT INI (belum dibayar). >=1 → otomatis
    // bikin titipan berjalan (OpeningBalance) supaya kedai LANGSUNG bisa "Tagih + Titip
    // Ulang" di kunjungan pertama.
    public int $titipanBerjalan = 0;

    // CATATAN KEDAI (teks bebas) — karakteristik kedai, tampil menonjol ke operator.
    public string $storeNote = '';

    // Tier 1 (koordinat/link panjang) = murni parsing, instan. Tier 2 (link
    // pendek maps.app.goo.gl/goo.gl) = best-effort via redirect resolve —
    // lihat GoogleMapsShortLinkResolver utk safeguard SSRF-nya.
    public function jumpToMapsLocation(): void
    {
        $this->resetErrorBag('mapsInput');

        $input = trim((string) $this->mapsInput);
        if ($input === '') {
            $this->addError('mapsInput', 'Tempel link atau koordinat Google Maps dulu.');
            return;
        }

        $coords = KioskLocationParser::parse($input);

        if ($coords === null && GoogleMapsShortLinkResolver::isEligible($input)) {
            $resolved = GoogleMapsShortLinkResolver::resolve($input);
            $coords = $resolved !== null ? KioskLocationParser::parse($resolved) : null;

            if ($coords === null) {
                $this->addError('mapsInput', 'Link pendek tidak bisa dibaca. Buka dulu link-nya di browser, lalu tempel link panjang atau koordinatnya di sini.');
                return;
            }
        }

        if ($coords === null) {
            $this->addError('mapsInput', 'Format tidak dikenali. Coba tempel koordinat langsung, contoh: 3.5896, 98.6739');
            return;
        }

        $this->latitude = $coords['lat'];
        $this->longitude = $coords['lng'];

        $this->dispatch('kiosk-location-jumped', lat: $coords['lat'], lng: $coords['lng']);
    }

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

        $isKonsinyasi = $this->jenisKedai === 'konsinyasi';

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
            'jenisKedai' => 'required|in:konsinyasi,cash_only',
            // Jatah hanya wajib untuk kedai KONSINYASI. Cash-only tak punya jatah.
            'defaultQtyMika' => $isKonsinyasi ? 'required|integer|min:1' : 'nullable|integer|min:1',
            // Titipan berjalan saat ini (konsinyasi) — 0 kalau kebetulan belum ada.
            'titipanBerjalan' => 'nullable|integer|min:0|max:1000',
            'storeNote' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'telepon' => 'nullable|string|max:20',
            'foto' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'namaKios.required' => 'Nama kios wajib diisi',
            'namaPemilik.required' => 'Nama pemilik wajib diisi',
            'clusterId.required' => 'Pilih area dulu',
            'clusterId.exists' => 'Area tidak valid',
            'defaultQtyMika.required' => 'Isi jatah (mika biasa dititip)',
            'defaultQtyMika.min' => 'Minimal 1 mika',
        ]);

        $kiosk = DB::transaction(function () use ($isKonsinyasi) {
            $kiosk = Kiosk::create([
                'name' => $this->namaKios,
                'owner_name' => $this->namaPemilik,
                'phone' => $this->telepon ?: null,
                'cluster_id' => $this->clusterId, // wajib & tervalidasi milik owner.
                // Jatah hanya untuk konsinyasi; cash-only tak punya jatah.
                'default_qty_mika' => $isKonsinyasi ? $this->defaultQtyMika : null,
                'is_cash_only' => ! $isKonsinyasi,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'is_active' => true,
                // Kios dibuat operator di lapangan = titip pertama hari ini → kios baru.
                'first_titip_date' => today(),
                'store_note' => trim($this->storeNote) ?: null,
                'notes' => 'Input lapangan oleh operator: '.auth()->user()->name.' (id='.auth()->id().')',
            ]);

            // KONSINYASI + titipan berjalan → bikin titipan (OpeningBalance) supaya kedai
            // LANGSUNG bisa "Tagih + Titip Ulang" di kunjungan pertama. Idempoten.
            if ($isKonsinyasi && (int) $this->titipanBerjalan >= 1) {
                \App\Support\OpeningBalance::create($kiosk, (int) $this->titipanBerjalan);
            }

            return $kiosk;
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

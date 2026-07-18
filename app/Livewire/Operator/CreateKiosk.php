<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Support\ConsignmentDrop;
use App\Support\GoogleMapsShortLinkResolver;
use App\Support\KioskLocationParser;
use App\Support\KioskPhoto;
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

    // JENIS KEDAI saat input. TIGA opsi sejajar:
    //  'konsinyasi' = operator MENARUH dodol sekarang (butuh TRIP AKTIF). Titipan dicatat
    //                 ke TRIP AKTIF (ConsignmentDrop) → stok trip berkurang + KOMISI operator
    //                 terhitung. Tanpa trip aktif, pilihan ini otomatis jatuh ke booking.
    //  'cash_only'  = beli putus, tak ada titipan/jatah.
    //  'booking'    = catat IDENTITAS saja (belum ada dodol). Jenis final ditentukan operator
    //                 nanti di lapangan (Titip Cash → cash-only / Mulai Titipan → konsinyasi).
    public string $jenisKedai = 'konsinyasi';

    // Apakah operator sedang punya trip aktif (di-set di mount). Menentukan apakah field
    // "Jatah Titipan" boleh muncul: TANPA trip, operator tak bisa menaruh dodol (sistem tak
    // tahu stok mana yang keluar → celah). Titipan HANYA lewat trip aktif.
    public bool $hasActiveTrip = false;
    public ?int $activeTripId = null;

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

        // Trip aktif menentukan apakah operator boleh menaruh dodol saat daftar (poin 1 & 3).
        $trip = $this->resolveActiveTrip();
        $this->hasActiveTrip = $trip !== null;
        $this->activeTripId = $trip?->id;
    }

    /**
     * Trip operasional yang sedang berjalan milik operator ini HARI INI (started, belum
     * ended). Sumber kebenaran "apakah boleh menaruh dodol" + tujuan Delivery titipan.
     */
    private function resolveActiveTrip(): ?Trip
    {
        return Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();
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

        // Trip aktif = izin menaruh dodol. Re-resolve saat simpan (bukan andalkan snapshot
        // mount yang bisa basi kalau trip diakhiri di tab lain).
        $activeTrip = $this->resolveActiveTrip();
        $this->hasActiveTrip = $activeTrip !== null;
        $this->activeTripId = $activeTrip?->id;

        // KONSINYASI hanya BERARTI (menaruh dodol) kalau ada trip aktif. Tanpa trip, pilihan
        // konsinyasi jatuh ke booking: identitas saja, tanpa titipan (poin 3 — cegah stok
        // keluar tak tercatat). Titipan SELALU nempel ke trip aktif (poin 1).
        $titipToTrip = $this->jenisKedai === 'konsinyasi' && $activeTrip !== null;
        $isCashOnly = $this->jenisKedai === 'cash_only';

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
            'jenisKedai' => 'required|in:konsinyasi,cash_only,booking',
            // Jatah WAJIB hanya saat menaruh dodol ke trip aktif (konsinyasi + trip aktif).
            // Cash-only / booking / konsinyasi-tanpa-trip tak punya jatah saat daftar.
            'defaultQtyMika' => $titipToTrip ? 'required|integer|min:1|max:100' : 'nullable|integer|min:1',
            'storeNote' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'telepon' => 'nullable|string|max:20',
            // Plafon & format foto dipusatkan di App\Support\KioskPhoto (dulu tiap jalur
            // punya aturannya sendiri → perilaku beda-beda). Foto besar TIDAK ditolak
            // lagi: sudah dikompres di browser, dan plafon 20MB cuma jaring pengaman.
            'foto' => KioskPhoto::rules(),
        ], array_merge([
            'namaKios.required' => 'Nama kios wajib diisi',
            'namaPemilik.required' => 'Nama pemilik wajib diisi',
            'clusterId.required' => 'Pilih area dulu',
            'clusterId.exists' => 'Area tidak valid',
            'defaultQtyMika.required' => 'Isi jatah (mika biasa dititip)',
            'defaultQtyMika.min' => 'Minimal 1 mika',
        ], KioskPhoto::pesanValidasi('foto')));

        // HEIC di server tanpa delegate HEIF: tolak DI SINI dengan instruksi yang bisa
        // ditindaklanjuti, jangan biarkan tersimpan mentah (= foto blank buat yang lain).
        if (KioskPhoto::heicTakBisaDiproses($this->foto)) {
            $this->addError('foto', KioskPhoto::pesanHeicTakDidukung());

            return null;
        }

        $titipanGagal = false;

        $kiosk = DB::transaction(function () use ($titipToTrip, $isCashOnly, $activeTrip, &$titipanGagal) {
            $kiosk = Kiosk::create([
                'name' => $this->namaKios,
                'owner_name' => $this->namaPemilik,
                'phone' => $this->telepon ?: null,
                'cluster_id' => $this->clusterId, // wajib & tervalidasi milik owner.
                // Jatah HANYA di-set kalau dodol benar-benar ditaruh ke trip aktif.
                // Booking / konsinyasi-tanpa-trip → NULL (belum ada titipan/jatah).
                'default_qty_mika' => $titipToTrip ? $this->defaultQtyMika : null,
                'is_cash_only' => $isCashOnly,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'is_active' => true,
                // Kios dibuat operator di lapangan = titip pertama hari ini → kios baru.
                'first_titip_date' => today(),
                'store_note' => trim($this->storeNote) ?: null,
                'notes' => 'Input lapangan oleh operator: '.auth()->user()->name.' (id='.auth()->id().')',
            ]);

            // URUTAN RUTE: operator tak mengisi urutan → otomatis TERAKHIR di cluster
            // (bukan NULL yang jatuh ke bawah tanpa nomor & mengacaukan urutan rute).
            Kiosk::appendToClusterOrder($kiosk);

            // 🔴 TITIPAN → TRIP AKTIF: dodol yang ditaruh operator dicatat sebagai delivery
            // konsinyasi pada TRIP AKTIF (bukan trip migrasi sentinel). Karena nempel ke trip
            // aktif, ia otomatis ikut getTotalDropReal() → STOK trip berkurang + KOMISI operator
            // terhitung, TANPA jalur komisi terpisah. Reuse ConsignmentDrop (sama dgn OpeningBalance,
            // beda trip). Kios langsung bisa "Tagih + Titip Ulang" kunjungan berikutnya.
            if ($titipToTrip && (int) $this->defaultQtyMika >= 1) {
                $delivery = ConsignmentDrop::record($kiosk, $activeTrip, (int) $this->defaultQtyMika);
                // null = owner belum punya varian produk aktif → titipan tak tercatat, tapi
                // kios tetap tersimpan (jangan gagalkan semuanya). Beri tahu operator.
                $titipanGagal = $delivery === null;
            }

            return $kiosk;
        });

        if ($titipanGagal) {
            session()->flash('titipan_gagal', 'Kios tersimpan, tapi titipan belum tercatat (belum ada varian produk aktif). Hubungi owner untuk cek Master Data → Produk.');
        }

        // Foto opsional dari lapangan. Kompres utama di browser; KioskPhoto::store()
        // menangani konversi HEIC → JPG + ImageResizer (jaring pengaman server) dan
        // menyimpan ke disk media (MEDIA_DISK: lokal default, R2/S3 di produksi).
        if ($this->foto) {
            $path = KioskPhoto::store($this->foto);

            if ($path === null) {
                // Kios sudah tersimpan — jangan gagalkan semuanya cuma karena foto.
                session()->flash('foto_gagal', 'Kios tersimpan, tapi fotonya gagal diproses. Buka kios ini lalu unggah ulang fotonya.');
            } else {
                $kiosk->update(['photo_path' => $path]);
            }
        }

        session()->flash('kios_saved', 'Kios baru berhasil ditambahkan.');

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

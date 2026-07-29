<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Support\KioskPhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

#[Layout('layouts.operator', ['hideBottomNav' => true])]
class ActiveTrip extends Component
{
    use WithFileUploads;

    // Konstanta domain (jangan hardcode di method)
    public const BIJI_PER_MIKA = 15;
    public const HARGA_PER_BIJI = 800;

    // Alasan valid untuk mengakhiri trip
    public const VALID_END_REASONS = ['stock_habis', 'target_done', 'sakit', 'urgent_personal', 'other'];

    // Ukuran SATU BATCH kartu kios. Daftar dibatasi demi DOM ringan + payload kecil
    // di HP lapangan (owner besar punya ~957 kios).
    //
    // 🔴 BUKAN plafon keras lagi (bug 28 Juli 2026): dulu ini `limit()` mati di 50,
    // jadi area dengan 54 kedai berhenti diam-diam di kedai ber-sort_order 50
    // ("Bilal 3") — operator mengira area itu memang habis di situ. Sekarang batch
    // pertama 50, sisanya dijangkau lewat tombol "Muat lebih banyak" (loadMoreKiosks)
    // yang JELAS terlihat, bukan pemotongan senyap.
    public const DISPLAY_LIMIT = 50;

    // Batas kartu yang sedang ditampilkan; naik per DISPLAY_LIMIT tiap "Muat lebih
    // banyak". Di-reset ke batch pertama tiap kali daftar berganti konteks (cari /
    // urut jarak) supaya payload tak ikut membengkak diam-diam.
    public int $kioskLimit = self::DISPLAY_LIMIT;

    // Radius prefilter mode "Urutkan Jarak" (kilometer). Bounding box SQL memakai
    // index `idx_kiosks_geo(latitude, longitude)` supaya haversine PHP hanya jalan
    // untuk kios di sekitar operator — bukan SELURUH kios owner (~957).
    //
    // Batas atas beban = jumlah kios BER-GPS di dalam kotak. Cakupan GPS sekarang
    // ~12% (119 dari 1014), jadi himpunan itu kecil. Kalau suatu saat hampir semua
    // kios ber-GPS, kecilkan angka ini.
    public const GEO_BBOX_KM = 25;

    // --- STATE DASAR ---
    public $trip;
    public $starting_cluster_id;

    /**
     * Area yang sedang DILIHAT di daftar kunjungan (fitur lintas area, 29 Juli 2026).
     *   null → SEMUA area milik owner
     *   int  → satu cluster
     *
     * Diisi dari `trip->starting_cluster_id` saat mount. Menggantinya TIDAK mengubah
     * dan TIDAK mengakhiri trip — trip tetap yang itu juga, cuma daftarnya yang
     * bergeser. `starting_cluster_id` di atas tetap dipegang sebagai penanda
     * "area awal" (dipakai label & urutan), JANGAN dipakai lagi untuk memfilter.
     *
     * Jalur TULIS memang sudah siap lintas area: openVisitModal() → ownedKiosk()
     * hanya memeriksa OWNER, tak pernah memeriksa cluster. Yang dikunci selama ini
     * cuma DAFTARNYA.
     */
    public ?int $viewClusterId = null;

    /** Panel "Lihat Area Lain" sedang terbuka? (daftar area baru di-query saat perlu) */
    public bool $isAreaPickerOpen = false;

    // Pencarian kios (filter server-side by nama). Tetap bisa akses kios mana pun.
    public string $search = '';

    // CATATAN PERFORMA: daftar kios + flag + operator-terakhir + status kunjungan
    // TIDAK lagi disimpan sebagai public property. Dulu koleksi besar (s.d. ~957
    // kios beserta array flag & operator) ikut snapshot Livewire bolak-balik tiap
    // aksi → payload berat di HP lapangan. Sekarang dihitung saat render() sebagai
    // variabel view (lihat kioskViewData()), sehingga TIDAK masuk snapshot.

    // --- STATE GEO/NEAREST NEIGHBOR ---
    public bool $sortedByDistance = false;
    public ?float $userLat = null;
    public ?float $userLng = null;

    // --- STATE TRANSAKSI KUNJUNGAN ---
    public $isVisitModalOpen = false;
    public $selectedKiosk = null;
    public $pendingDelivery = null;

    // Foto kios yang di-upload operator dari lapangan (modal kunjungan). Kompres
    // utama di browser; ImageResizer jaring pengaman server-side. Lihat saveKioskPhoto().
    public $kioskPhoto = null;

    // Aksi yang dipilih operator di layar pertama modal (UI murni — aksi yang
    // TERSIMPAN tetap ditentukan resolveVisitAction() dari kondisi form).
    // null = masih di layar pilih aksi. Nilai 3-AKSI (owner 11 Juli 2026):
    //   AKSI 1 = tagih_titip (ada titipan) / titip (belum ada titipan) — siklus normal.
    //   AKSI 2 = titip_cash — naruh ekstra dibayar tunai, TIDAK nagih, TIDAK urus BS.
    //   AKSI 3 = cek — Lewati / Belum Habis (0 transaksi, catat sisa).
    // ("Tagih Saja" dicabut — bayaran tanpa titip via Hentikan Kedai. "Tunda Bayar"
    //  dilebur ke Cek Sisa alasan "belum bisa bayar" — Tahap 2. "Tambah cash sekali"
    //  yang dulu nempel di form titip DILEBUR jadi AKSI 2 mandiri.)
    public ?string $chosenAction = null;

    // Kios cash only: setiap drop langsung bayar cash (di-set dari kios terpilih)
    public bool $isCashOnly = false;

    // Input Form dari Rian
    public $returnFresh = 0;
    public $returnExpired = 0;
    public $dropBaru = 0;
    public $uangDiterima = 0;

    // === AKSI "MULAI TITIPAN" (kedai tanpa titipan → mulai konsinyasi) ===
    // Jatah seterusnya saat operator mengubah kedai cash-only/baru jadi konsinyasi.
    // $dropBaru = mika yang dititip sekarang; $jatahMulai = jatah tetap ke depan.
    public int $jatahMulai = 0;

    // === UBAH JATAH PERMANEN — HANYA AKSI 1 (Tagih + Titip Ulang / Titip Baru) ===
    // Operator mengubah default_qty_mika kios (naik ATAU turun) jadi kebiasaan baru
    // SETERUSNYA. ATURAN SATU-ANGKA (owner): angka yang DITARUH hari ini ($dropBaru) =
    // jatah baru — TIDAK ada field kedua. Menandai visit changed_default (tak bisa
    // dikoreksi). Verifikasi 2-langkah: titip beda-dari-jatah WAJIB centang ini dulu
    // (lihat blokir di persistVisitFromState()).
    //
    // SENGAJA TIDAK ADA di AKSI 2 (Titip Cash) & AKSI 3 (Lewati) — owner 12 Juli 2026:
    // Titip Cash BEBAS naruh berapa saja (tak terikat jatah) → ubah-jatah tak relevan;
    // Lewati tak menaruh apa pun. (Dulu ada di ketiganya + field $jatahBaru terpisah
    // untuk AKSI 3 — DIBUANG total, tak ada sisa nyangkut.)
    public bool $ubahJatah = false;

    // === AKSI 2 (Titip Cash) — BS per BIJI (owner 12 Juli 2026) ===
    // Biji tak layak jual yang ditemukan saat cek. Kedai TIDAK bayar BS
    // (cash = (biji_ditaruh − BS) × harga/biji); BS jadi KERUGIAN owner lewat
    // mekanisme yang SAMA dgn Stop Tanpa Tagih (settlement is_writeoff +
    // qty_returned_expired → 1 laporan kerugian). Komisi TIDAK terpengaruh BS
    // (mika ditaruh dihitung penuh). Satuan BIJI, seperti field BS di AKSI 1.
    public int $qtyBsCash = 0;

    // --- SKENARIO 5: check_only + alasan + sisa biji ---
    public string $alasanCheck = '';
    public int $sisaBiji = 0;

    // Catatan "janji bayar" (teks bebas) saat Cek Sisa alasan = belum bisa bayar.
    // Disimpan ke KioskVisit.notes (titipan TETAP pending / tidak di-settle — Realisasi B).
    public string $janjiBayar = '';

    // Banner "Titipan Tertunda" di modal: kalau titipan kios ini sebelumnya ditandai
    // "belum bisa bayar", tampilkan qty + janji bayar ke operator agar ingat menagih.
    public int $tertundaMika = 0;
    public string $tertundaJanji = '';

    // --- TAHAP 3: PIUTANG LAMA (Settlement pending dari Tagih+Titip uang-kurang) ---
    // Total sisa utang rupiah kios ini + janji bayar + alur terima pembayaran (pelunasan).
    public int $piutangLama = 0;
    public string $piutangJanji = '';
    public bool $payingPiutang = false;
    public int $piutangBayar = 0;

    // --- SKENARIO 7: BS redistribusi (mika BS dari kios lain ikut di-drop) ---
    public bool $adaBsRedistribusi = false;
    public int $qtyBsMika = 0;

    // Kalkulasi Sistem
    public $terjual = 0;
    public $tagihan = 0;

    // --- LEGACY: dulu dipakai "Tunda Bayar" (dihapus Tahap 2 — dilebur ke Cek Sisa
    //     alasan "belum bisa bayar"). extensionGranted selalu false sekarang; dibiarkan
    //     agar persistVisitFromState ($extension) tetap kompatibel (selalu no-op).
    public bool $extensionGranted = false;

    // --- STATE HENTIKAN KEDAI (stop titipan) ---
    // stopMode: '' = tidak di alur stop. 'pick' = layar pilih cara stop.
    //           'tagih' = stop + tagih terakhir (jalur a).
    //           'tanpa_tagih' = stop tanpa tagih / kerugian (jalur b).
    public string $stopMode = '';
    public string $stopReason = '';
    // Gerbang konfirmasi tegas: true = tampilkan peringatan "FINAL" sebelum eksekusi.
    public bool $stopConfirming = false;
    // Tandai settlement titipan lama sebagai kerugian (jalur b: Stop Tanpa Tagih).
    public bool $stopWriteOff = false;

    // --- STATE WALK-IN CASH (penjualan cash ke pembeli random, bukan kios) ---
    public bool $isWalkInModalOpen = false;
    public int $walkInMika = 0;

    // --- STATE KOREKSI ANGKA VISIT (UI Tahap 3; logic reversal di correctVisit) ---
    public bool $isCorrectionModalOpen = false;
    public ?int $correctionVisitId = null;
    public bool $correctionHasDrop = false;     // tampilkan field drop mika
    public bool $correctionHasSettle = false;   // tampilkan field uang + retur
    public ?string $correctionKioskName = null;

    // --- STATE END TRIP ---
    public bool $isEndTripModalOpen = false;
    public string $endReason = '';
    public array $tripSummary = [
        'kios_visited' => 0,
        'total_mika_drop' => 0,
        'total_uang_diterima' => 0,
        'qty_carried' => 0,
        'total_mika_sisa' => 0,
    ];

    public function mount()
    {
        // Trip aktif operator ini. {tripId} di URL sengaja TIDAK dipakai untuk query
        // (kontrak lama, lihat TripPersistenceAcrossLoginTest) — operator selalu
        // mendarat di trip aktifnya walau URL basi/ID sembarang.
        //
        // 🔴 URUTAN PENTING (bug 28 Juli 2026): trip HARI INI menang mutlak. Dulu
        // ->first() polos = id TERKECIL, jadi trip lama yang lupa di-"Akhiri Trip"
        // (misal area Pancing kemarin) selalu terpilih walau operator baru saja
        // mulai trip area lain hari ini → daftar kedai yang terbuka area yang salah.
        // Fallback ke trip belum-selesai TERBARU tetap dipertahankan supaya operator
        // yang trip-nya lewat tengah malam tidak terkunci keluar dari tripnya.
        $baseQuery = fn () => Trip::where('operator_id', auth()->id())->whereNull('ended_at');

        $this->trip = $baseQuery()
            ->whereDate('trip_date', today())
            ->latest('id')
            ->first()
            ?? $baseQuery()->latest('trip_date')->latest('id')->first();

        if (!$this->trip) {
            return redirect()->route('operator.dashboard');
        }

        $this->starting_cluster_id = $this->trip->starting_cluster_id;
        $this->viewClusterId = $this->trip->starting_cluster_id; // mulai dari area awal
        $this->loadKiosks();
    }

    /**
     * NO-OP yang disengaja. Dulu method ini memuat seluruh kios + flag + operator
     * terakhir ke public property (ikut snapshot tiap request). Sekarang data daftar
     * kios dihitung di render() lewat kioskViewData() sebagai variabel view — TIDAK
     * disimpan sebagai state. Pemanggilan lama (refresh setelah transaksi) dibiarkan
     * karena render() otomatis menyegarkan daftar setiap request.
     */
    public function loadKiosks(): void
    {
        // sengaja kosong — lihat kioskViewData().
    }

    /**
     * Bangun data daftar kios untuk view (dipanggil HANYA dari render()).
     * Dibatasi DISPLAY_LIMIT kartu; flag/operator-terakhir/pending dihitung hanya
     * untuk kios yang TAMPIL, sehingga query & payload tidak ikut membesar saat
     * jumlah kios owner banyak (957). Scoping multi-tenant TIDAK diubah.
     */
    private function kioskViewData(): array
    {
        $search = trim($this->search);

        // Satu definisi "kios yang boleh tampil", dipakai ulang oleh SEMUA cabang
        // (dekat / sisa / hitungan per-area) supaya tak ada cabang yang diam-diam
        // memakai aturan berbeda.
        $ownerId = auth()->user()->owner_id;
        $base = function () use ($ownerId, $search) {
            $q = Kiosk::query()
                ->where('is_active', true)
                // Kios sentinel walk-in ("Penjualan Walk-in") bukan kedai yang
                // dikunjungi. Tanpa ini ia muncul sebagai kartu di daftar lintas area.
                ->excludeWalkInSentinel();

            // Multi-tenant: batasi ke kios milik owner operator (lewat cluster).
            // Guard null untuk backward-compat (data lama / operator tanpa owner_id).
            if ($ownerId !== null) {
                $q->whereHas('cluster', fn ($c) => $c->where('owner_id', $ownerId));
            }

            // 🔴 Filter area kini dari viewClusterId (bisa diganti DI DALAM trip),
            // bukan lagi starting_cluster_id yang cuma dibaca sekali di mount().
            if ($this->viewClusterId) {
                $q->where('cluster_id', $this->viewClusterId);
            }

            if ($search !== '') {
                $q->where('name', 'like', '%'.$search.'%');
            }

            return $q;
        };

        // Trip-scoped (kecil, tak tergantung jumlah kios). 1 query untuk visit trip
        // ini, lalu dipecah: belum-dikoreksi = "dikunjungi", dikoreksi = badge koreksi.
        $tripVisits = KioskVisit::where('trip_id', $this->trip->id)
            ->get(['kiosk_id', 'corrected_at']);
        $visitedKioskIds = $tripVisits->whereNull('corrected_at')
            ->pluck('kiosk_id')->unique()->values()->all();
        $correctedKioskIds = $tripVisits->whereNotNull('corrected_at')
            ->pluck('kiosk_id')->unique()->values()->all();

        // Total per AREA dalam satu query GROUP BY. Dipakai dua hal sekaligus:
        // judul pemisah ("— Pancing (12 kios) —") dan totalMatched — jadi ini
        // MENGGANTIKAN query count lama, bukan menambah.
        $perClusterTotals = $base()
            ->selectRaw('kiosks.cluster_id as cluster_id, count(*) as agg')
            ->groupBy('kiosks.cluster_id')
            ->pluck('agg', 'cluster_id');
        $totalMatched = (int) $perClusterTotals->sum();

        if ($this->sortedByDistance && $this->userLat !== null && $this->userLng !== null) {
            [$kiosks, $groups] = $this->kiosksByDistance($base, $visitedKioskIds, $perClusterTotals);
        } else {
            [$kiosks, $groups] = $this->kiosksByRoute($base, $visitedKioskIds, $perClusterTotals);
        }

        $displayedIds = $kiosks->pluck('id')->all();

        // Titipan BERJALAN per kios: jumlah mika + ada/tidaknya. Keduanya sudah ikut
        // di query utama sebagai subquery berkorelasi (lihat withCardAggregates) →
        // 0 query tambahan, dan MENGGANTIKAN query terpisah pendingKioskIdsFor()
        // yang dulu dipakai badge "Ada Titipan".
        $pendingKioskIds = $kiosks
            ->filter(fn ($k) => (int) ($k->pending_titipan_count ?? 0) > 0)
            ->pluck('id')->all();

        // BADGE "belum pernah dititip" = kedai BOOKING (belum ada dodol; belum cash-only,
        // belum punya jatah). Diturunkan dari kolom yang SUDAH termuat di tiap kios
        // (is_cash_only + default_qty_mika, lihat Kiosk::isBooking) → 0 query tambahan,
        // BUKAN N+1. Hilang otomatis begitu operator Titip Cash (jadi cash) atau Mulai
        // Titipan (jatah terisi) → isBooking() jadi false.
        $bookingKioskIds = $kiosks->filter(fn ($k) => $k->isBooking())->pluck('id')->all();

        return [
            'kiosks' => $kiosks,
            // Daftar yang benar-benar dirender, sudah terbagi per judul pemisah
            // ("— Pancing (12 kios) —" / "Tanpa lokasi GPS"). `kiosks` di atas tetap
            // datar untuk pemanggil lama.
            'kioskGroups' => $groups,
            'visitedKioskIds' => $visitedKioskIds,
            'correctedKioskIds' => $correctedKioskIds,
            'pendingKioskIds' => $pendingKioskIds,
            'bookingKioskIds' => $bookingKioskIds,
            // Label area di KARTU hanya saat daftar memang menjangkau lebih dari satu
            // area. Di trip satu-area ia cuma mengulang header dan menaikkan tinggi
            // kartu. Di mode jarak label ini WAJIB: grupnya geo/non-geo, bukan area.
            'showAreaOnCard' => $kiosks->pluck('cluster_id')->unique()->count() > 1,
            'kioskFlags' => $this->computeKioskFlags($kiosks),
            'lastOperatorPerKiosk' => $this->lastOperatorFor($displayedIds),
            'totalMatched' => $totalMatched,
            'displayLimit' => self::DISPLAY_LIMIT,
            'startingClusterId' => $this->trip->starting_cluster_id,
            'viewedAreaName' => $this->viewedAreaName(),
            // Judul pemisah cuma berguna kalau ada lebih dari satu grup — di trip
            // satu-area ia jadi pengulangan header. Mode jarak selalu berjudul,
            // karena "Tanpa lokasi GPS" perlu alasannya tertulis.
            'showGroupLabels' => count($groups) > 1 || $this->sortedByDistance,
        ];
    }

    /**
     * Nama area yang sedang dilihat. Tak boleh bergantung pada kartu yang kebetulan
     * termuat — daftar bisa kosong (mis. hasil pencarian nihil) dan judulnya tetap
     * harus benar.
     */
    private function viewedAreaName(): string
    {
        if ($this->viewClusterId === null) {
            return 'Semua Area';
        }

        // Kasus umum (belum menyeberang): relasi ini toh sudah dimuat header.
        if ($this->viewClusterId === $this->trip->starting_cluster_id) {
            return $this->trip->startingCluster?->name ?? 'Area';
        }

        return Cluster::whereKey($this->viewClusterId)->value('name') ?? 'Area';
    }

    /**
     * Urutan RUTE (default): belum dikunjungi dulu, lalu per-AREA, lalu sort_order
     * di dalam area (diatur owner sesuai pengalaman lapangan). Kios tanpa sort_order
     * turun ke bawah DALAM AREA-nya, tie-break alfabet.
     *
     * Saat melihat SEMUA AREA, area asal trip didahulukan di paling atas — operator
     * yang menyeberang tetap melihat area yang sedang ia kerjakan lebih dulu.
     *
     * @return array{0: Collection, 1: array<int, array<string, mixed>>}
     */
    private function kiosksByRoute(callable $base, array $visitedKioskIds, Collection $perClusterTotals): array
    {
        $query = $this->withCardAggregates($base());

        if (! empty($visitedKioskIds)) {
            $ids = implode(',', array_map('intval', $visitedKioskIds));
            $query->orderByRaw("CASE WHEN kiosks.id IN ($ids) THEN 1 ELSE 0 END asc");
        }

        $startingId = (int) ($this->trip->starting_cluster_id ?? 0);
        if ($this->viewClusterId === null && $startingId > 0) {
            $query->orderByRaw('CASE WHEN kiosks.cluster_id = ? THEN 0 ELSE 1 END asc', [$startingId]);
        }

        $kiosks = $query
            ->orderBy(Cluster::query()->select('name')->whereColumn('clusters.id', 'kiosks.cluster_id'))
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($this->kioskLimit)
            ->get();

        return [$kiosks, $this->groupByArea($kiosks, $perClusterTotals)];
    }

    /**
     * Urutan JARAK (pelengkap, operator menekan "Urutkan Jarak").
     *
     * 🔴 DUA hal yang diperbaiki 29 Juli 2026:
     *
     * (a) KIOS TANPA GPS TAK BOLEH HILANG. Dulu kios tanpa koordinat diberi jarak
     *     PHP_FLOAT_MAX lalu tenggelam ke ekor daftar dan terpotong batas batch —
     *     senyap. Cakupan GPS baru ~12% (119 dari 1014 kios), jadi 88% kedai lenyap
     *     dari layar begitu tombol jarak ditekan. Sekarang mereka punya GRUP SENDIRI
     *     di bawah, dengan judul yang menyebutkan alasannya.
     *
     * (b) PERFORMA. Dulu `$query->get()` TANPA limit + haversine PHP untuk SEMUA kios
     *     owner (~957 baris ditarik & 957 kali trigonometri tiap render). Sekarang
     *     bounding box SQL lebih dulu (index `idx_kiosks_geo` — sudah ada sejak awal
     *     tapi tak pernah dipakai), jadi haversine hanya untuk kios ber-GPS di
     *     sekitar operator.
     *
     * @return array{0: Collection, 1: array<int, array<string, mixed>>}
     */
    private function kiosksByDistance(callable $base, array $visitedKioskIds, Collection $perClusterTotals): array
    {
        $lat = (float) $this->userLat;
        $lng = (float) $this->userLng;

        // 1 derajat lintang ≈ 111 km; bujur menyusut mengikuti cos(lintang).
        $latDelta = self::GEO_BBOX_KM / 111.0;
        $lngDelta = self::GEO_BBOX_KM / (111.0 * max(cos(deg2rad($lat)), 0.01));

        $nearby = $this->withCardAggregates($base())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get()
            ->sort(function ($a, $b) use ($visitedKioskIds, $lat, $lng) {
                $visitedA = in_array($a->id, $visitedKioskIds, true) ? 1 : 0;
                $visitedB = in_array($b->id, $visitedKioskIds, true) ? 1 : 0;
                if ($visitedA !== $visitedB) {
                    return $visitedA <=> $visitedB;
                }

                return $this->calculateDistance($lat, $lng, (float) $a->latitude, (float) $a->longitude)
                    <=> $this->calculateDistance($lat, $lng, (float) $b->latitude, (float) $b->longitude);
            })
            ->values();

        // Batch DIBAGI DUA antar grup, bukan satu batch penuh per grup: jumlah kartu
        // di layar tetap ±DISPLAY_LIMIT seperti mode rute (janji "DOM ringan di HP"
        // tak boleh diam-diam jadi dua kali lipat hanya karena tombol jarak ditekan).
        $perGroup = max(1, intdiv($this->kioskLimit, 2));

        $nearbyTotal = $nearby->count();
        $nearby = $nearby->take($perGroup);

        // SISANYA: kios tanpa koordinat, atau ber-GPS tapi di luar kotak. Diurut
        // aturan rute biasa — bukan dibuang.
        $restQuery = $this->withCardAggregates($base());
        if ($nearbyTotal > 0) {
            $restQuery->whereNotIn('kiosks.id', $nearby->pluck('id')->all());
        }
        if (! empty($visitedKioskIds)) {
            $ids = implode(',', array_map('intval', $visitedKioskIds));
            $restQuery->orderByRaw("CASE WHEN kiosks.id IN ($ids) THEN 1 ELSE 0 END asc");
        }

        $rest = $restQuery
            ->orderBy(Cluster::query()->select('name')->whereColumn('clusters.id', 'kiosks.cluster_id'))
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($perGroup)
            ->get();

        $groups = [];
        if ($nearby->isNotEmpty()) {
            $groups[] = [
                'key' => 'geo',
                'label' => 'Terdekat dari lokasimu',
                'note' => $nearbyTotal.' kios ber-GPS dalam '.self::GEO_BBOX_KM.' km',
                'kiosks' => $nearby,
            ];
        }
        if ($rest->isNotEmpty()) {
            $groups[] = [
                'key' => 'nogeo',
                'label' => 'Tanpa lokasi GPS / di luar jangkauan',
                'note' => 'Tidak bisa diurut jarak — urutan rute biasa',
                'kiosks' => $rest,
            ];
        }

        return [$nearby->concat($rest)->values(), $groups];
    }

    /**
     * Pecah daftar jadi grup per AREA, urutannya mengikuti urutan kartu (jadi area
     * asal tetap di atas). Judul pemisah hanya berguna kalau areanya lebih dari satu —
     * penilaian itu diserahkan ke view lewat jumlah grup.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupByArea(Collection $kiosks, Collection $perClusterTotals): array
    {
        $groups = [];
        $startingId = (int) ($this->trip->starting_cluster_id ?? 0);

        foreach ($kiosks->groupBy('cluster_id') as $clusterId => $rows) {
            $clusterId = (int) $clusterId;
            $total = (int) ($perClusterTotals[$clusterId] ?? $rows->count());
            $label = $rows->first()->cluster?->name ?? 'Tanpa area';

            $groups[] = [
                'key' => 'area-'.$clusterId,
                'label' => $label.($clusterId === $startingId ? ' · area awal' : ''),
                'note' => $total.' kios'.($rows->count() < $total ? ' — '.$rows->count().' tampil' : ''),
                'kiosks' => $rows,
            ];
        }

        return $groups;
    }

    /**
     * Tampilkan satu batch kios berikutnya (+DISPLAY_LIMIT kartu). Dipakai tombol
     * "Muat lebih banyak" — pengganti pemotongan senyap di kios ke-50 yang bikin
     * operator kehilangan ekor rute area (bug 28 Juli 2026).
     */
    public function loadMoreKiosks(): void
    {
        $this->kioskLimit += self::DISPLAY_LIMIT;
    }

    /** Ganti kata kunci = daftar berganti konteks → balik ke batch pertama. */
    public function updatedSearch(): void
    {
        $this->kioskLimit = self::DISPLAY_LIMIT;
    }

    public function openAreaPicker(): void
    {
        $this->isAreaPickerOpen = true;
    }

    public function closeAreaPicker(): void
    {
        $this->isAreaPickerOpen = false;
    }

    /**
     * LINTAS AREA DI TENGAH TRIP (permintaan owner 29 Juli 2026).
     *
     * Operator selesai menyeser area awal tapi dodol masih sisa → lanjut ke kedai
     * area lain TANPA mengakhiri trip. Kalau harus akhiri lalu mulai trip baru,
     * komisi & data pengantaran terpecah jadi dua trip.
     *
     * Yang berubah HANYA daftar yang tampil. Trip tidak diubah, tidak diakhiri,
     * `starting_cluster_id` tidak disentuh (tetap jadi catatan "area awal").
     *
     * 🔒 Cluster diverifikasi milik owner operator — jangan percaya id dari klien.
     */
    public function switchArea(?int $clusterId): void
    {
        $this->resetErrorBag('general');

        if ($clusterId !== null) {
            $ownerId = auth()->user()->owner_id;

            $milikOwner = Cluster::whereKey($clusterId)
                ->where('is_active', true)
                ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
                ->exists();

            if (! $milikOwner) {
                $this->addError('general', 'Area itu bukan milik Anda.');

                return;
            }
        }

        $this->viewClusterId = $clusterId;
        $this->kioskLimit = self::DISPLAY_LIMIT; // ganti area → balik ke batch pertama
        $this->isAreaPickerOpen = false;
    }

    /**
     * Area milik owner + jumlah kios aktif per area, untuk panel "Lihat Area Lain".
     * Hanya di-query saat panelnya dibuka — trip satu-area tak membayar apa pun.
     *
     * @return \Illuminate\Support\Collection<int, Cluster>
     */
    private function availableAreas(): Collection
    {
        $ownerId = auth()->user()->owner_id;

        return Cluster::query()
            ->where('is_active', true)
            ->excludeWalkInSentinel()
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->withCount(['kiosks' => fn ($q) => $q->where('is_active', true)->excludeWalkInSentinel()])
            ->orderBy('name')
            ->get();
    }

    /**
     * Data KARTU yang butuh agregat per kios, dipasang sebagai SUBQUERY BERKORELASI
     * di query utama — bukan query terpisah per baris. Daftar bisa ratusan kios, jadi
     * ini titik paling rawan N+1.
     *
     *  - `cluster:id,name`      → label area di kartu & judul pemisah (1 eager load).
     *  - `pending_titipan_mika` → berapa mika titipan berjalan (angka di kartu).
     *  - `pending_titipan_count`→ ada/tidaknya titipan (menggantikan query terpisah
     *                             pendingKioskIdsFor yang dulu dipanggil tiap render).
     *
     * Pola withSum ini sama persis dengan kolom "Titipan" di panel owner
     * (KioskResource::getEloquentQuery) — satu aturan, bukan dua yang bisa menyimpang.
     */
    private function withCardAggregates(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->with('cluster:id,name')
            ->withCount(['deliveries as pending_titipan_count' => fn ($q) => $q->doesntHave('settlement')])
            ->withSum(
                ['deliveries as pending_titipan_mika' => fn ($q) => $q->doesntHave('settlement')],
                'qty_delivered',
            );
    }

    /**
     * Operator terakhir yang mengunjungi tiap kios (untuk himpunan TAMPIL).
     * Satu query join+group, hindari N+1.
     */
    private function lastOperatorFor(array $kioskIds): array
    {
        if (empty($kioskIds)) {
            return [];
        }

        return KioskVisit::whereIn('kiosk_id', $kioskIds)
            ->whereNull('kiosk_visits.corrected_at')
            ->join('trips', 'kiosk_visits.trip_id', '=', 'trips.id')
            ->whereNull('trips.deleted_at') // join manual → tak kena SoftDeletes global scope
            ->join('users', 'trips.operator_id', '=', 'users.id')
            ->groupBy('kiosk_visits.kiosk_id')
            ->selectRaw('kiosk_visits.kiosk_id, MAX(kiosk_visits.visited_at) as last_visited_at, '
                .'SUBSTRING_INDEX(GROUP_CONCAT(users.name ORDER BY kiosk_visits.visited_at DESC), ",", 1) as operator_name')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->kiosk_id => [
                'name' => $row->operator_name,
                'date' => \Carbon\Carbon::parse($row->last_visited_at)->translatedFormat('d M Y'),
            ]])
            ->all();
    }

    /**
     * Hitung flag pintar untuk kios TAMPIL sekaligus (hindari N+1).
     * Flag: urgent, warning, new, fast_mover, slow_mover. Mengembalikan array
     * ['kioskId' => [...flags]] (dulu menulis ke public property; kini view-local).
     */
    private function computeKioskFlags(Collection $kiosks): array
    {
        $kioskIds = $kiosks->pluck('id')->all();

        if (empty($kioskIds)) {
            return [];
        }

        $today = now();
        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();

        // Kunjungan terakhir per kios (MAX(visited_at)) — pakai index (kiosk_id, visited_at).
        $lastVisits = KioskVisit::active()
            ->whereIn('kiosk_id', $kioskIds)
            ->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
            ->pluck('last_visit', 'kiosk_id');

        // Rata-rata hari sampai habis (settle) per kios, minimal 3 settlement.
        $avgDays = Settlement::query()
            ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
            ->whereIn('deliveries.kiosk_id', $kioskIds)
            ->groupBy('deliveries.kiosk_id')
            ->havingRaw('COUNT(*) >= 3')
            ->selectRaw('deliveries.kiosk_id as kiosk_id, AVG(DATEDIFF(settlements.visit_date, deliveries.created_at)) as avg_days')
            ->pluck('avg_days', 'kiosk_id');

        $flagsByKiosk = [];

        foreach ($kiosks as $kiosk) {
            $flags = [];

            $lastVisit = $lastVisits[$kiosk->id] ?? null;
            // abs(): Carbon 3 diffInDays bertanda (tanggal lampau = negatif).
            $daysSinceVisit = $lastVisit
                ? (int) abs($today->diffInDays(\Illuminate\Support\Carbon::parse($lastVisit)))
                : 999;

            // URGENT — lewat target interval (default 10 hari).
            $urgentThreshold = $kiosk->target_visit_interval_days ?: 10;
            if ($daysSinceVisit > $urgentThreshold) {
                $flags[] = 'urgent';
            }

            // HAMPIR EXPIRED — lewat warning interval (kalau belum urgent).
            $warningThreshold = $kiosk->warning_visit_interval_days ?? null;
            if ($warningThreshold && $daysSinceVisit > $warningThreshold && ! in_array('urgent', $flags, true)) {
                $flags[] = 'warning';
            }

            // KIOS BARU — first_titip_date dalam 30 hari terakhir.
            if ($kiosk->first_titip_date && $kiosk->first_titip_date->toDateString() >= $thirtyDaysAgo) {
                $flags[] = 'new';
            }

            // FAST / SLOW MOVER — butuh threshold + minimal 3 data settle.
            $threshold = $kiosk->fast_mover_threshold_days ?? null;
            $avg = isset($avgDays[$kiosk->id]) ? (float) $avgDays[$kiosk->id] : null;
            if ($threshold && $avg !== null) {
                if ($avg < $threshold) {
                    $flags[] = 'fast_mover';
                } elseif ($avg > ($threshold * 2)) {
                    $flags[] = 'slow_mover';
                }
            }

            $flagsByKiosk[$kiosk->id] = $flags;
        }

        return $flagsByKiosk;
    }

    public function sortByDistance($lat, $lng)
    {
        $this->userLat = (float) $lat;
        $this->userLng = (float) $lng;
        $this->sortedByDistance = true;
        $this->kioskLimit = self::DISPLAY_LIMIT; // urutan berganti → balik ke batch pertama

        $this->loadKiosks();
    }

    private function calculateDistance(float $latFrom, float $lngFrom, float $latTo, float $lngTo): float
    {
        $earthRadius = 6371000; // meter

        $latFromRad = deg2rad($latFrom);
        $lngFromRad = deg2rad($lngFrom);
        $latToRad = deg2rad($latTo);
        $lngToRad = deg2rad($lngTo);

        $latDelta = $latToRad - $latFromRad;
        $lngDelta = $lngToRad - $lngFromRad;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFromRad) * cos($latToRad) * pow(sin($lngDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }

    // --- METODE TRANSAKSI MODAL ---

    /**
     * 🔒 Gerbang multi-tenant: ambil kios HANYA jika milik owner operator ini.
     * Mengembalikan null kalau kios bukan milik operator (atau tidak ada). Jangan
     * pernah percaya $kioskId / $selectedKiosk mentah dari klien — pola identik
     * dengan saveKioskPhoto() (:650) & scopedPendingSettlements() (:479).
     */
    private function ownedKiosk($kioskId): ?Kiosk
    {
        if (! $kioskId) {
            return null;
        }

        $ownerId = auth()->user()->owner_id;

        return Kiosk::whereKey($kioskId)
            ->when($ownerId !== null, fn ($q) => $q->whereHas('cluster', fn ($c) => $c->where('owner_id', $ownerId)))
            ->first();
    }

    public function openVisitModal($kioskId)
    {
        // 🔒 Re-verifikasi kepemilikan server-side SEBELUM membuka modal / memuat
        // data kios. Kios owner lain → tolak, jangan buka modal.
        $this->selectedKiosk = $this->ownedKiosk($kioskId);

        if (! $this->selectedKiosk) {
            $this->addError('general', 'Kios ini bukan milik Anda.');
            return;
        }

        $this->isCashOnly = (bool) ($this->selectedKiosk?->is_cash_only);

        $this->pendingDelivery = Delivery::where('kiosk_id', $kioskId)
            ->doesntHave('settlement')
            ->latest('id')
            ->first();

        // Banner "Titipan Tertunda": kalau titipan kios ini sebelumnya ditandai
        // "belum bisa bayar" (Cek Sisa), tampilkan qty + janji bayar agar operator
        // ingat menagih. Titipan tetap pending (Realisasi B) → tertagih normal.
        $this->tertundaMika = 0;
        $this->tertundaJanji = '';
        if ($this->pendingDelivery) {
            $lastDeferred = KioskVisit::active()
                ->where('kiosk_id', $kioskId)
                ->where('alasan_check', 'belum_bisa_bayar')
                ->latest('visited_at')
                ->first();
            if ($lastDeferred) {
                $this->tertundaMika = (int) $this->pendingDelivery->qty_delivered;
                $this->tertundaJanji = (string) ($lastDeferred->notes ?? '');
            }
        }

        // Piutang lama (Settlement pending rupiah) untuk banner + alur pelunasan.
        $this->payingPiutang = false;
        $this->piutangBayar = 0;
        $this->refreshPiutangLama($kioskId);

        $this->resetVisitForm();

        // Reset state hentikan kedai tiap buka modal.
        $this->stopMode = '';
        $this->stopReason = '';
        $this->stopConfirming = false;

        // AKSI ADAPTIF: aksi yang muncul mengikuti KONDISI kedai (ada titipan berjalan
        // atau tidak), BUKAN label kaku. Selalu mulai di layar pilih aksi — termasuk
        // kedai cash-only (yang kini melihat Titip Cash / Lewati / Mulai Titipan).
        $this->chosenAction = null;

        $this->resetErrorBag();
        $this->isVisitModalOpen = true;
    }

    /**
     * Hitung total piutang lama (Settlement pending) kios ini + janji bayar terbaru.
     * 🔒 SELALU di-scope ke owner operator (lewat delivery.kiosk.cluster.owner_id) —
     * operator tak boleh melihat/utak-atik piutang kios owner lain.
     */
    private function refreshPiutangLama(int $kioskId): void
    {
        $rows = $this->scopedPendingSettlements($kioskId)->get(['amount_due', 'amount_paid', 'notes']);
        $this->piutangLama = (int) $rows->sum(fn ($s) => (int) $s->amount_due - (int) $s->amount_paid);
        $this->piutangJanji = (string) ($rows->filter(fn ($s) => $s->notes)->last()->notes ?? '');
    }

    /** Query Settlement pending kios INI yang ter-scope owner operator (gate multi-tenant). */
    private function scopedPendingSettlements(int $kioskId)
    {
        $ownerId = auth()->user()->owner_id;

        return Settlement::where('status', 'pending')
            ->whereHas('delivery', function ($q) use ($kioskId, $ownerId) {
                $q->where('kiosk_id', $kioskId)
                    ->whereHas('kiosk.cluster', fn ($c) => $c->where('owner_id', $ownerId));
            })
            ->orderBy('id'); // tertua dulu saat pelunasan
    }

    /** Buka input pelunasan piutang. */
    public function startPiutangPayment(): void
    {
        $this->payingPiutang = true;
        $this->piutangBayar = 0;
        $this->resetErrorBag('piutang');
    }

    /**
     * Terima pembayaran piutang lama (pelunasan). Update amount_paid Settlement pending
     * (tertua dulu); SettlementObserver set status='paid'+paid_at saat lunas penuh.
     * Opsi A: TIDAK ubah visit_date (omzet tetap tanggal asli), TIDAK tambah komisi.
     */
    public function terimaPembayaranPiutang(): void
    {
        $this->resetErrorBag('piutang');

        if (! $this->selectedKiosk) {
            $this->addError('piutang', 'Kios tidak valid. Tutup form dan coba lagi.');
            return;
        }

        $kioskId = $this->selectedKiosk->id;
        $amount = (int) $this->piutangBayar;

        // 🔒 Outstanding HANYA dari settlement kios ini yang ter-scope owner operator.
        $pending = $this->scopedPendingSettlements($kioskId)->get();
        $outstanding = (int) $pending->sum(fn ($s) => (int) $s->amount_due - (int) $s->amount_paid);

        if ($outstanding <= 0) {
            $this->addError('piutang', 'Tidak ada piutang untuk kios ini.');
            return;
        }
        if ($amount <= 0) {
            $this->addError('piutang', 'Jumlah pembayaran harus lebih dari 0.');
            return;
        }
        if ($amount > $outstanding) {
            $this->addError('piutang', 'Melebihi sisa piutang (Rp '.number_format($outstanding, 0, ',', '.').').');
            return;
        }

        DB::transaction(function () use ($pending, $amount) {
            $remaining = $amount;
            foreach ($pending as $s) {
                if ($remaining <= 0) {
                    break;
                }
                $sisa = (int) $s->amount_due - (int) $s->amount_paid;
                $bayar = min($sisa, $remaining);
                // Observer set status='paid' + paid_at saat amount_paid >= amount_due.
                $s->amount_paid = (int) $s->amount_paid + $bayar;
                $s->save();
                $remaining -= $bayar;
            }
        });

        $this->refreshPiutangLama($kioskId);
        $this->payingPiutang = false;
        $this->piutangBayar = 0;
        session()->flash('visit_saved', 'Pembayaran piutang dicatat. Sisa: Rp '.number_format($this->piutangLama, 0, ',', '.'));
    }

    private function resetVisitForm(): void
    {
        $this->returnFresh = 0;
        $this->returnExpired = 0;
        $this->dropBaru = 0;
        $this->jatahMulai = 0;
        $this->terjual = 0;
        $this->tagihan = 0;
        $this->uangDiterima = 0;
        $this->ubahJatah = false;
        $this->qtyBsCash = 0;
        $this->alasanCheck = '';
        $this->sisaBiji = 0;
        $this->janjiBayar = '';
        $this->adaBsRedistribusi = false;
        $this->qtyBsMika = 0;
        $this->extensionGranted = false;
        $this->stopWriteOff = false;
    }

    /**
     * Layar 1 modal: operator memilih aksi. Hanya mengatur section form yang
     * tampil + state extension — TIDAK menentukan visit_action yang tersimpan
     * (itu tetap auto-detect server-side di resolveVisitAction()).
     */
    public function chooseAction(string $action): void
    {
        // AKSI ADAPTIF ikut kondisi kedai (cegah operator lihat aksi mustahil):
        //  - ADA titipan berjalan  → Tagih+Titip / Titip Cash / Lewati / Ganti ke Cash.
        //  - TANPA titipan berjalan → Titip Cash / Lewati / Mulai Titipan.
        // "Tagih + Titip Ulang" TIDAK muncul tanpa titipan (tak ada yang ditagih).
        $valid = $this->pendingDelivery
            ? ['tagih_titip', 'titip_cash', 'cek', 'ganti_cash']
            : ['titip_cash', 'cek', 'mulai_titipan'];

        if (! in_array($action, $valid, true)) {
            return;
        }

        $this->chosenAction = $action;
        $defaultQty = (int) ($this->selectedKiosk->default_qty_mika ?? 0);

        // AKSI 1 (siklus normal): pra-isi titip ulang = jatah kios (satu angka).
        // Operator tinggal simpan bila sesuai; kalau mau beda WAJIB centang "Ubah jatah"
        // (blokir 2-langkah di persistVisitFromState()).
        if ($action === 'tagih_titip') {
            $this->dropBaru = $defaultQty; // 0 kalau kios belum punya jatah (kios baru).
        }

        // AKSI 2 (titip cash): mulai dari 0 — operator isi jumlah cash yang ditaruh.
        if ($action === 'titip_cash') {
            $this->dropBaru = 0;
        }

        // AKSI 3 (cek / lewati): tak ada titip baru — qty drop bersih.
        if ($action === 'cek') {
            $this->dropBaru = 0;
        }

        // GANTI KE CASH: tagih titipan TERAKHIR (settle) tapi TIDAK titip lagi (drop=0).
        // Kedai tetap aktif & tetap dikunjungi, tapi mulai sekarang mode cash. Form
        // memakai kolom settle yang sama dgn AKSI 1 (BS/uang), tanpa field titip ulang.
        if ($action === 'ganti_cash') {
            $this->dropBaru = 0;
            $this->hitungTagihan();
        }

        // MULAI TITIPAN: kedai cash-only/baru mulai dititip. Operator isi berapa mika
        // dititip ($dropBaru) + jatah seterusnya ($jatahMulai). Pra-isi jatah dari
        // default lama kalau ada, drop dari jatah itu sebagai titik awal.
        if ($action === 'mulai_titipan') {
            $this->jatahMulai = $defaultQty >= 1 ? $defaultQty : 0;
            $this->dropBaru = $defaultQty >= 1 ? $defaultQty : 0;
        }

        // AKSI 1 (Tagih+Titip): hitung tagihan langsung (uangDiterima default = tagihan
        // penuh) supaya "Total Tagihan" + deteksi bayar-kurang (janji bayar) tampil awal.
        if ($action === 'tagih_titip') {
            $this->hitungTagihan();
        }
    }

    /** Kembali ke layar pilih aksi; bersihkan input agar tidak nyangkut antar aksi. */
    public function backToActionPicker(): void
    {
        $this->resetVisitForm();
        $this->chosenAction = null;
        $this->resetErrorBag();
    }

    public function closeVisitModal()
    {
        $this->isVisitModalOpen = false;
        $this->selectedKiosk = null;
        $this->pendingDelivery = null;
        $this->chosenAction = null;
        $this->stopMode = '';
        $this->stopReason = '';
        $this->stopConfirming = false;
        $this->kioskPhoto = null;
        $this->resetErrorBag('kioskPhoto');
    }

    /**
     * Operator tambah/ganti foto kios dari lapangan (modal kunjungan). Opsi A:
     * bebas tambah ATAU timpa foto lama, dengan jejak audit di kiosk.notes.
     *
     * 🔒 GATE MULTI-TENANT KRITIS: kios yang difoto WAJIB milik owner operator
     * (lewat cluster.owner_id) — sama seperti scopedPendingSettlements(). Operator
     * TIDAK boleh mengganti foto kios owner lain, walau selectedKiosk di-set paksa.
     */
    public function saveKioskPhoto(): void
    {
        $this->resetErrorBag('kioskPhoto');

        if (! $this->selectedKiosk) {
            $this->addError('kioskPhoto', 'Kios tidak valid. Tutup form dan coba lagi.');
            return;
        }

        // Aturan foto dipusatkan di App\Support\KioskPhoto (plafon 20MB = jaring
        // pengaman; foto sudah dikompres browser jadi <1MB). Foto besar dari kamera HP
        // TIDAK ditolak lagi.
        $this->validate(
            ['kioskPhoto' => KioskPhoto::rules(required: true)],
            array_merge(
                ['kioskPhoto.required' => 'Pilih foto dulu.'],
                KioskPhoto::pesanValidasi('kioskPhoto'),
            ),
        );

        // HEIC di server tanpa delegate HEIF → tolak dengan instruksi, jangan simpan
        // mentah (HEIC tak tampil di browser Android/desktop = foto blank).
        if (KioskPhoto::heicTakBisaDiproses($this->kioskPhoto)) {
            $this->addError('kioskPhoto', KioskPhoto::pesanHeicTakDidukung());

            return;
        }

        // 🔒 Re-verifikasi kepemilikan server-side (jangan percaya selectedKiosk mentah).
        $ownerId = auth()->user()->owner_id;
        $kiosk = Kiosk::whereKey($this->selectedKiosk->id)
            ->when($ownerId !== null, fn ($q) => $q->whereHas('cluster', fn ($c) => $c->where('owner_id', $ownerId)))
            ->first();

        if (! $kiosk) {
            $this->addError('kioskPhoto', 'Kios ini bukan milik Anda — foto tidak diubah.');
            return;
        }

        $isReplace = ! empty($kiosk->photo_path);

        // KioskPhoto::store() = konversi HEIC → JPG (kalau perlu) lalu kompres
        // (ImageResizer) lalu simpan. Satu jalur, sama dengan create-kiosk & form owner.
        $path = KioskPhoto::store($this->kioskPhoto);

        if ($path === null) {
            $this->addError('kioskPhoto', 'Foto gagal diproses. Coba foto ulang.');

            return;
        }

        // Jejak audit di notes (pola CreateKiosk). Timpa bebas — riwayat foto tidak disimpan.
        $verb = $isReplace ? 'diganti' : 'ditambah';
        $jejak = "Foto {$verb} operator ".auth()->user()->name.' pada '.now()->translatedFormat('d M Y H:i');
        $notes = trim(($kiosk->notes ? $kiosk->notes."\n" : '').$jejak);

        $kiosk->update([
            'photo_path' => $path,
            'notes' => $notes,
        ]);

        // Segarkan model modal agar foto baru langsung tampil.
        $this->selectedKiosk = $kiosk->fresh();
        $this->kioskPhoto = null;

        session()->flash('visit_saved', $isReplace ? 'Foto kios diganti.' : 'Foto kios ditambahkan.');
    }

    // ===================== HENTIKAN KEDAI (stop titipan) =====================
    // Satu pintu, dua jalur jelas:
    //   (a) Stop + Tagih Terakhir  → catat tagihan terakhir lalu kedai berhenti.
    //   (b) Stop Tanpa Tagih       → sisa dodol dicatat sebagai kerugian, lalu berhenti.
    // Kedua jalur MENUTUP titipan lewat Settlement (reuse persistVisitFromState),
    // sehingga tidak ada titipan menggantung ("silent loss") di pembukuan.

    /** Layar pilih cara stop ("⛔ Hentikan Kedai Ini" pada layar pilih aksi). */
    public function startStop(): void
    {
        $this->resetVisitForm();
        $this->chosenAction = null;
        $this->stopMode = 'pick';
        $this->stopReason = '';
        $this->stopConfirming = false;
        $this->resetErrorBag(['stopReason', 'general']);
    }

    /** Pilih jalur stop: 'tagih' (a) atau 'tanpa_tagih' (b). */
    public function chooseStopMode(string $mode): void
    {
        if (! in_array($mode, ['tagih', 'tanpa_tagih'], true)) {
            return;
        }

        // (a) Stop + Tagih hanya relevan kalau kios MASIH punya titipan aktif.
        if ($mode === 'tagih' && ! $this->pendingDelivery) {
            return;
        }

        $this->resetVisitForm();
        $this->stopMode = $mode;
        $this->stopConfirming = false;
        $this->resetErrorBag(['stopReason', 'general', 'uangDiterima']);

        // Jalur (a): pra-isi hitungan tagihan (anggap belum ada retur) supaya
        // operator tinggal sesuaikan sisa bagus / dodol sisa.
        if ($mode === 'tagih') {
            $this->hitungTagihan();
        }
    }

    /** Kembali ke layar pilih cara stop. */
    public function backToStopPick(): void
    {
        $this->stopMode = 'pick';
        $this->stopConfirming = false;
        $this->resetErrorBag(['stopReason', 'general', 'uangDiterima']);
    }

    /** Batal total dari alur stop, kembali ke layar pilih aksi. */
    public function cancelStop(): void
    {
        $this->resetVisitForm();
        $this->stopMode = '';
        $this->stopReason = '';
        $this->stopConfirming = false;
        $this->chosenAction = null;
        $this->resetErrorBag(['stopReason', 'general', 'uangDiterima']);
    }

    /**
     * Gerbang konfirmasi tegas: validasi alasan dulu, baru tampilkan peringatan
     * "FINAL" sebelum eksekusi. Operator tidak bisa 1-tap langsung jalan.
     */
    public function requestStopConfirm(): void
    {
        $this->resetErrorBag(['stopReason', 'general']);

        if (! array_key_exists($this->stopReason, Kiosk::STOP_REASONS)) {
            $this->addError('stopReason', 'Pilih alasan dulu');
            return;
        }

        $this->stopConfirming = true;
    }

    /** Eksekusi stop setelah konfirmasi, dispatch sesuai jalur terpilih. */
    public function executeStop(): void
    {
        if (! $this->stopConfirming) {
            return;
        }

        if ($this->stopMode === 'tagih') {
            $this->stopWithSettle();
        } elseif ($this->stopMode === 'tanpa_tagih') {
            $this->stopWithoutSettle();
        }
    }

    /**
     * Jalur (a): catat tagihan terakhir LALU nonaktifkan kios — ATOMIK.
     * Reuse persistVisitFromState() (settle_only). URUTAN WAJIB: settle commit
     * dulu, baru kios non-aktif, dalam SATU transaksi. Kalau settle gagal,
     * kios TIDAK ter-nonaktif (rollback total) → tidak ada titipan menggantung.
     */
    private function stopWithSettle(): void
    {
        // 🔒 Re-verifikasi kepemilikan server-side SEBELUM menonaktifkan/menyettle
        // kios + pastikan titipan yang di-settle milik kios ini (bukan owner lain).
        if (! $this->selectedKiosk || ! $this->ownedKiosk($this->selectedKiosk->id)) {
            $this->addError('general', 'Kios tidak valid atau bukan milik Anda. Tutup form dan coba lagi.');
            return;
        }
        if (! $this->pendingDelivery || (int) $this->pendingDelivery->kiosk_id !== (int) $this->selectedKiosk->id) {
            $this->addError('general', 'Kios tidak punya titipan yang cocok. Tutup form dan coba lagi.');
            return;
        }
        if (! array_key_exists($this->stopReason, Kiosk::STOP_REASONS)) {
            $this->addError('stopReason', 'Pilih alasan dulu');
            return;
        }

        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
        ]);

        // Pastikan auto-detect resolveVisitAction() jatuh ke settle_only
        // (ada titipan + drop 0 + bukan niat 'cek').
        $this->dropBaru = 0;
        $this->chosenAction = null;
        $this->extensionGranted = false;

        try {
            DB::transaction(function () {
                $message = $this->persistVisitFromState();
                if ($message === null) {
                    // Error sudah di-addError oleh persist → rollback semua.
                    throw new \RuntimeException('Penyimpanan tagihan gagal.');
                }

                // Hanya tercapai bila settle SUKSES → baru nonaktifkan kios.
                $this->selectedKiosk->update([
                    'is_active' => false,
                    'stopped_at' => now(),
                    'stop_reason' => $this->stopReason,
                    'stopped_by' => 'operator',
                ]);
            });
        } catch (\Throwable $e) {
            if ($this->getErrorBag()->isNotEmpty()) {
                return; // error spesifik dari persist sudah ditampilkan
            }
            $this->addError('general', 'Gagal menghentikan kedai. Coba lagi.');
            return;
        }

        $name = $this->selectedKiosk->name;
        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', "Tagihan terakhir tercatat. Kedai {$name} dihentikan.");
    }

    /**
     * Jalur (b): stop tanpa tagih (kedai kabur / tak bisa ditagih). Sisa titipan
     * dicatat sebagai KERUGIAN lewat Settlement (semua sisa = dodol basi,
     * uang diterima 0) → omset 0, laku 0, titipan TERTUTUP (tidak menggantung).
     * Reuse persistVisitFromState() — tanpa titipan jadi sekadar check_only.
     */
    private function stopWithoutSettle(): void
    {
        // 🔒 Re-verifikasi kepemilikan server-side SEBELUM menonaktifkan kios +
        // (kalau ada titipan) pastikan titipannya milik kios ini, bukan owner lain.
        if (! $this->selectedKiosk || ! $this->ownedKiosk($this->selectedKiosk->id)) {
            $this->addError('general', 'Kios tidak valid atau bukan milik Anda. Tutup form dan coba lagi.');
            return;
        }
        if ($this->pendingDelivery && (int) $this->pendingDelivery->kiosk_id !== (int) $this->selectedKiosk->id) {
            $this->addError('general', 'Data titipan tidak cocok dengan kios. Tutup form dan coba lagi.');
            return;
        }
        if (! array_key_exists($this->stopReason, Kiosk::STOP_REASONS)) {
            $this->addError('stopReason', 'Pilih alasan dulu');
            return;
        }

        // Catat seluruh sisa titipan sebagai kerugian (basi/hilang), uang 0.
        $this->dropBaru = 0;
        $this->chosenAction = null;
        $this->extensionGranted = false;
        $this->returnFresh = 0;
        $this->uangDiterima = 0;
        $this->returnExpired = $this->pendingDelivery
            ? (int) $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA
            : 0;
        // Tandai settlement titipan lama sebagai kerugian (write-off) agar owner
        // dashboard bisa membedakannya dari tagih biasa yang semua diretur basi.
        $this->stopWriteOff = (bool) $this->pendingDelivery;
        // Tanpa titipan → persist jadi check_only; tandai jejak stop.
        $this->alasanCheck = $this->pendingDelivery ? '' : 'stop_titipan';

        try {
            DB::transaction(function () {
                $message = $this->persistVisitFromState();
                if ($message === null) {
                    throw new \RuntimeException('Pencatatan kerugian gagal.');
                }

                $this->selectedKiosk->update([
                    'is_active' => false,
                    'stopped_at' => now(),
                    'stop_reason' => $this->stopReason,
                    'stopped_by' => 'operator',
                ]);
            });
        } catch (\Throwable $e) {
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
            $this->addError('general', 'Gagal menghentikan kedai. Coba lagi.');
            return;
        }

        $name = $this->selectedKiosk->name;
        $hadPending = (bool) $this->pendingDelivery;
        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', $hadPending
            ? "Kedai {$name} dihentikan. Sisa dodol dicatat sebagai kerugian."
            : "Kedai {$name} dihentikan.");
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['returnFresh', 'returnExpired'])) {
            $this->hitungTagihan();
        }
    }

    public function hitungTagihan()
    {
        if ($this->pendingDelivery) {
            $totalBijiDititip = $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;
            $this->terjual = $totalBijiDititip - ((int)$this->returnFresh + (int)$this->returnExpired);
            $this->terjual = max(0, $this->terjual);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
            // Default uang diterima = tagihan penuh (operator bisa override manual)
            $this->uangDiterima = $this->tagihan;
        }
    }

    public function incrementDrop()
    {
        $this->dropBaru = max(0, $this->dropBaru + 1);
    }

    public function decrementDrop()
    {
        $this->dropBaru = max(0, $this->dropBaru - 1);
    }

    /**
     * Label aksi yang akan dilakukan, auto-detect dari kondisi form.
     */
    public function getVisitActionProperty(): string
    {
        return $this->resolveVisitAction();
    }

    private function resolveVisitAction(): string
    {
        // Niat eksplisit "Cek Sisa" → SELALU check_only, walau kios masih punya
        // titipan. Tanpa guard ini, kios bertitipan + drop=0 akan auto-detect ke
        // settle_only dan menutup titipan tak sengaja. correctVisit() menetralkan
        // chosenAction=null, jadi guard ini tak pernah keliru menyala saat koreksi.
        // Aksi eksplisit DIDAHULUKAN dari isCashOnly agar kedai cash-only pun bisa
        // Titip Cash (BS support) / Lewati / Mulai Titipan lewat picker adaptif.
        if ($this->chosenAction === 'cek') {
            return 'check_only';
        }

        // AKSI 2 "Titip Cash": naruh ekstra dibayar tunai (cash_sale), TIDAK menagih
        // titipan lama, TIDAK urus BS. Ter-persist lewat cabang khusus di
        // persistVisitFromState(); di sini cukup labeli cash_sale.
        if ($this->chosenAction === 'titip_cash') {
            return 'cash_sale';
        }

        // GANTI KE CASH: settle titipan terakhir tanpa titip lagi (drop=0). Kedai
        // dijadikan cash-only di persistVisitFromState(). Di sini = settle_only.
        if ($this->chosenAction === 'ganti_cash') {
            return 'settle_only';
        }

        // MULAI TITIPAN: kedai cash-only/baru mulai konsinyasi. Drop konsinyasi baru
        // (drop_only) + set jatah + is_cash_only=false di persistVisitFromState().
        if ($this->chosenAction === 'mulai_titipan') {
            return 'drop_only';
        }

        // Kios cash only (tanpa aksi eksplisit di atas — mis. jalur koreksi) = cash.
        if ($this->isCashOnly) {
            return 'cash_sale';
        }

        $drop = (int) $this->dropBaru;
        $hasPending = (bool) $this->pendingDelivery;

        if ($hasPending && $drop > 0) {
            return 'drop_and_settle';
        }
        if (!$hasPending && $drop > 0) {
            return 'drop_only';
        }
        if ($hasPending && $drop === 0) {
            return 'settle_only';
        }

        return 'check_only';
    }

    /**
     * 1 jenis dodol, 1 varian aktif. Operator tidak memilih.
     */
    private function resolveActiveVariant(): ProductVariant
    {
        // 🔒 Varian ter-scope owner operator (varian → product.owner_id). Jangan
        // ambil varian owner lain untuk mencatat penjualan.
        $ownerId = auth()->user()?->owner_id;

        $variant = ProductVariant::where('is_active', true)
            ->when($ownerId !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('owner_id', $ownerId)))
            ->first();

        if (!$variant) {
            throw new \RuntimeException('Tidak ada varian produk aktif.');
        }

        return $variant;
    }

    public function saveVisit()
    {
        if ($this->extensionGranted) {
            $this->dropBaru = 0;
        }

        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'dropBaru' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
            'jatahMulai' => 'nullable|integer|min:0',
        ]);

        $this->resetErrorBag('general');

        // 🔒 Re-verifikasi kepemilikan server-side (jangan percaya $selectedKiosk
        // mentah dari klien) + pastikan titipan yang mau di-settle memang milik
        // kios ini. Menutup penulisan lintas-tenant lewat properti yang di-hidrasi.
        if (! $this->selectedKiosk || ! $this->ownedKiosk($this->selectedKiosk->id)) {
            $this->addError('general', 'Kios tidak valid atau bukan milik Anda. Tutup form dan coba lagi.');
            return;
        }
        if ($this->pendingDelivery && (int) $this->pendingDelivery->kiosk_id !== (int) $this->selectedKiosk->id) {
            $this->addError('general', 'Data titipan tidak cocok dengan kios. Tutup form dan coba lagi.');
            return;
        }

        // Guard idempotensi: submit ganda (koneksi lambat, request retry) tidak
        // boleh membuat kunjungan duplikat. Kunjungan ulang yang sah ke kios
        // yang sama tetap bisa setelah jeda singkat ini.
        $alreadySaved = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $this->selectedKiosk->id)
            ->where('visited_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($alreadySaved) {
            $this->loadKiosks();
            $this->closeVisitModal();
            session()->flash('visit_saved', 'Kunjungan sudah tersimpan.');
            return;
        }

        $message = $this->persistVisitFromState();
        if ($message === null) {
            return;
        }

        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', $message);
    }

    // --- UI KOREKSI ANGKA VISIT ---
    /**
     * Buka form koreksi untuk kunjungan aktif TERAKHIR ke sebuah kios. Menjalankan
     * pre-check yang sama seperti correctVisit() agar operator tahu lebih awal bila
     * visit tak bisa dikoreksi, lalu mengisi form dengan angka LAMA yang direkonstruksi
     * dari record (delivery + settlement). Tidak menyentuh logic reversal.
     */
    public function openCorrectionModal(int $kioskId): void
    {
        $this->resetErrorBag('correction');

        $visit = KioskVisit::active()
            ->where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kioskId)
            ->orderByDesc('visited_at')
            ->first();

        if (! $visit) {
            $this->addError('correction', 'Belum ada kunjungan aktif ke kios ini.');
            return;
        }

        // Pre-check (selaras correctVisit) — gagal mana pun: jangan buka modal.
        if ($this->trip->ended_at !== null) {
            $this->addError('correction', 'Trip sudah diakhiri, koreksi tidak bisa dilakukan.');
            return;
        }

        $kiosk = Kiosk::find($kioskId);
        if ($kiosk && $kiosk->name === Kiosk::WALKIN_SENTINEL_NAME) {
            $this->addError('correction', 'Penjualan walk-in tidak bisa dikoreksi.');
            return;
        }
        if ($visit->changed_default) {
            $this->addError('correction', 'Kunjungan yang mengubah default kios tidak bisa dikoreksi.');
            return;
        }
        if ($visit->new_delivery_id !== null
            && ! Delivery::where('kiosk_visit_id', $visit->id)->whereKey($visit->new_delivery_id)->exists()) {
            $this->addError('correction', 'Kunjungan ini dibuat sebelum fitur koreksi dan tidak bisa dikoreksi.');
            return;
        }

        // --- Rekonstruksi angka lama dari record ---
        $isCashOnly = (bool) ($kiosk?->is_cash_only);

        // Drop mika = total konsinyasi + cash-extra yang dibuat visit ini (BS dikecualikan).
        $linkedDeliveries = Delivery::where('kiosk_visit_id', $visit->id)->get();
        $dropQty = (int) $linkedDeliveries
            ->whereIn('delivery_type', ['consignment', 'cash_sale'])
            ->sum('qty_delivered');
        $hasDrop = $dropQty > 0;

        // Penagihan titipan lama: settled_delivery_id menunjuk titipan lama (bukan delivery
        // yang dibuat visit ini, dan bukan kios cash-only yang lunas otomatis).
        $hasSettle = ! $isCashOnly
            && $visit->settled_delivery_id !== null
            && ! $linkedDeliveries->pluck('id')->contains($visit->settled_delivery_id);

        $uang = 0;
        $fresh = 0;
        $expired = 0;
        if ($hasSettle) {
            $settlement = Settlement::where('delivery_id', $visit->settled_delivery_id)->first();
            if ($settlement) {
                $uang = (int) $settlement->amount_paid;
                $fresh = (int) $settlement->qty_returned_fresh;
                $expired = (int) $settlement->qty_returned_expired;
            }
        }

        if (! $hasDrop && ! $hasSettle) {
            $this->addError('correction', 'Kunjungan ini tidak punya angka untuk dikoreksi.');
            return;
        }

        // Isi form dengan angka lama (operator tinggal ubah yang salah).
        $this->dropBaru = $dropQty;
        $this->returnFresh = $fresh;
        $this->returnExpired = $expired;
        $this->uangDiterima = $uang;

        $this->correctionVisitId = $visit->id;
        $this->correctionHasDrop = $hasDrop;
        $this->correctionHasSettle = $hasSettle;
        $this->correctionKioskName = $kiosk?->name;
        $this->isCorrectionModalOpen = true;
    }

    public function closeCorrectionModal(): void
    {
        $this->isCorrectionModalOpen = false;
        $this->correctionVisitId = null;
        $this->correctionHasDrop = false;
        $this->correctionHasSettle = false;
        $this->correctionKioskName = null;
        $this->dropBaru = 0;
        $this->returnFresh = 0;
        $this->returnExpired = 0;
        $this->uangDiterima = 0;
        $this->resetErrorBag('correction');
    }

    /**
     * Submit form koreksi → delegasi ke correctVisit() (logic reversal yang sudah ada
     * & lulus test). Tutup modal hanya bila sukses; bila ada error, modal tetap terbuka.
     */
    public function submitCorrection(): void
    {
        if ($this->correctionVisitId === null) {
            return;
        }

        $this->correctVisit(
            $this->correctionVisitId,
            (int) $this->dropBaru,
            (int) $this->returnFresh,
            (int) $this->returnExpired,
            (int) $this->uangDiterima,
        );

        // correctVisit menambahkan error ke bag 'correction' bila gagal → biarkan modal terbuka.
        if ($this->getErrorBag()->has('correction')) {
            return;
        }

        $this->closeCorrectionModal();
    }

    /**
     * Koreksi angka kunjungan TERAKHIR ke sebuah kios pada trip aktif (reversal).
     * Prinsip: record finansial visit lama DIHAPUS (angka lama hilang dari hitungan),
     * baris kiosk_visits lama DISIMPAN + ditandai corrected_at (audit trail), lalu
     * angka baru ditulis ulang lewat persistVisitFromState() (satu sumber kebenaran).
     *
     * Hanya ANGKA yang dikoreksi (drop mika, retur fresh/expired, uang diterima);
     * bukan ganti kios / aksi / BS / default kios.
     */
    public function correctVisit(int $visitId, int $dropBaru, int $returnFresh, int $returnExpired, int $uangDiterima): void
    {
        $this->resetErrorBag('correction');

        $visit = KioskVisit::with('kiosk')->find($visitId);

        // --- Validasi batasan koreksi ---
        if (! $visit || $visit->trip_id !== $this->trip->id) {
            $this->addError('correction', 'Kunjungan tidak ditemukan pada trip ini.');
            return;
        }
        if ($this->trip->ended_at !== null) {
            $this->addError('correction', 'Trip sudah diakhiri, koreksi tidak bisa dilakukan.');
            return;
        }
        if ($visit->corrected_at !== null) {
            $this->addError('correction', 'Kunjungan ini sudah dikoreksi sebelumnya.');
            return;
        }
        if ($visit->kiosk && $visit->kiosk->name === Kiosk::WALKIN_SENTINEL_NAME) {
            $this->addError('correction', 'Penjualan walk-in tidak bisa dikoreksi.');
            return;
        }
        if ($visit->changed_default) {
            $this->addError('correction', 'Kunjungan yang mengubah default kios tidak bisa dikoreksi.');
            return;
        }

        // Hanya visit TERAKHIR (tidak ada kunjungan aktif lebih baru ke kios yang sama).
        $adaLebihBaru = KioskVisit::active()
            ->where('trip_id', $this->trip->id)
            ->where('kiosk_id', $visit->kiosk_id)
            ->where('id', '!=', $visit->id)
            ->where('visited_at', '>=', $visit->visited_at)
            ->exists();
        if ($adaLebihBaru) {
            $this->addError('correction', 'Hanya kunjungan terakhir ke kios ini yang bisa dikoreksi.');
            return;
        }

        // Determinisme: visit yang membuat delivery harus punya linkage lengkap
        // (data sebelum fitur koreksi tidak punya kiosk_visit_id → tidak bisa direversal).
        if ($visit->new_delivery_id !== null
            && ! Delivery::where('kiosk_visit_id', $visit->id)->whereKey($visit->new_delivery_id)->exists()) {
            $this->addError('correction', 'Kunjungan ini dibuat sebelum fitur koreksi dan tidak bisa dikoreksi.');
            return;
        }

        // --- Siapkan state untuk penyimpanan ulang ---
        $this->selectedKiosk = $visit->kiosk()->first();
        $this->isCashOnly = (bool) ($this->selectedKiosk?->is_cash_only);
        // Titipan lama yang akan di-settle ulang = yang dulu di-settle visit ini
        // (settlement-nya dihapus di transaksi → titipan aktif lagi).
        $this->pendingDelivery = $visit->settled_delivery_id
            ? Delivery::find($visit->settled_delivery_id)
            : null;

        $this->dropBaru = $dropBaru;
        $this->returnFresh = $returnFresh;
        $this->returnExpired = $returnExpired;
        $this->uangDiterima = $uangDiterima;
        // Koreksi hanya angka — flag perilaku lain dinetralkan.
        // chosenAction=null WAJIB: cegah guard 'cek' di resolveVisitAction() keliru
        // memaksa check_only saat koreksi visit tagih/settle (akan merusak titipan).
        $this->chosenAction = null;
        $this->extensionGranted = false;
        $this->ubahJatah = false;
        $this->qtyBsCash = 0;
        $this->adaBsRedistribusi = false;
        $this->qtyBsMika = 0;
        $this->alasanCheck = '';
        $this->sisaBiji = 0;

        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'dropBaru' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
            'jatahMulai' => 'nullable|integer|min:0',
        ]);

        try {
            DB::transaction(function () use ($visit) {
                // a. Hapus settlement milik delivery yang dibuat visit ini.
                $linkedDeliveryIds = Delivery::where('kiosk_visit_id', $visit->id)->pluck('id');
                if ($linkedDeliveryIds->isNotEmpty()) {
                    Settlement::whereIn('delivery_id', $linkedDeliveryIds)->delete();
                }

                // b. Hapus settlement penagihan titipan lama → titipan lama aktif lagi.
                if ($visit->settled_delivery_id) {
                    Settlement::where('delivery_id', $visit->settled_delivery_id)->delete();
                }

                // BS: kembalikan counter trip sebelum delivery BS dihapus.
                $bsQty = (int) Delivery::where('kiosk_visit_id', $visit->id)
                    ->where('delivery_type', 'bs_redistribution')
                    ->sum('qty_delivered');
                if ($bsQty > 0) {
                    $this->trip->decrement('qty_bs_redistributed', $bsQty);
                }

                // c. Hapus semua delivery yang dibuat visit ini (settlement-nya sudah dihapus).
                Delivery::where('kiosk_visit_id', $visit->id)->delete();

                // d. Tandai visit lama sebagai dikoreksi (audit trail, baris disimpan).
                $visit->update(['corrected_at' => now()]);

                // e. Tulis ulang dengan angka baru — visit baru menaut ke visit lama.
                $message = $this->persistVisitFromState($visit->id);
                if ($message === null) {
                    // Error sudah di-addError oleh persist → rollback semuanya.
                    throw new \RuntimeException('Penyimpanan ulang gagal.');
                }
            });
        } catch (\Throwable $e) {
            if ($this->getErrorBag()->isNotEmpty()) {
                return; // error spesifik sudah ditambahkan
            }
            $this->addError('correction', 'Gagal mengoreksi kunjungan. Coba lagi.');
            return;
        }

        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', 'Kunjungan berhasil dikoreksi.');
    }

    /**
     * Inti penyimpanan kunjungan dari state komponen ($selectedKiosk, $pendingDelivery,
     * dropBaru/returnFresh/returnExpired/uangDiterima + flag). SATU sumber kebenaran:
     * dipakai saveVisit() DAN correctVisit(). Mengembalikan pesan sukses, atau null bila
     * gagal (error sudah di-addError). TIDAK memanggil loadKiosks/flash/closeModal —
     * itu tugas pemanggil. Semua delivery yang dibuat ditaut ke KioskVisit lewat
     * kiosk_visit_id (linkage deterministik untuk reversal koreksi).
     *
     * @param int|null $correctionOfVisitId  Bila ini hasil koreksi: id visit yang dikoreksi.
     */
    private function persistVisitFromState(?int $correctionOfVisitId = null): ?string
    {
        $drop = (int) $this->dropBaru;
        $fresh = (int) $this->returnFresh;
        $expired = (int) $this->returnExpired;
        $hasPending = (bool) $this->pendingDelivery;
        $action = $this->resolveVisitAction();
        $isSettleAction = in_array($action, ['drop_and_settle', 'settle_only'], true);
        $isDrop = in_array($action, ['drop_and_settle', 'drop_only'], true);

        // Koreksi angka (dari correctVisit) HANYA memperbaiki angka historis — BUKAN
        // titip normal. Blokir "titip = jatah" & penetapan jatah baseline TIDAK berlaku
        // saat koreksi (operator boleh mengoreksi drop ke angka berapa pun, jatah kios
        // tak ikut berubah). Sinyal: $correctionOfVisitId terisi.
        $isCorrection = $correctionOfVisitId !== null;

        // === SKENARIO KIOS CASH ONLY (jalur legacy/koreksi) ===
        // Setiap kunjungan = penjualan cash langsung lunas, tanpa konsinyasi. Kini kedai
        // cash-only lewat picker adaptif (Titip Cash/Lewati/Mulai Titipan), jadi cabang
        // ini HANYA menyala saat tak ada aksi eksplisit (mis. correctVisit → chosenAction
        // null). Aksi eksplisit (titip_cash/cek/ganti_cash/mulai_titipan) ditangani cabang
        // masing-masing di bawah — jangan diserobot jadi cash_sale polos di sini.
        if ($this->isCashOnly && ($this->chosenAction === null || $this->chosenAction === 'cash')) {
            if ($drop <= 0) {
                $this->addError('general', 'Jumlah mika harus lebih dari 0 untuk penjualan cash.');
                return null;
            }

            try {
                $variant = $this->resolveActiveVariant();
            } catch (\RuntimeException $e) {
                $this->addError('general', $e->getMessage());
                return null;
            }

            try {
                DB::transaction(function () use ($drop, $variant, $correctionOfVisitId) {
                    $delivery = Delivery::create([
                        'kiosk_id' => $this->selectedKiosk->id,
                        'trip_id' => $this->trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'cash_sale',
                        'qty_delivered' => $drop,
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                    ]);

                    $totalBiji = $drop * self::BIJI_PER_MIKA;
                    $amountDue = $totalBiji * self::HARGA_PER_BIJI;

                    Settlement::create([
                        'delivery_id' => $delivery->id,
                        'visit_date' => today(),
                        'qty_sold' => $totalBiji,
                        'qty_returned_fresh' => 0,
                        'qty_returned_expired' => 0,
                        'amount_due' => $amountDue,
                        'amount_paid' => $amountDue, // langsung lunas
                    ]);

                    $visit = KioskVisit::create([
                        'trip_id' => $this->trip->id,
                        'kiosk_id' => $this->selectedKiosk->id,
                        'visited_at' => now(),
                        'visit_action' => 'cash_sale',
                        'new_delivery_id' => $delivery->id,
                        'settled_delivery_id' => $delivery->id,
                        'extension_granted' => false,
                        'correction_of_visit_id' => $correctionOfVisitId,
                    ]);

                    $delivery->update(['kiosk_visit_id' => $visit->id]);
                });
            } catch (\Throwable $e) {
                $this->addError('general', 'Gagal menyimpan. Coba lagi.');
                return null;
            }

            return 'Kunjungan cash berhasil disimpan.';
        }

        // === AKSI 2 — TITIP CASH (kios non-cash-only) ===
        // Operator naruh $drop mika EKSTRA dibayar TUNAI sekarang, BEBAS berapa saja
        // (tak terikat jatah). TIDAK menagih titipan lama (pending dibiarkan utuh,
        // ditagih nanti pas siklus normal). Jatah kios TIDAK berubah (ubah-jatah tak
        // relevan di sini — dihapus 12 Juli 2026). cash_sale langsung lunas → masuk
        // omset & komisi (getTotalDropReal).
        //
        // BS (biji tak layak jual) yang ditemukan saat cek, DICATAT per biji:
        //   cash_dibayar = (biji_ditaruh − biji_BS) × HARGA_PER_BIJI  (kedai TAK bayar BS)
        //   biji_BS      = KERUGIAN owner → settlement is_writeoff=true + qty_returned_expired
        //                  (mekanisme SAMA dgn Stop Tanpa Tagih → satu laporan kerugian owner)
        //   komisi       = mika DITARUH penuh: qty_delivered = $cashMika (TIDAK dikurangi BS),
        //                  jadi getTotalDropReal tak terpengaruh BS.
        if ($this->chosenAction === 'titip_cash') {
            $cashMika = $drop;
            if ($cashMika <= 0) {
                $this->addError('general', 'Jumlah mika cash harus lebih dari 0.');
                return null;
            }

            $totalBiji = $cashMika * self::BIJI_PER_MIKA;
            $bsBiji = max(0, (int) $this->qtyBsCash);
            if ($bsBiji > $totalBiji) {
                $this->addError('general', 'BS (biji) tidak boleh melebihi biji yang ditaruh ('.$totalBiji.').');
                return null;
            }

            $bijiBayar = $totalBiji - $bsBiji;              // biji yang dibayar kedai
            $amountDue = $bijiBayar * self::HARGA_PER_BIJI; // cash yang diterima

            // KEDAI BOOKING → jenis final = CASH: Titip Cash pertama pada kedai booking
            // (belum ada dodol, belum cash-only) menetapkan kedai jadi cash-only seterusnya.
            // Kedai konsinyasi biasa (punya jatah) yang naruh cash-ekstra TIDAK berubah.
            $bookingJadiCash = $this->selectedKiosk->isBooking();

            try {
                $variant = $this->resolveActiveVariant();
            } catch (\RuntimeException $e) {
                $this->addError('general', $e->getMessage());
                return null;
            }

            try {
                DB::transaction(function () use ($cashMika, $bsBiji, $bijiBayar, $amountDue, $variant, $correctionOfVisitId, $bookingJadiCash) {
                    $delivery = Delivery::create([
                        'kiosk_id' => $this->selectedKiosk->id,
                        'trip_id' => $this->trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'cash_sale',
                        'qty_delivered' => $cashMika, // PENUH — komisi tidak dikurangi BS
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                    ]);

                    Settlement::create([
                        'delivery_id' => $delivery->id,
                        'visit_date' => today(),
                        'qty_sold' => $bijiBayar,            // biji yang menghasilkan uang
                        'qty_returned_fresh' => 0,
                        'qty_returned_expired' => $bsBiji,   // BS → basis laporan kerugian
                        'amount_due' => $amountDue,
                        'amount_paid' => $amountDue,         // langsung lunas (cash)
                        'is_writeoff' => $bsBiji > 0,        // reuse mekanisme kerugian owner
                    ]);

                    // Kedai booking → tetapkan cash-only seterusnya (jenis final).
                    if ($bookingJadiCash) {
                        $this->selectedKiosk->update(['is_cash_only' => true]);
                    }

                    // Jatah kios TIDAK berubah di AKSI 2 (changed_default=false).
                    $visit = KioskVisit::create([
                        'trip_id' => $this->trip->id,
                        'kiosk_id' => $this->selectedKiosk->id,
                        'visited_at' => now(),
                        'visit_action' => 'cash_sale',
                        'new_delivery_id' => $delivery->id,
                        'settled_delivery_id' => $delivery->id, // self-settle; pending lama TAK disentuh
                        'extension_granted' => false,
                        'changed_default' => false,
                        'correction_of_visit_id' => $correctionOfVisitId,
                    ]);

                    $delivery->update(['kiosk_visit_id' => $visit->id]);
                });
            } catch (\Throwable $e) {
                $this->addError('general', 'Gagal menyimpan. Coba lagi.');
                return null;
            }

            return $bsBiji > 0
                ? 'Titip cash disimpan. Cash Rp '.number_format($amountDue, 0, ',', '.').' ('.$bsBiji.' biji BS jadi kerugian owner).'
                : 'Titip cash berhasil disimpan.';
        }

        // === AKSI 1 (tagih_titip) + AKSI 3 (cek) + GANTI KE CASH + MULAI TITIPAN ===
        $defaultQty = (int) ($this->selectedKiosk->default_qty_mika ?? 0);
        $ubahJatah = $this->ubahJatah; // hanya berpengaruh di AKSI 1 (isDrop) — lihat di bawah.

        // GANTI KE CASH (settle_only, kedai → cash-only) & MULAI TITIPAN (drop_only,
        // kedai → konsinyasi): aksi mandiri, TIDAK ikut blokir 2-langkah AKSI 1.
        $isGantiCash = $this->chosenAction === 'ganti_cash';
        $isMulaiTitipan = $this->chosenAction === 'mulai_titipan';

        // MULAI TITIPAN wajib punya jatah seterusnya (>=1) + mika dititip (>=1).
        if ($isMulaiTitipan) {
            if ($drop < 1) {
                $this->addError('general', 'Isi berapa mika yang dititip (minimal 1).');
                return null;
            }
            if ((int) $this->jatahMulai < 1) {
                $this->addError('general', 'Isi jatah seterusnya (minimal 1 mika).');
                return null;
            }
        }

        // BLOKIR 2-LANGKAH (owner 11 Juli 2026): titip konsinyasi HARUS = jatah kios.
        // Titip beda (KURANG atau LEBIH) TANPA centang "Ubah jatah" → TOLAK, arahkan.
        // Cegah angka "nyasar tanpa tujuan" yang diam-diam menggeser jatah / kacaukan
        // tagihan. Verifikasi 2-langkah: mau beda? centang "Ubah jatah" dulu (aksi sadar).
        // PENGECUALIAN: kios belum punya jatah (defaultQty < 1 → kios benar-baru) →
        // titip pertama MENETAPKAN jatah baseline, tanpa blokir. MULAI TITIPAN juga
        // dikecualikan (jatah di-set eksplisit dari field jatah, bukan dari angka titip).
        // Lapis server (anti-bypass UI): guard ini tetap menolak walau tombol diakali.
        if (! $isCorrection && ! $isMulaiTitipan && $isDrop && $defaultQty >= 1 && ! $ubahJatah && $drop !== $defaultQty) {
            $this->addError('general', sprintf(
                'Titip harus sama dengan jatah (%1$d). '
                .'Mau ubah jatah kedai ini? Centang "Ubah jatah permanen" dulu. '
                .'Mau naruh ekstra dibayar tunai? Pakai aksi "Titip Cash". '
                .'Salah ketik? Betulkan jadi %1$d.',
                $defaultQty
            ));
            return null;
        }

        // Validasi AKSI 1 + ubah jatah: jatah baru (= angka titip $drop) minimal 1.
        if ($isDrop && $ubahJatah && $drop < 1) {
            $this->addError('general', 'Jatah baru minimal 1 mika.');
            return null;
        }

        // SKENARIO 7: mika BS redistribusi yang ikut di-drop (delivery terpisah,
        // titipan konsinyasi biasa — TIDAK di-settle saat ini, dibayar saat terjual).
        $bsMika = ($isDrop && $this->adaBsRedistribusi && (int) $this->qtyBsMika > 0)
            ? (int) $this->qtyBsMika
            : 0;

        // LEGACY (Tahap 2): dulu "Tunda Bayar" set extensionGranted=true → settle ditunda.
        // Sekarang extensionGranted SELALU false (Tunda dilebur ke Cek Sisa "belum bisa
        // bayar" = check_only). Jadi $extension selalu false & $createSettlement = isSettle.
        // Dibiarkan agar struktur tetap kompatibel (no-op).
        $extension = $this->extensionGranted && $hasPending && $isSettleAction;
        $createSettlement = $isSettleAction && !$extension;

        // UBAH JATAH satu-angka — HANYA AKSI 1 (isDrop) + centang → jatah baru = $drop
        // (angka titip). Dua arah (naik/turun). Menandai changed_default (tak bisa
        // dikoreksi). AKSI 3 (cek) TIDAK punya ubah-jatah lagi (owner 12 Juli 2026).
        $applyJatahDrop = $isDrop && $ubahJatah && $drop >= 1;
        $changedDefault = $applyJatahDrop;

        // KIOS BARU (belum punya jatah) menetapkan baseline dari titip pertama — BUKAN
        // "ubah" (changed_default tetap false → kunjungan masih bisa dikoreksi). Tidak
        // berlaku saat koreksi (jatah kios tak boleh berubah gara-gara mengoreksi angka).
        $establishBaseline = ! $isCorrection && ! $isMulaiTitipan && $isDrop && ! $ubahJatah && $defaultQty < 1 && $drop >= 1;

        // --- Hitung ulang terjual & tagihan dari server (jangan percaya client),
        //     TANPA menimpa uangDiterima yang diinput operator ---
        if ($createSettlement) {
            $totalBiji = (int) $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;

            if (($fresh + $expired) > $totalBiji) {
                $this->addError('general', 'Total retur melebihi jumlah titipan sebelumnya.');
                return null;
            }

            $this->terjual = max(0, $totalBiji - $fresh - $expired);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
        }

        if ((int) $this->uangDiterima < 0) {
            $this->addError('uangDiterima', 'Uang diterima tidak boleh negatif.');
            return null;
        }

        // --- Resolve varian aktif SEBELUM transaksi (tidak ada block stok batch) ---
        try {
            $variant = $isDrop ? $this->resolveActiveVariant() : null;
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return null;
        }

        try {
            DB::transaction(function () use ($action, $drop, $fresh, $expired, $isSettleAction, $createSettlement, $extension, $isDrop, $variant, $bsMika, $applyJatahDrop, $establishBaseline, $changedDefault, $correctionOfVisitId, $isGantiCash, $isMulaiTitipan) {
                $newDeliveryId = null;
                $settledDeliveryId = null;
                $createdDeliveryIds = [];

                // Aksi settle-type selalu menandai delivery lama (agar extension count
                // & jejak kunjungan terhitung), meski settlement-nya ditunda.
                if ($isSettleAction) {
                    $settledDeliveryId = $this->pendingDelivery->id;
                }

                // 1. Settle titipan lama (dilewati kalau extension/tunda bayar)
                if ($createSettlement) {
                    Settlement::create([
                        'delivery_id' => $this->pendingDelivery->id,
                        'visit_date' => today(),
                        'qty_sold' => (int) $this->terjual,
                        'qty_returned_fresh' => $fresh,
                        'qty_returned_expired' => $expired,
                        'amount_due' => (int) $this->tagihan,
                        'amount_paid' => (int) $this->uangDiterima,
                        // Janji bayar (Tahap 3): hanya saat bayar KURANG dari tagihan
                        // (sisa = piutang). janjiBayar kosong di alur Stop → notes null.
                        'notes' => ((int) $this->uangDiterima < (int) $this->tagihan && trim($this->janjiBayar) !== '')
                            ? 'Janji bayar: '.trim($this->janjiBayar)
                            : null,
                        // Kerugian (Stop Tanpa Tagih) ditandai untuk laporan owner.
                        'is_writeoff' => $this->stopWriteOff,
                        // status & paid_at di-set otomatis oleh SettlementObserver
                    ]);
                }

                // GANTI KE CASH: titipan terakhir sudah di-settle di atas → kedai jadi
                // cash-only mulai sekarang (tetap aktif & tetap dikunjungi, mode beli tunai).
                if ($isGantiCash) {
                    $this->selectedKiosk->update(['is_cash_only' => true]);
                }

                // MULAI TITIPAN: kedai cash-only/baru jadi konsinyasi. Jatah seterusnya
                // = $jatahMulai (field terpisah, BUKAN angka titip hari ini). Delivery
                // konsinyasi $drop dibuat di blok drop di bawah.
                if ($isMulaiTitipan) {
                    $this->selectedKiosk->update([
                        'default_qty_mika' => max(1, (int) $this->jatahMulai),
                        'is_cash_only' => false,
                    ]);
                }

                // UBAH JATAH satu-angka (AKSI 1) / netapkan baseline kios baru.
                //  - AKSI 1 (isDrop) + centang → jatah baru = $drop (angka titip).
                //  - Kios baru tanpa centang → baseline = $drop (bukan "ubah", tak dikunci koreksi).
                if ($applyJatahDrop || $establishBaseline) {
                    $this->selectedKiosk->update(['default_qty_mika' => $drop]);
                }

                // 2. Drop titipan baru (new_procurement, tanpa link batch — operasional bebas)
                if ($isDrop) {
                    // Titipan hari ini = konsinyasi MURNI sejumlah $drop. Kelebihan/ekstra
                    // dibayar tunai bukan lagi di sini — itu AKSI 2 "Titip Cash" terpisah.
                    $newDelivery = Delivery::create([
                        'kiosk_id' => $this->selectedKiosk->id,
                        'trip_id' => $this->trip->id,
                        'product_variant_id' => $variant->id,
                        'procurement_batch_id' => null,
                        'source_type' => 'new_procurement',
                        'delivery_type' => 'consignment',
                        'qty_delivered' => $drop,
                        'unit_price' => $variant->sale_price_per_pack,
                        'cost_snapshot' => null,
                    ]);
                    $newDeliveryId = $newDelivery->id;
                    $createdDeliveryIds[] = $newDelivery->id;

                    // SKENARIO 7: mika BS redistribusi = delivery terpisah, titipan
                    // konsinyasi biasa (HPP 0 karena loss sudah dihitung di kios asal).
                    // TIDAK di-settle sekarang — dibayar nanti saat terjual, seperti titipan normal.
                    if ($bsMika > 0) {
                        $bsDelivery = Delivery::create([
                            'kiosk_id' => $this->selectedKiosk->id,
                            'trip_id' => $this->trip->id,
                            'product_variant_id' => $variant->id,
                            'procurement_batch_id' => null,
                            'source_type' => 'new_procurement',
                            'delivery_type' => 'bs_redistribution',
                            'qty_delivered' => $bsMika,
                            'unit_price' => $variant->sale_price_per_pack,
                            'cost_snapshot' => 0,
                        ]);
                        $createdDeliveryIds[] = $bsDelivery->id;

                        // qty BS tidak berasal dari qty_carried → dicatat terpisah.
                        $this->trip->increment('qty_bs_redistributed', $bsMika);
                    }
                }

                // 3. Catat kunjungan. alasan_check & sisa_biji khusus AKSI 3 (Lewati /
                //    check_only — TIDAK menutup titipan, tunggakan tetap nyangkut).
                //    notes: field $janjiBayar dipakai ganda —
                //      - alasan "belum bisa bayar" → "Janji bayar: ..."
                //      - alasan lain (AKSI 3) → catatan bebas apa adanya.
                $catatan = trim($this->janjiBayar);
                $visitNotes = null;
                if ($action === 'check_only' && $catatan !== '') {
                    $visitNotes = $this->alasanCheck === 'belum_bisa_bayar'
                        ? 'Janji bayar: '.$catatan
                        : $catatan;
                }

                $visit = KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $this->selectedKiosk->id,
                    'visited_at' => now(),
                    'visit_action' => $action,
                    'alasan_check' => $action === 'check_only' ? ($this->alasanCheck ?: null) : null,
                    'sisa_biji' => (($action === 'check_only' || $extension) && $this->sisaBiji > 0) ? $this->sisaBiji : null,
                    'notes' => $visitNotes,
                    'new_delivery_id' => $newDeliveryId,
                    'settled_delivery_id' => $settledDeliveryId,
                    'extension_granted' => $extension,
                    'changed_default' => $changedDefault,
                    'correction_of_visit_id' => $correctionOfVisitId,
                ]);

                // Linkage deterministik: semua delivery yang dibuat visit ini → visit.id.
                if (! empty($createdDeliveryIds)) {
                    Delivery::whereIn('id', $createdDeliveryIds)->update(['kiosk_visit_id' => $visit->id]);
                }
            });
        } catch (\Throwable $e) {
            // DB::transaction() auto-rollback; jangan rollback manual
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return null;
        }

        if ($isGantiCash) {
            return 'Titipan terakhir ditagih. Kedai tetap aktif, mulai sekarang mode cash (beli tunai).';
        }
        if ($isMulaiTitipan) {
            $jatahBaru = max(1, (int) $this->jatahMulai);
            return "Titipan dimulai: {$drop} mika dititip, jatah seterusnya {$jatahBaru} mika. Kunjungan berikutnya bisa Tagih + Titip Ulang.";
        }
        if ($applyJatahDrop) {
            return "Kunjungan disimpan. Jatah kios diperbarui ke {$drop} mika.";
        }
        return 'Kunjungan berhasil disimpan.';
    }

    // --- WALK-IN CASH FLOW ---
    public function openWalkInModal(): void
    {
        $this->walkInMika = 0;
        $this->resetErrorBag(['walkInMika', 'general']);
        $this->isWalkInModalOpen = true;
    }

    public function closeWalkInModal(): void
    {
        $this->isWalkInModalOpen = false;
        $this->walkInMika = 0;
    }

    /**
     * Catat penjualan cash walk-in (pembeli random, bukan kios terdaftar).
     * Disimpan ke kios sentinel tersembunyi milik owner trip ini, mengikuti
     * persis pola cash-only saveVisit(): Delivery cash_sale + Settlement lunas
     * + KioskVisit. Omset otomatis masuk komisi operator karena dihitung dari
     * settled_delivery_id per-trip (lihat Trip::omset_val).
     */
    public function saveWalkInCash(): void
    {
        $this->validate([
            'walkInMika' => 'required|integer|min:1',
        ], [
            'walkInMika.required' => 'Isi jumlah mika dulu.',
            'walkInMika.min' => 'Jumlah mika minimal 1.',
        ]);

        $this->resetErrorBag('general');

        $sentinel = Kiosk::walkInSentinelFor($this->trip->owner_id);

        // Guard idempotensi: submit ganda (koneksi lambat / retry) tidak boleh
        // membuat penjualan walk-in duplikat dalam jeda singkat.
        $alreadySaved = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $sentinel->id)
            ->where('visited_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($alreadySaved) {
            $this->loadKiosks();
            $this->closeWalkInModal();
            session()->flash('visit_saved', 'Penjualan cash sudah tercatat.');
            return;
        }

        try {
            $variant = $this->resolveActiveVariant();
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return;
        }

        $mika = (int) $this->walkInMika;

        try {
            DB::transaction(function () use ($mika, $variant, $sentinel) {
                $delivery = Delivery::create([
                    'kiosk_id' => $sentinel->id,
                    'trip_id' => $this->trip->id,
                    'product_variant_id' => $variant->id,
                    'procurement_batch_id' => null,
                    'source_type' => 'new_procurement',
                    'delivery_type' => 'cash_sale',
                    'qty_delivered' => $mika,
                    'unit_price' => $variant->sale_price_per_pack,
                    'cost_snapshot' => null,
                ]);

                $totalBiji = $mika * self::BIJI_PER_MIKA;
                $amountDue = $totalBiji * self::HARGA_PER_BIJI;

                Settlement::create([
                    'delivery_id' => $delivery->id,
                    'visit_date' => today(),
                    'qty_sold' => $totalBiji,
                    'qty_returned_fresh' => 0,
                    'qty_returned_expired' => 0,
                    'amount_due' => $amountDue,
                    'amount_paid' => $amountDue, // langsung lunas
                ]);

                $visit = KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $sentinel->id,
                    'visited_at' => now(),
                    'visit_action' => 'cash_sale',
                    'new_delivery_id' => $delivery->id,
                    'settled_delivery_id' => $delivery->id,
                    'extension_granted' => false,
                ]);

                $delivery->update(['kiosk_visit_id' => $visit->id]);
            });
        } catch (\Throwable $e) {
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return;
        }

        $this->loadKiosks();
        $this->closeWalkInModal();
        session()->flash('visit_saved', 'Penjualan cash berhasil dicatat.');
    }

    // --- END TRIP FLOW ---
    public function openEndTripModal()
    {
        $totalDrop = (int) Delivery::where('trip_id', $this->trip->id)->sum('qty_delivered');
        $qtyCarried = (int) ($this->trip->qty_carried_total ?? 0);
        $totalMikaCash = (int) Delivery::where('trip_id', $this->trip->id)
            ->where('delivery_type', 'cash_sale')
            ->sum('qty_delivered');
        $totalAmountCash = $totalMikaCash * self::BIJI_PER_MIKA * self::HARGA_PER_BIJI;

        $this->tripSummary = [
            'kios_visited' => KioskVisit::active()->where('trip_id', $this->trip->id)->count(),
            'kios_lama' => (int) $this->trip->kios_lama_count,
            'kios_baru' => (int) $this->trip->kios_baru_count,
            'qty_carried' => $qtyCarried,
            'total_mika_drop' => $totalDrop,
            'total_mika_sisa' => $qtyCarried - $totalDrop,
            
            'mika_terjual' => (float) $this->trip->mika_terjual,
            'mika_kios_baru' => (float) $this->trip->mika_kios_baru,
            'total_mika_cash' => $totalMikaCash,
            'total_amount_cash' => $totalAmountCash,
            'total_uang_diterima' => (int) $this->trip->omset_val,
            'hpp_estimasi' => (int) $this->trip->hpp_estimasi,
            'untung_kotor' => (int) $this->trip->untung_kotor,
            'komisi_reguler' => (int) $this->trip->komisi_reguler,
            'komisi_kios_baru' => (int) $this->trip->komisi_kios_baru,
            'komisi_rian' => (int) $this->trip->komisi_rian,
            'untung_bersih_owner' => (int) $this->trip->untung_bersih_owner,
        ];

        $this->endReason = '';
        $this->resetErrorBag('endReason');
        $this->isEndTripModalOpen = true;
    }

    public function closeEndTripModal()
    {
        $this->isEndTripModalOpen = false;
        $this->endReason = '';
    }

    public function confirmEndTrip()
    {
        // Validasi: alasan wajib + harus salah satu nilai valid
        if (!in_array($this->endReason, self::VALID_END_REASONS, true)) {
            $this->addError('endReason', 'Pilih alasan mengakhiri trip.');
            return;
        }

        // Trip harus masih aktif
        if (!$this->trip || $this->trip->ended_at !== null) {
            $this->addError('endReason', 'Trip sudah diakhiri.');
            return;
        }

        DB::transaction(function () {
            $this->trip->update([
                'ended_at' => now(),
                'ended_reason' => $this->endReason,
            ]);

            // Save commission record to the database using the new formula
            $omset = $this->trip->omset_val;
            $komisi = $this->trip->komisi_rian;

            $cashCollectedReported = $omset;
            $marginRateAssumed = 0;

            if ($omset > 0) {
                $marginRateAssumed = $komisi / ($omset * 0.2000);
            }

            // Fallback if omset is 0 or margin rate would overflow decimal(5,4)
            if ($omset == 0 || $marginRateAssumed > 9.0) {
                $cashCollectedReported = $komisi / 0.2000;
                $marginRateAssumed = 1.0000;
            }

            $owner = $this->trip->owner;
            $komisiPerMika = $owner ? $owner->getKomisiPerMikaValue() : 1000;

            \App\Models\Commission::create([
                'trip_id' => $this->trip->id,
                'operator_id' => $this->trip->operator_id,
                'cash_collected_reported' => $cashCollectedReported,
                'margin_rate_assumed' => $marginRateAssumed,
                'commission_rate' => 0.2000,
                'status' => 'paid',
                'paid_at' => now(),
                'notes' => sprintf('Komisi Rian (basis DROP): mika diletakkan (exclude BS) x Rp %d', $komisiPerMika),
            ]);
        });

        session()->flash('trip_ended', 'Trip berhasil diakhiri.');

        return redirect()->route('operator.dashboard');
    }

    public function render()
    {
        // Data daftar kios dihitung di sini (view-local), TIDAK disimpan sebagai
        // state → snapshot Livewire tetap kecil walau kios owner ratusan/ribuan.
        return view('livewire.operator.active-trip', $this->kioskViewData() + [
            // Daftar area cuma di-query kalau panelnya memang dibuka.
            'availableAreas' => $this->isAreaPickerOpen ? $this->availableAreas() : collect(),
        ]);
    }
}

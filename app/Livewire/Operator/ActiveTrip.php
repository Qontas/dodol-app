<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;

#[Layout('layouts.operator')]
class ActiveTrip extends Component
{
    // Konstanta domain (jangan hardcode di method)
    public const BIJI_PER_MIKA = 15;
    public const HARGA_PER_BIJI = 800;

    // Alasan valid untuk mengakhiri trip
    public const VALID_END_REASONS = ['stock_habis', 'target_done', 'sakit', 'urgent_personal', 'other'];

    // --- STATE DASAR ---
    public $trip;
    public $kiosks = [];
    public $starting_cluster_id;

    // --- STATE TRANSAKSI KUNJUNGAN ---
    public $isVisitModalOpen = false;
    public $selectedKiosk = null;
    public $pendingDelivery = null;

    // Input Form dari Rian
    public $returnFresh = 0;
    public $returnExpired = 0;
    public $dropBaru = 0;
    public $uangDiterima = 0;

    // Kalkulasi Sistem
    public $terjual = 0;
    public $tagihan = 0;

    // --- STATE EXTENSION (tunda settle) ---
    public bool $extensionGranted = false;
    public int $extensionCount = 0;

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
        $this->trip = Trip::where('operator_id', auth()->id())
            ->whereNull('ended_at')
            ->first();

        if (!$this->trip) {
            return redirect()->route('operator.dashboard');
        }

        $this->starting_cluster_id = $this->trip->starting_cluster_id;
        $this->loadKiosks();
    }

    public function loadKiosks()
    {
        if ($this->starting_cluster_id) {
            $this->kiosks = Kiosk::where('cluster_id', $this->starting_cluster_id)
                ->where('is_active', true)
                ->get();
        } else {
            $this->kiosks = Kiosk::where('is_active', true)->get();
        }
    }

    // --- METODE TRANSAKSI MODAL ---
    public function openVisitModal($kioskId)
    {
        $this->selectedKiosk = Kiosk::find($kioskId);

        $this->pendingDelivery = Delivery::where('kiosk_id', $kioskId)
            ->doesntHave('settlement')
            ->latest('id')
            ->first();

        // Hitung berapa kali titipan ini sudah diperpanjang
        $this->extensionGranted = false;
        if ($this->pendingDelivery) {
            $this->extensionCount = KioskVisit::where('settled_delivery_id', $this->pendingDelivery->id)
                ->where('extension_granted', true)
                ->count();
        } else {
            $this->extensionCount = 0;
        }

        // Reset form
        $this->returnFresh = 0;
        $this->returnExpired = 0;
        $this->dropBaru = 0;
        $this->terjual = 0;
        $this->tagihan = 0;
        $this->uangDiterima = 0;

        $this->resetErrorBag();
        $this->isVisitModalOpen = true;
    }

    public function closeVisitModal()
    {
        $this->isVisitModalOpen = false;
        $this->selectedKiosk = null;
        $this->pendingDelivery = null;
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
        $variant = ProductVariant::where('is_active', true)->first();

        if (!$variant) {
            throw new \RuntimeException('Tidak ada varian produk aktif.');
        }

        return $variant;
    }

    public function saveVisit()
    {
        $this->validate([
            'returnFresh' => 'nullable|integer|min:0',
            'returnExpired' => 'nullable|integer|min:0',
            'dropBaru' => 'nullable|integer|min:0',
            'uangDiterima' => 'nullable|integer|min:0',
        ]);

        $this->resetErrorBag('general');

        if (!$this->selectedKiosk) {
            $this->addError('general', 'Kios tidak valid. Tutup form dan coba lagi.');
            return;
        }

        $drop = (int) $this->dropBaru;
        $fresh = (int) $this->returnFresh;
        $expired = (int) $this->returnExpired;
        $hasPending = (bool) $this->pendingDelivery;
        $action = $this->resolveVisitAction();
        $isSettleAction = in_array($action, ['drop_and_settle', 'settle_only'], true);
        $isDrop = in_array($action, ['drop_and_settle', 'drop_only'], true);

        // Extension hanya berlaku untuk aksi yang seharusnya settle + ada titipan lama.
        // Kalau granted: settle DITUNDA (tidak buat row settlements), drop tetap jalan.
        $extension = $this->extensionGranted && $hasPending && $isSettleAction;
        $createSettlement = $isSettleAction && !$extension;

        // --- Hitung ulang terjual & tagihan dari server (jangan percaya client),
        //     TANPA menimpa uangDiterima yang diinput operator ---
        if ($createSettlement) {
            $totalBiji = (int) $this->pendingDelivery->qty_delivered * self::BIJI_PER_MIKA;

            if (($fresh + $expired) > $totalBiji) {
                $this->addError('general', 'Total retur melebihi jumlah titipan sebelumnya.');
                return;
            }

            $this->terjual = max(0, $totalBiji - $fresh - $expired);
            $this->tagihan = $this->terjual * self::HARGA_PER_BIJI;
        }

        if ((int) $this->uangDiterima < 0) {
            $this->addError('uangDiterima', 'Uang diterima tidak boleh negatif.');
            return;
        }

        // --- Resolve varian aktif SEBELUM transaksi (tidak ada block stok batch) ---
        try {
            $variant = $isDrop ? $this->resolveActiveVariant() : null;
        } catch (\RuntimeException $e) {
            $this->addError('general', $e->getMessage());
            return;
        }

        try {
            DB::transaction(function () use ($action, $drop, $fresh, $expired, $isSettleAction, $createSettlement, $extension, $isDrop, $variant) {
                $newDeliveryId = null;
                $settledDeliveryId = null;

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
                        // status & paid_at di-set otomatis oleh SettlementObserver
                    ]);
                }

                // 2. Drop titipan baru (new_procurement, tanpa link batch — operasional bebas)
                if ($isDrop) {
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
                }

                // 3. Catat kunjungan
                KioskVisit::create([
                    'trip_id' => $this->trip->id,
                    'kiosk_id' => $this->selectedKiosk->id,
                    'visited_at' => now(),
                    'visit_action' => $action,
                    'new_delivery_id' => $newDeliveryId,
                    'settled_delivery_id' => $settledDeliveryId,
                    'extension_granted' => $extension,
                ]);
            });
        } catch (\Throwable $e) {
            // DB::transaction() auto-rollback; jangan rollback manual
            $this->addError('general', 'Gagal menyimpan. Coba lagi.');
            return;
        }

        // 4. Refresh daftar + reset form + tutup modal
        $this->loadKiosks();
        $this->closeVisitModal();
        session()->flash('visit_saved', 'Kunjungan berhasil disimpan.');
    }

    // --- END TRIP FLOW ---
    public function openEndTripModal()
    {
        $totalDrop = (int) Delivery::where('trip_id', $this->trip->id)->sum('qty_delivered');
        $qtyCarried = (int) ($this->trip->qty_carried_total ?? 0);

        $this->tripSummary = [
            'kios_visited' => KioskVisit::where('trip_id', $this->trip->id)->count(),
            'total_mika_drop' => $totalDrop,
            'total_uang_diterima' => (int) Settlement::whereHas(
                'delivery',
                fn ($q) => $q->where('trip_id', $this->trip->id)
            )->sum('amount_paid'),
            'qty_carried' => $qtyCarried,
            'total_mika_sisa' => $qtyCarried - $totalDrop,
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
        });

        session()->flash('trip_ended', 'Trip berhasil diakhiri.');

        return redirect()->route('operator.dashboard');
    }

    public function render()
    {
        return view('livewire.operator.active-trip');
    }
}

<?php

namespace App\Filament\Resources\KioskResource\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\KioskResource;
use App\Models\Kiosk;
use App\Support\OpeningBalance;
use Filament\Resources\Pages\CreateRecord;

class CreateKiosk extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = KioskResource::class;

    // Create + titipan adalah satu transaksi: jangan simpan kedai seolah sudah
    // dititip jika pencatatan owner_placed gagal.
    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($this->data['jenis_kedai'] ?? 'konsinyasi') === 'konsinyasi'
            && ! in_array($this->data['first_placement'] ?? null, ['operator', 'owner_placed'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'data.first_placement' => 'Pilih siapa yang menaruh titipan pertama.',
            ]);
        }

        return $data;
    }

    /** Titipan operator menunggu trip; titipan owner dicatat tanpa komisi/stok motor. */
    protected function afterCreate(): void
    {
        $data = $this->data;

        $kiosk = $this->record;
        if (! $kiosk instanceof Kiosk) {
            return;
        }

        // URUTAN RUTE: kios baru SELALU jadi urutan TERAKHIR di area-nya (bukan NULL yang
        // jatuh ke bawah tanpa nomor & mengacaukan urutan rute). Sejak field "Urutan Rute"
        // dibuang dari form (15 Juli 2026), tak ada lagi jalur "owner isi angka eksplisit
        // saat create" — owner mengatur urutan BELAKANGAN di halaman daftar (drag per-Area
        // atau ketik angka di kolom "Urutan" → Kiosk::reorderWithinCluster). Sama persis
        // dengan perilaku operator create-kiosk.
        Kiosk::appendToClusterOrder($kiosk);

        $jenis = $data['jenis_kedai'] ?? 'konsinyasi';

        if ($jenis === 'cash_only') {
            // Cash-only: tak ada titipan/jatah.
            $kiosk->update(['is_cash_only' => true, 'default_qty_mika' => null]);

            return;
        }

        if ($jenis === 'booking') {
            // Booking: identitas saja. TIDAK cash-only, TIDAK ada jatah/titipan. Jenis final
            // (cash/konsinyasi) ditentukan operator di lapangan. Tak ada OpeningBalance.
            $kiosk->update(['is_cash_only' => false, 'default_qty_mika' => null]);

            return;
        }

        if (($data['first_placement'] ?? null) === 'operator') {
            // Jatah tetap terisi, tetapi belum ada delivery. Operator Mulai Titipan
            // pada trip nyata supaya fee, komisi, dan stok motor tercatat di sana.
            return;
        }

        // Owner menaruh sendiri: jumlah awal = jatah, fee dibekukan per delivery.
        $jatah = (int) ($kiosk->default_qty_mika ?? 0);
        if ($jatah < 1) {
            return;
        }

        $delivery = OpeningBalance::create($kiosk, $jatah, 'owner_placed');

        if ($delivery === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'data.first_placement' => 'Titipan pertama belum dapat dicatat. Pastikan ada varian produk aktif milik usaha ini.',
            ]);
        }
    }
}

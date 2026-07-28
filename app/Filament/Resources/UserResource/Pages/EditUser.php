<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * ⚠️ Tombol Delete di halaman EDIT dulu POLOS (`DeleteAction::make()` tanpa apa pun).
     * Itu LUBANG BETULAN, bukan cuma kosmetik: guard delete UserResource hidup di
     * ->hidden()/->disabled()/->before() milik action TABEL, dan tak ada guard model
     * (User tak punya event `deleting`). Jadi halaman Edit melewati semuanya —
     * super admin bisa MENGHAPUS DIRINYA SENDIRI dari sini = lockout permanen
     * (terbukti: probe "super masih ada? TIDAK — TERHAPUS"), dan owner berdata
     * meledak jadi QueryException FK 1451 mentah (500), bukan pesan ramah.
     *
     * Aturan di sini: alasan blokir APA PUN (super admin, akun sendiri, atau berdata)
     * → tombol HILANG, dan alasannya ditulis di subheading halaman (lihat
     * getSubheading()). ->before() tetap ada sebagai guard server-side real-time.
     *
     * KENAPA HILANG, bukan "disabled + tooltip" seperti di tabel: tombol Filament yang
     * disabled dirender dengan `pointer-events-none`, sedangkan `x-tooltip` menempel di
     * tombol itu sendiri — jadi tooltipnya TAK PERNAH BISA MUNCUL (diverifikasi di
     * Chrome: computedPointerEvents = "none"). Menyalin pola itu ke sini berarti
     * menaruh tombol merah mati tanpa penjelasan yang bisa diraih — persis kebingungan
     * yang sedang diperbaiki. Halaman Edit punya ruang untuk kalimat penuh, jadi
     * alasannya ditaruh sebagai teks yang selalu terlihat.
     *
     * ⚠️ Tooltip disabled di TABEL (UserResource::table) juga mati karena sebab yang
     * sama — itu isu lama yang terpisah, belum disentuh di sini.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                // Halaman Edit tak punya withCount seperti tabel → pakai versi real-time
                // langsung (satu record saja, bukan 10 baris; tak ada isu N+1 di sini).
                ->hidden(fn (): bool => UserResource::deleteBlockReason($this->record) !== null)
                ->before(function (Actions\DeleteAction $action) {
                    if ($reason = UserResource::deleteBlockReason($this->record)) {
                        Notification::make()
                            ->danger()
                            ->title('Tidak bisa dihapus')
                            ->body($reason)
                            ->persistent()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Alasan tombol Delete tak ada, ditulis terang-terangan di bawah judul halaman.
     * Tanpa ini tombolnya cuma "hilang" dan owner menebak-nebak (atau, seperti kemarin,
     * mengira ada yang salah dengan aplikasinya).
     */
    public function getSubheading(): ?string
    {
        if ($reason = UserResource::deleteBlockReason($this->record)) {
            return "Tidak bisa dihapus — {$reason}";
        }

        return null;
    }

    /**
     * Defense-in-depth (parity dengan CreateUser): owner tidak boleh mengubah
     * operatornya menjadi role lain atau memindahkannya ke owner lain, walau
     * payload di-tamper. Super admin tidak dipaksa.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()?->isOwner()) {
            $data['role'] = 'operator';
            $data['owner_id'] = auth()->id();
        }

        // Guard lockout (server-side, bukan cuma UI disabled): user tak boleh
        // mengunci dirinya sendiri keluar lewat form Edit — paksa is_active tetap
        // aktif & role tak berubah walau payload di-tamper.
        if (isset($this->record) && (int) $this->record->id === (int) auth()->id()) {
            $data['is_active'] = true;
            $data['role'] = $this->record->role;
        }

        // Super Admin tunggal (server-side): tolak promosi user lain jadi super_admin
        // kalau sudah ada super lain. Kecualikan record ini sendiri (self di atas
        // sudah menjaga role super tetap super).
        if (($data['role'] ?? null) === 'super_admin'
            && isset($this->record)
            && User::anotherSuperAdminExists($this->record->getKey())) {
            Notification::make()
                ->danger()
                ->title('Ditolak')
                ->body('Sistem hanya boleh punya satu Super Admin.')
                ->persistent()
                ->send();

            $this->halt();
        }

        return $data;
    }
}

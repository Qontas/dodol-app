<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'owner_id',
        'commission_rate',
        'hpp_per_mika',
        'is_active',
        'harga_mika',
        'komisi_per_mika',
        'komisi_kios_baru_per_mika',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
            'commission_rate' => 'decimal:4',
            'hpp_per_mika' => 'decimal:2',
            'is_active' => 'boolean',
            'harga_mika' => 'decimal:2',
            'komisi_per_mika' => 'decimal:2',
            'komisi_kios_baru_per_mika' => 'decimal:2',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Sistem hanya boleh punya SATU super_admin (keputusan owner: tak ada alasan
     * bisnis untuk super admin kedua; tiap tambahan = permukaan serangan lintas-tenant).
     * True kalau sudah ada super_admin lain (opsional kecualikan satu id, mis. saat
     * Edit si super itu sendiri). Dipakai form role option + guard CreateUser/EditUser.
     */
    public static function anotherSuperAdminExists(?int $exceptId = null): bool
    {
        return static::query()
            ->where('role', 'super_admin')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    /**
     * Akun sistem (dibuat command kios:saldo-awal, password acak, nonaktif) —
     * bukan akun manusia. Tak boleh diedit/diaktifkan/dihapus dari UI & disembunyikan
     * dari daftar user. Penanda: pola email operator.migrasi.owner{N}@...
     */
    public function isSystemAccount(): bool
    {
        return str_starts_with((string) $this->email, 'operator.migrasi.');
    }

    /**
     * Sembunyikan akun sistem dari listing/panel. Dipakai getEloquentQuery
     * UserResource (super admin) & OperatorResource (owner).
     */
    public function scopeExcludeSystem(Builder $query): Builder
    {
        return $query->where('email', 'not like', 'operator.migrasi.%');
    }

    /**
     * Alasan ramah kenapa user ini TIDAK BOLEH dihapus (null = boleh hapus).
     * Dipakai guard delete di UserResource (super admin) & OperatorResource (owner)
     * agar muncul pesan jelas — BUKAN QueryException FK 500 mentah, dan bukan cascade
     * parsial yang menghapus data owner/operator diam-diam.
     *
     * - Super admin: tak boleh dihapus siapa pun (cegah lockout / hapus admin lain).
     * - Owner berdata (punya kios/trip/operator): nonaktifkan saja, jangan hapus.
     * - Operator berdata (punya trip/komisi): nonaktifkan saja, jangan hapus.
     *
     * ⚠️ SELALU MENGHITUNG REAL-TIME — sengaja. Ini yang dipakai guard server-side
     * (->before()) saat tombol hapus BENAR-BENAR ditekan: keputusan hapus tak boleh
     * bersandar pada angka yang dimuat saat render tabel (bisa basi berdetik/bermenit).
     * Untuk RENDER tabel pakai deletionBlockReasonForDisplay() yang 0 query.
     */
    public function deletionBlockReason(): ?string
    {
        if ($alasan = $this->deletionBlockReasonByRole()) {
            return $alasan;
        }

        if ($this->isOwner()) {
            return $this->ownerBlockMessage(
                \App\Models\Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $this->id))->count(),
                \App\Models\Trip::where('owner_id', $this->id)->count(),
                static::where('owner_id', $this->id)->count(),
            );
        }

        return $this->operatorBlockMessage(
            $this->operatedTrips()->count(),
            $this->commissions()->count(),
        );
    }

    /**
     * Versi RENDER-ONLY: baca count yang sudah di-eager-load (withCount di
     * getEloquentQuery resource) → 0 query tambahan per baris.
     *
     * KENAPA ADA: ->disabled() dan ->tooltip() Filament masing-masing memanggil guard
     * ini sekali per baris, jadi versi real-time menembak 2-3 COUNT × 2 = 4-6 query
     * PER BARIS (audit: ~53 query untuk 10 baris). Terbatas pagination, tapi mubazir.
     *
     * KENAPA TERPISAH, bukan bikin deletionBlockReason() ikut baca count: instance
     * record yang sama dipakai ulang oleh guard ->before(), jadi kalau guard utama
     * membaca count hasil render, keputusan HAPUS jadi bersandar angka basi. Pemisahan
     * ini menjaga: render = murah, keputusan = real-time.
     *
     * Kalau count belum dimuat (dipanggil di luar tabel), otomatis jatuh ke versi
     * real-time — jadi tak pernah salah, cuma tak sehemat itu.
     */
    public function deletionBlockReasonForDisplay(): ?string
    {
        if ($alasan = $this->deletionBlockReasonByRole()) {
            return $alasan;
        }

        if ($this->isOwner()) {
            if (! $this->countsSudahDimuat(['owned_kiosks_count', 'owned_trips_count', 'operators_count'])) {
                return $this->deletionBlockReason();
            }

            return $this->ownerBlockMessage(
                (int) $this->owned_kiosks_count,
                (int) $this->owned_trips_count,
                (int) $this->operators_count,
            );
        }

        if (! $this->countsSudahDimuat(['operated_trips_count', 'commissions_count'])) {
            return $this->deletionBlockReason();
        }

        return $this->operatorBlockMessage(
            (int) $this->operated_trips_count,
            (int) $this->commissions_count,
        );
    }

    /** Blokir yang tak butuh hitungan sama sekali (murni dari role/pola email). */
    private function deletionBlockReasonByRole(): ?string
    {
        if ($this->isSuperAdmin()) {
            return 'Akun Super Admin tidak bisa dihapus.';
        }

        if ($this->isSystemAccount()) {
            return 'Akun sistem tidak bisa dihapus.';
        }

        return null;
    }

    /** Satu sumber teks — supaya jalur real-time & jalur render tak bisa menyimpang. */
    private function ownerBlockMessage(int $kios, int $trip, int $operator): ?string
    {
        if ($kios || $trip || $operator) {
            return "Owner ini punya {$kios} kios, {$trip} trip, {$operator} operator. Nonaktifkan saja, jangan hapus.";
        }

        return null;
    }

    private function operatorBlockMessage(int $trip, int $komisi): ?string
    {
        if ($trip || $komisi) {
            return "Operator ini punya {$trip} trip, {$komisi} komisi. Nonaktifkan saja, jangan hapus.";
        }

        return null;
    }

    /** @param  array<string>  $atribut */
    private function countsSudahDimuat(array $atribut): bool
    {
        foreach ($atribut as $a) {
            if (! array_key_exists($a, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * URL dashboard sesuai role — satu sumber kebenaran dipakai oleh route
     * "/dashboard" dan LoginResponse (Filament) agar tidak divergent.
     * Super admin diarahkan ke Filament admin panel.
     */
    public function homePath(): string
    {
        if ($this->isSuperAdmin()) {
            return '/admin';
        }

        return route("{$this->role}.dashboard");
    }

    /**
     * HPP per mika owner ini, fallback ke default Rp 9.500.
     */
    public function getHppPerMikaValue(): float
    {
        return (float) ($this->hpp_per_mika ?? 9500);
    }

    public function getHargaMikaValue(): float
    {
        return (float) ($this->harga_mika ?? 200);
    }

    public function getKomisiPerMikaValue(): float
    {
        // Tarif komisi Rian per mika DROP (Opsi Y). Default Rp 1.000.
        return (float) ($this->komisi_per_mika ?? 1000);
    }

    public function getKomisiKiosBaruPerMikaValue(): float
    {
        return (float) ($this->komisi_kios_baru_per_mika ?? 1000);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['owner', 'super_admin'], true) && $this->is_active;
    }

    /**
     * Owner dari user ini (hanya relevan untuk operator).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Operator yang terikat ke owner ini.
     */
    public function operators(): HasMany
    {
        return $this->hasMany(User::class, 'owner_id');
    }

    public function operatedTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'operator_id');
    }

    /**
     * Trip MILIK owner ini (owner_id) — beda dari operatedTrips() yang trip yang
     * DIJALANKAN user ini sebagai operator. Dipakai withCount guard delete.
     */
    public function ownedTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'owner_id');
    }

    /**
     * Kios milik owner ini lewat rantai cluster (kiosks tak punya kolom owner_id) —
     * rantai yang sama dipakai OwnerScope. Dipakai withCount guard delete supaya
     * hitungan kios ikut query utama tabel, bukan query terpisah per baris.
     */
    public function ownedKiosks(): HasManyThrough
    {
        return $this->hasManyThrough(
            Kiosk::class,
            Cluster::class,
            'owner_id',   // FK di clusters → users
            'cluster_id', // FK di kiosks → clusters
            'id',
            'id',
        );
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'operator_id');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class, 'operator_id');
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     */
    public function deletionBlockReason(): ?string
    {
        if ($this->isSuperAdmin()) {
            return 'Akun Super Admin tidak bisa dihapus.';
        }

        if ($this->isSystemAccount()) {
            return 'Akun sistem tidak bisa dihapus.';
        }

        if ($this->isOwner()) {
            $kios = \App\Models\Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $this->id))->count();
            $trip = \App\Models\Trip::where('owner_id', $this->id)->count();
            $operator = static::where('owner_id', $this->id)->count();

            if ($kios || $trip || $operator) {
                return "Owner ini punya {$kios} kios, {$trip} trip, {$operator} operator. Nonaktifkan saja, jangan hapus.";
            }

            return null;
        }

        // operator
        $trip = $this->operatedTrips()->count();
        $komisi = $this->commissions()->count();

        if ($trip || $komisi) {
            return "Operator ini punya {$trip} trip, {$komisi} komisi. Nonaktifkan saja, jangan hapus.";
        }

        return null;
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

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'operator_id');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class, 'operator_id');
    }
}

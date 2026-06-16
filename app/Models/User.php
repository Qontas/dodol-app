<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
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

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
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
        return (float) ($this->komisi_per_mika ?? 500);
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

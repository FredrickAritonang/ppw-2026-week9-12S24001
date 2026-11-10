<?php

namespace App\Models;

use App\Support\Peran;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'role', 'password',
        'nomor_induk', 'nuptk', 'nidn', 'prodi', 'jabatan', 'golongan', 'status_kerja', 'telepon',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function peminjamans(): HasMany
    {
        return $this->hasMany(Peminjaman::class);
    }

    public function getPeranDataAttribute(): array
    {
        return Peran::DATA[$this->role];
    }

    public function pinjamanAktif(): int
    {
        return Peminjaman::aktif()->where('user_id', $this->id)->count();
    }

    public function dendaBelumLunas(): int
    {
        return (int) Peminjaman::berdenda()
            ->where('user_id', $this->id)
            ->where('denda_lunas', false)
            ->get()
            ->sum('total_denda');
    }
}
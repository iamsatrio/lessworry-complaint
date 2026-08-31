<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'outlet_id', 'division', 'is_active', 'must_change_password',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Default harus ada di model, bukan hanya di kolom database.
     * Tanpa ini, instance hasil User::create() punya is_active = null
     * dan pengguna baru langsung ditolak middleware 'active'.
     */
    protected $attributes = [
        'role'                 => 'kasir',
        'is_active'            => true,
        'must_change_password' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'is_active'            => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /* ---------- Peran (API-13) ---------- */

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function isCustomerCare(): bool
    {
        return $this->role === 'customer_care';
    }

    public function isKasir(): bool
    {
        return $this->role === 'kasir';
    }

    public function isDivisi(): bool
    {
        return $this->role === 'divisi';
    }

    /** Boleh melihat seluruh outlet. */
    public function seesAllOutlets(): bool
    {
        return in_array($this->role, ['supervisor', 'customer_care'], true);
    }

    public function canCreateComplaint(): bool
    {
        return in_array($this->role, ['kasir', 'customer_care', 'supervisor'], true);
    }

    /**
     * Siapa yang boleh menutup complaint. (API-25, keputusan API-18 nomor 1)
     *
     * Customer Care dan supervisor: selalu. Kasir: hanya complaint berbobot
     * Ringan — itu 52,3% kasus pada 2026, dan menahannya di antrean Customer
     * Care hanya memindahkan pekerjaan yang sudah selesai di outlet.
     *
     * Batas kompensasi TIDAK diperiksa di sini. Itu batas yang berdiri
     * sendiri dan ditegakkan terpisah di ComplaintController: complaint
     * Ringan berkompensasi Rp 200.000 tetap tidak boleh ditutup kasir.
     */
    public function canResolve(?Complaint $complaint = null): bool
    {
        if (in_array($this->role, ['customer_care', 'supervisor'], true)) {
            return true;
        }

        if (! $this->isKasir()) {
            return false;
        }

        return $complaint !== null && $complaint->bobot === 'ringan';
    }

    /**
     * Data siapa-mengerjakan-apa menyangkut penilaian kerja orang. Hanya
     * Customer Care dan supervisor yang boleh melihatnya — kasir tidak
     * perlu bisa menelusuri catatan rekannya.
     */
    public function canSeeStaffAttribution(): bool
    {
        return in_array($this->role, ['customer_care', 'supervisor'], true);
    }

    /** Menetapkan penanggung jawab adalah penilaian, bukan pencatatan. */
    public function canAssignResponsibility(): bool
    {
        return in_array($this->role, ['customer_care', 'supervisor'], true);
    }

    public function canManageUsers(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * Penanda draft form intake di penyimpanan perangkat.
     *
     * Perangkat outlet dipakai bergantian, jadi draft harus terikat pengguna:
     * tanpa itu keluhan pelanggan yang diketik petugas sebelumnya muncul lagi
     * di form petugas berikutnya. Diturunkan lewat hash supaya id pengguna
     * tidak ikut tertulis ke perangkat bersama.
     */
    public function draftKey(): string
    {
        return substr(hash_hmac('sha256', 'draft-intake:'.$this->id, (string) config('app.key')), 0, 16);
    }

    public function compensationLimit(): int
    {
        return (int) config('complaint.compensation_limit.'.$this->role, 0);
    }

    public function canView(Complaint $complaint): bool
    {
        return match ($this->role) {
            'kasir'  => $complaint->outlet_id === $this->outlet_id,
            'divisi' => $complaint->forwarded_division === $this->division,
            default  => true,
        };
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'kasir'         => 'Kasir',
            'customer_care' => 'Customer Care',
            'divisi'        => 'Divisi '.config('complaint.divisions.'.$this->division, $this->division),
            'supervisor'    => 'Supervisor',
            default         => $this->role,
        };
    }
}

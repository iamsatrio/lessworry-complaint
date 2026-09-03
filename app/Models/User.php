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

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

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
        return in_array($this->role, ['admin', 'supervisor', 'customer_care'], true);
    }

    public function canCreateComplaint(): bool
    {
        return in_array($this->role, ['kasir', 'customer_care', 'supervisor', 'admin'], true);
    }

    /** Hanya CC, supervisor, dan admin yang boleh menutup complaint. */
    public function canResolve(): bool
    {
        return in_array($this->role, ['customer_care', 'supervisor', 'admin'], true);
    }

    /**
     * Data siapa-mengerjakan-apa menyangkut penilaian kerja orang. Hanya
     * Customer Care dan supervisor yang boleh melihatnya — kasir tidak
     * perlu bisa menelusuri catatan rekannya.
     */
    public function canSeeStaffAttribution(): bool
    {
        return in_array($this->role, ['customer_care', 'supervisor', 'admin'], true);
    }

    /** Menetapkan penanggung jawab adalah penilaian, bukan pencatatan. */
    public function canAssignResponsibility(): bool
    {
        return in_array($this->role, ['customer_care', 'supervisor', 'admin'], true);
    }

    /**
     * Mengelola pengguna adalah wewenang Admin, bukan Supervisor.
     *
     * Supervisor memimpin pekerjaan di lapangan — melihat seluruh outlet,
     * menutup complaint apa pun bobotnya, menyetujui kompensasi tanpa batas.
     * Yang TIDAK dipegangnya: membuat akun, mengubah peran orang lain, dan
     * menonaktifkan orang. Itu memisahkan wewenang operasional dari wewenang
     * atas siapa yang boleh masuk ke sistem.
     */
    public function canManageUsers(): bool
    {
        return $this->role === 'admin';
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
            // Divisi boleh belum diisi — jangan balas null, halaman Pengguna
            // memanggil ini untuk setiap baris.
            'divisi'        => (string) (config('complaint.divisions.'.$this->division, $this->division) ?: 'Produksi / Kurir'),
            'supervisor'    => 'Supervisor',
            'admin'         => 'Admin',
            default         => $this->role,
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu orang yang ditetapkan terlibat dalam satu complaint. (API-19)
 *
 * Beberapa baris untuk satu complaint itu normal: kasir yang menerima,
 * petugas yang mencuci, kurir yang mengantar. Yang disimpan bukan jabatan
 * sehari-hari orangnya, melainkan perannya DALAM KEJADIAN INI — plus alasan
 * yang wajib, karena penetapan tanpa alasan tidak bisa ditinjau ulang.
 */
class ComplaintResponsible extends Model
{
    protected $fillable = [
        'complaint_id', 'nevira_user_id', 'staff_name', 'staff_nip',
        'role', 'stage', 'reason', 'set_by', 'set_at',
    ];

    protected $attributes = [
        'role' => 'lainnya',
    ];

    protected function casts(): array
    {
        return ['set_at' => 'datetime'];
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function setter()
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function roleLabel(): string
    {
        return config('complaint.responsible_roles.'.$this->role, $this->role);
    }

    /**
     * Penanda orang yang sama lintas sumber: id NEVIRA kalau ada, lalu NIP,
     * baru nama. Dipakai supaya satu orang tidak tercatat dua kali di
     * complaint yang sama, dan supaya rekap tidak memecah satu orang jadi dua.
     */
    public function identity(): string
    {
        return static::identityFor($this->nevira_user_id, $this->staff_nip, $this->staff_name);
    }

    public static function identityFor(?string $neviraUserId, ?string $nip, ?string $name): string
    {
        if (filled($neviraUserId)) {
            return 'staff:'.$neviraUserId;
        }

        if (filled($nip)) {
            return 'nip:'.$nip;
        }

        return 'nama:'.mb_strtolower(trim((string) $name));
    }
}

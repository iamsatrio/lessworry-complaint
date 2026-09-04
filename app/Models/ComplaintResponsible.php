<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu orang yang ditetapkan terlibat dalam satu complaint. (API-19)
 *
 * Beberapa baris untuk satu complaint itu normal: kasir yang menerima,
 * petugas yang mencuci, kurir yang mengantar. Yang disimpan bukan jabatan
 * sehari-hari orangnya, melainkan perannya DALAM KEJADIAN INI — plus alasan
 * yang wajib, karena penetapan tanpa alasan tidak bisa ditinjau ulang.
 */
/**
 * @property int $id
 * @property int $complaint_id
 * @property string|null $nevira_user_id
 * @property string $staff_name
 * @property string|null $staff_nip
 * @property string $role
 * @property string|null $stage
 * @property string $reason
 * @property int|null $set_by
 * @property Carbon|null $set_at
 * @property-read User|null $setter
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

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
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

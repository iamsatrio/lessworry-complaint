<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu baris jejak audit akun. Ditulis hanya lewat JejakPengguna.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $reason
 * @property string|null $detail
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read User|null $actor
 */
class UserAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_id', 'action', 'reason', 'detail'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'email_diverifikasi_manual' => 'Ditandai terverifikasi oleh admin',
            'email_diverifikasi_konsol' => 'Ditandai terverifikasi lewat perintah shell',
            'email_diubah' => 'Alamat email diubah',
            'peran_disetel_ulang_seeder' => 'Peran dikembalikan oleh seeder',
            default => $this->action,
        };
    }

    /** Siapa pelakunya — perintah shell tidak punya akun, jadi actor_id kosong. */
    public function actorLabel(): string
    {
        if ($this->actor_id !== null) {
            return $this->actor->name;
        }

        return $this->action === 'peran_disetel_ulang_seeder'
            ? 'Seeder (php artisan db:seed)'
            : 'Perintah shell (lessworry:pulihkan-admin)';
    }
}

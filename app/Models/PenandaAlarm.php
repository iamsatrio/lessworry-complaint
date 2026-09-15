<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Penandaan "sudah saya tangani" pada satu alarm, untuk satu hari. (API-72)
 *
 * @property int $id
 * @property string $alarm
 * @property Carbon $untuk_tanggal
 * @property int $user_id
 * @property Carbon $ditandai_pada
 * @property-read User|null $user
 */
class PenandaAlarm extends Model
{
    protected $table = 'penanda_alarm';

    /**
     * created_at/updated_at tidak dipakai: `ditandai_pada` sudah menjawab
     * kapan, dan penanda tidak pernah diubah setelah tercatat — kalau
     * keadaannya masih ada besok, yang muncul baris baru untuk tanggal baru.
     */
    public $timestamps = false;

    protected $fillable = ['alarm', 'untuk_tanggal', 'user_id', 'ditandai_pada'];

    protected function casts(): array
    {
        return [
            'untuk_tanggal' => 'date',
            'ditandai_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

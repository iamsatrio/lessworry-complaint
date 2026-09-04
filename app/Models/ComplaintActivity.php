<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $complaint_id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class ComplaintActivity extends Model
{
    protected $fillable = ['complaint_id', 'user_id', 'type', 'from_status', 'to_status', 'note'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Foto bukti yang menempel pada catatan ini. (API-20) */
    /** @return HasMany<ComplaintAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class, 'complaint_activity_id');
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}

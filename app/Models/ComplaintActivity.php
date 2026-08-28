<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintActivity extends Model
{
    protected $fillable = ['complaint_id', 'user_id', 'type', 'from_status', 'to_status', 'note'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Foto bukti yang menempel pada catatan ini. (API-20) */
    public function attachments()
    {
        return $this->hasMany(ComplaintAttachment::class, 'complaint_activity_id');
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }
}

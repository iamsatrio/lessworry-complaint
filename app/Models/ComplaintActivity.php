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

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }
}

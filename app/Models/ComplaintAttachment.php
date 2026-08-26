<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintAttachment extends Model
{
    protected $fillable = ['complaint_id', 'path', 'original_name'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $nevira_outlet_id
 * @property bool $is_active
 */
class Outlet extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'nevira_outlet_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Complaint, $this> */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}

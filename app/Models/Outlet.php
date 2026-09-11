<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Outlet yang boleh dipilih pengguna ini sebagai saringan. (API-62 nomor 3)
     *
     * Aturannya sama persis dengan User::canViewOutlet, ditulis sebagai kueri.
     * Sengaja mengikuti bentuk Complaint::scopeVisibleTo, termasuk penjagaan
     * null-nya: kasir yang outlet-nya belum diisi tidak melihat apa pun, bukan
     * melihat semua outlet yang id-nya kebetulan null.
     *
     * @param  Builder<Outlet>  $query
     * @return Builder<Outlet>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'kasir' => $user->outlet_id === null
                ? $query->whereRaw('1 = 0')
                : $query->whereKey($user->outlet_id),
            default => $query,
        };
    }
}

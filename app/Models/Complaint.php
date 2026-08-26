<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nevira_snapshot'  => 'array',
            'nevira_synced_at' => 'datetime',
            'due_response_at'  => 'datetime',
            'due_resolution_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at'      => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities()
    {
        return $this->hasMany(ComplaintActivity::class)->latest();
    }

    public function attachments()
    {
        return $this->hasMany(ComplaintAttachment::class);
    }

    /* ---------- Nomor tiket ---------- */

    public static function nextTicketNumber(): string
    {
        $prefix = 'LW-'.now()->format('Ymd');
        $todayCount = static::where('ticket_number', 'like', $prefix.'%')->count();

        return $prefix.'-'.str_pad((string) ($todayCount + 1), 3, '0', STR_PAD_LEFT);
    }

    /* ---------- SLA ---------- */

    public function applySla(): void
    {
        $sla = config('complaint.sla.'.$this->priority) ?? config('complaint.sla.medium');
        $base = $this->created_at ?? now();

        $this->due_response_at = $base->copy()->addMinutes($sla['response']);
        $this->due_resolution_at = $base->copy()->addMinutes($sla['resolution']);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, config('complaint.open_statuses'), true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_resolution_at !== null
            && $this->due_resolution_at->isPast();
    }

    public function isResponseOverdue(): bool
    {
        return $this->isOpen()
            && $this->first_response_at === null
            && $this->due_response_at !== null
            && $this->due_response_at->isPast();
    }

    /** Lama penyelesaian dalam menit; null kalau belum selesai. */
    public function resolutionMinutes(): ?int
    {
        if ($this->resolved_at === null) {
            return null;
        }

        return $this->created_at->diffInMinutes($this->resolved_at);
    }

    /* ---------- Scope ---------- */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', config('complaint.open_statuses'));
    }

    /**
     * Batasi menurut peran. Kasir hanya melihat outletnya sendiri;
     * divisi hanya melihat yang diteruskan ke divisinya. (API-13)
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'kasir'  => $query->where('outlet_id', $user->outlet_id),
            'divisi' => $query->where('forwarded_division', $user->division),
            default  => $query,
        };
    }

    /* ---------- Label ---------- */

    public function categoryLabel(): string
    {
        return config('complaint.categories.'.$this->category.'.label', $this->category);
    }

    public function statusLabel(): string
    {
        return config('complaint.statuses.'.$this->status, $this->status);
    }

    public function channelLabel(): string
    {
        return config('complaint.channels.'.$this->channel, $this->channel);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    /**
     * Ditulis eksplisit, bukan $guarded. Kolom yang menyangkut hasil
     * penelusuran (snapshot NEVIRA, penanggung jawab, id internal, stempel
     * waktu SLA) hanya boleh diisi lewat jalur yang memeriksa wewenang,
     * tidak lewat request pengguna.
     */
    protected $fillable = [
        'channel', 'reporter_name', 'reporter_phone',
        'nevira_transaction_number', 'nota_exemption',
        'outlet_id', 'category', 'sub_category', 'priority',
        'description', 'assigned_to', 'forwarded_division',
    ];

    /**
     * Default harus ada di model, bukan hanya di kolom database: instance
     * yang baru disimpan masih memegang null sampai di-refresh, dan form
     * halaman complaint membaca nilai ini langsung. (API-8 T6)
     */
    protected $attributes = [
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'nevira_snapshot'  => 'array',
            'nevira_synced_at' => 'datetime',
            'due_response_at'  => 'datetime',
            'due_resolution_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at'      => 'datetime',
            'responsibility_set_at' => 'datetime',
            'lock_version'     => 'integer',
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

    public function responsibilitySetter()
    {
        return $this->belongsTo(User::class, 'responsibility_set_by');
    }

    /**
     * Orang-orang yang menyentuh order ini menurut NEVIRA — kasir penerima
     * dan setiap tahap produksi. Fakta, bukan tuduhan.
     *
     * @return array<int,array{stage:string,name:?string,nip:?string,status:?string,duration:?int}>
     */
    public function orderHandlers(): array
    {
        $snapshot = $this->nevira_snapshot ?? [];
        $handlers = [];

        if (! empty($snapshot['cashier_name'])) {
            $handlers[] = [
                'stage'    => 'Kasir penerima order',
                'name'     => $snapshot['cashier_name'],
                'nip'      => $snapshot['cashier_nip'] ?? null,
                'staff_id' => $snapshot['cashier_id'] ?? null,
                'status'   => null,
                'duration' => null,
            ];
        }

        foreach ($snapshot['processes'] ?? [] as $process) {
            if (empty($process['staff_name'])) {
                continue;
            }

            $handlers[] = [
                'stage'    => $process['stage'] ?? 'Tahap produksi',
                'name'     => $process['staff_name'],
                'nip'      => $process['staff_nip'] ?? null,
                'staff_id' => $process['staff_id'] ?? null,
                'status'   => $process['status'] ?? null,
                'duration' => $process['duration'] ?? null,
            ];
        }

        return $handlers;
    }

    /** Perjalanan kurir dari snapshot NEVIRA. */
    public function deliveries(): array
    {
        return $this->nevira_snapshot['deliveries'] ?? [];
    }

    /** Umur transaksi NEVIRA dalam hari; null kalau tanggalnya tidak diketahui. */
    public function transactionAgeDays(): ?int
    {
        $created = $this->nevira_snapshot['created_at'] ?? null;

        if (! $created) {
            return null;
        }

        try {
            return (int) \Illuminate\Support\Carbon::parse($created)->diffInDays(now());
        } catch (\Throwable) {
            return null;
        }
    }

    public function transactionIsOld(): bool
    {
        $age = $this->transactionAgeDays();

        return $age !== null && $age > (int) config('complaint.nota_max_age_days');
    }

    public function notaExemptionLabel(): ?string
    {
        return $this->nota_exemption
            ? config('complaint.nota_exemptions.'.$this->nota_exemption, $this->nota_exemption)
            : null;
    }

    /** Yang ditampilkan ke petugas adalah nomor nota, bukan id internal NEVIRA. */
    public function orderLabel(): ?string
    {
        return $this->nevira_transaction_number;
    }

    public function isLinkedToOrder(): bool
    {
        return filled($this->nevira_transaction_number);
    }

    public function hasResponsibility(): bool
    {
        return filled($this->responsible_staff_name);
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

    /**
     * Nomor tiket berikutnya untuk hari ini.
     *
     * Dihitung dari nomor TERBESAR yang sudah ada, bukan dari jumlah baris:
     * begitu ada satu lubang di urutan, jumlah baris menghasilkan nomor yang
     * sudah dipakai — dan kolomnya punya indeks unik, jadi penyimpanannya
     * gagal dan complaint hilang. (API-8 T5)
     *
     * Ini tetap bisa bentrok kalau dua permintaan membacanya bersamaan;
     * yang menutup sisanya adalah percobaan ulang di
     * ComplaintController::simpanMeskiNomorBentrok().
     */
    public static function nextTicketNumber(): string
    {
        $prefix = 'LW-'.now()->format('Ymd');

        $terakhir = static::where('ticket_number', 'like', $prefix.'-%')
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $urut = $terakhir ? ((int) substr($terakhir, -3)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
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

    /**
     * Kondisi SLA untuk meteran di papan kerja.
     *
     * Mengembalikan sisa waktu sebagai porsi (0-1) plus label siap tampil,
     * supaya petugas melihat berapa banyak runway yang tersisa — bukan
     * sekadar sudah lewat atau belum.
     *
     * @return array{state:string,label:string,pct:int}
     */
    public function slaMeter(): array
    {
        if (! $this->isOpen()) {
            $minutes = $this->resolutionMinutes();

            return [
                'state' => 'done',
                'label' => $minutes === null
                    ? 'Ditutup'
                    : 'Selesai '.$this->humanMinutes($minutes),
                'pct'   => 100,
            ];
        }

        if ($this->due_resolution_at === null) {
            return ['state' => '', 'label' => 'Tanpa tenggat', 'pct' => 0];
        }

        $start = $this->created_at ?? now();
        $total = max(1, (int) round($start->diffInMinutes($this->due_resolution_at)));
        $left  = (int) round(now()->diffInMinutes($this->due_resolution_at, false));

        if ($left <= 0) {
            return [
                'state' => 'late',
                'label' => 'Telat '.$this->humanMinutes(abs($left)),
                'pct'   => 100,
            ];
        }

        $pct = (int) max(3, min(100, round($left / $total * 100)));

        return [
            'state' => $pct <= 25 ? 'warn' : '',
            'label' => 'Sisa '.$this->humanMinutes($left),
            'pct'   => $pct,
        ];
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' mnt';
        }

        if ($minutes < 1440) {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;

            return $m > 0 ? $h.' jam '.$m.' mnt' : $h.' jam';
        }

        $d = intdiv($minutes, 1440);
        $h = intdiv($minutes % 1440, 60);

        return $h > 0 ? $d.' hari '.$h.' jam' : $d.' hari';
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

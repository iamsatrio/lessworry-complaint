<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kolom tabel complaints, ditulis supaya analisis statis tahu bentuknya —
 * casts() hanya berlaku saat berjalan, tidak terbaca PHPStan.
 *
 * @property int $id
 * @property string $ticket_number
 * @property string $channel
 * @property string $reporter_name
 * @property string|null $reporter_phone
 * @property string|null $nevira_transaction_id
 * @property string|null $nevira_transaction_number
 * @property string|null $nevira_customer_id
 * @property array<string,mixed>|null $nevira_snapshot
 * @property Carbon|null $nevira_synced_at
 * @property string|null $nevira_sync_error
 * @property string|null $nota_exemption
 * @property int|null $outlet_id
 * @property string $category
 * @property string|null $sub_category
 * @property string $priority
 * @property string $status
 * @property int $lock_version
 * @property string $description
 * @property int|null $assigned_to
 * @property string|null $forwarded_division
 * @property string|null $resolution
 * @property string|null $root_cause
 * @property int $compensation_amount
 * @property Carbon|null $due_response_at
 * @property Carbon|null $due_resolution_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Outlet|null $outlet
 * @property-read User|null $assignee
 * @property-read User|null $creator
 */
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
            'nevira_snapshot' => 'array',
            'nevira_synced_at' => 'datetime',
            'due_response_at' => 'datetime',
            'due_resolution_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Orang-orang yang DITETAPKAN terlibat dalam complaint ini. (API-19)
     *
     * Beberapa baris untuk satu complaint itu wajar — satu keluhan sering
     * melibatkan kasir penerima, petugas cuci, dan kurir sekaligus. Kosong
     * juga wajar: complaint tanpa pelaku tidak pernah dipaksa.
     */
    /** @return HasMany<ComplaintResponsible, $this> */
    public function responsibles(): HasMany
    {
        return $this->hasMany(ComplaintResponsible::class)->orderBy('id');
    }

    /**
     * Orang-orang yang menyentuh order ini menurut NEVIRA — kasir penerima
     * dan setiap tahap produksi. Fakta, bukan tuduhan.
     *
     * @return array<int,array{stage:string,name:?string,nip:?string,staff_id:mixed,status:?string,duration:?int}>
     */
    public function orderHandlers(): array
    {
        $snapshot = $this->nevira_snapshot ?? [];
        $handlers = [];

        if (! empty($snapshot['cashier_name'])) {
            $handlers[] = [
                'stage' => 'Kasir penerima order',
                'name' => $snapshot['cashier_name'],
                'nip' => $snapshot['cashier_nip'] ?? null,
                'staff_id' => $snapshot['cashier_id'] ?? null,
                'status' => null,
                'duration' => null,
            ];
        }

        foreach ($snapshot['processes'] ?? [] as $process) {
            if (empty($process['staff_name'])) {
                continue;
            }

            $handlers[] = [
                'stage' => $process['stage'] ?? 'Tahap produksi',
                'name' => $process['staff_name'],
                'nip' => $process['staff_nip'] ?? null,
                'staff_id' => $process['staff_id'] ?? null,
                'status' => $process['status'] ?? null,
                'duration' => $process['duration'] ?? null,
            ];
        }

        return $handlers;
    }

    /** Kunci yang selalu ada di satu baris perjalanan kurir. */
    private const BENTUK_PENGANTARAN = [
        'id' => null, 'date' => null, 'status_code' => null, 'status' => null,
        'cancel_reason' => null, 'courier_name' => null, 'courier_nip' => null,
        'courier_id' => null, 'queue_no' => null, 'distance' => null,
        'notes' => null, 'courier_notes' => null, 'proof_count' => 0,
        'updated_at' => null,
    ];

    /**
     * Perjalanan kurir dari snapshot NEVIRA, dengan bentuk yang dijamin.
     *
     * Snapshot adalah data sistem lain yang mengendap berbulan-bulan: baris
     * yang disimpan sebelum summarizeDeliveries() berubah bentuk punya kunci
     * yang berbeda, dan halaman complaint yang membacanya langsung membalas
     * HTTP 500. Kunci yang hilang diisi null di sini supaya tampilan tidak
     * perlu tahu versi mana yang sedang dibacanya. (API-14 #11)
     */
    public function deliveries(): array
    {
        $rows = $this->nevira_snapshot['deliveries'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => array_merge(self::BENTUK_PENGANTARAN, $row))
            ->values()->all();
    }

    /** Umur transaksi NEVIRA dalam hari; null kalau tanggalnya tidak diketahui. */
    public function transactionAgeDays(): ?int
    {
        $created = $this->nevira_snapshot['created_at'] ?? null;

        if (! $created) {
            return null;
        }

        try {
            return (int) Carbon::parse($created)->diffInDays(now());
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
        return $this->responsibles()->exists();
    }

    /**
     * Id outlet menurut NEVIRA — dari nota kalau sudah tertarik, kalau belum
     * dari pemetaan outlet complaint ini. Dipakai untuk menyaring daftar
     * karyawan supaya petugas tidak menyisir seluruh perusahaan.
     */
    public function neviraOutletId(): ?string
    {
        $dariNota = $this->nevira_snapshot['outlet_id'] ?? null;

        return filled($dariNota)
            ? (string) $dariNota
            : ($this->outlet?->nevira_outlet_id ?: null);
    }

    /** @return HasMany<ComplaintActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ComplaintActivity::class)->latest();
    }

    /** @return HasMany<ComplaintAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }

    /**
     * Lampiran yang menempel pada keluhannya sendiri, bukan pada catatan
     * penanganan. Tanpa pemisahan ini, foto tindak lanjut ikut muncul di
     * kartu keluhan seolah-olah dikirim pelanggan sejak awal. (API-20)
     */
    public function intakeAttachments()
    {
        return $this->hasMany(ComplaintAttachment::class)->whereNull('complaint_activity_id');
    }

    /**
     * Complaint lain untuk nomor nota yang sama, sebatas yang boleh dilihat
     * pengguna ini. (API-8 T7)
     *
     * Sengaja peringatan, bukan larangan: satu nota memang bisa punya dua
     * keluhan berbeda — noda yang tidak hilang DAN antarnya telat. Yang
     * berbahaya adalah tidak tahu, karena satu keluhan lalu terhitung
     * berkali-kali di rekap SLA dan dikerjakan beberapa petugas paralel.
     *
     * Disaring visibleTo supaya peringatannya sendiri tidak jadi jalan
     * melihat complaint outlet lain.
     *
     * @return EloquentCollection<int,static>
     */
    public function kembaranNota(User $user): EloquentCollection
    {
        if (blank($this->nevira_transaction_number)) {
            return new EloquentCollection;
        }

        return static::query()
            ->visibleTo($user)
            ->where('nevira_transaction_number', $this->nevira_transaction_number)
            ->whereKeyNot($this->getKey())
            ->orderBy('id')
            ->get();
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
                'pct' => 100,
            ];
        }

        if ($this->due_resolution_at === null) {
            return ['state' => '', 'label' => 'Tanpa tenggat', 'pct' => 0];
        }

        $start = $this->created_at ?? now();
        $total = max(1, (int) round($start->diffInMinutes($this->due_resolution_at)));
        $left = (int) round(now()->diffInMinutes($this->due_resolution_at, false));

        if ($left <= 0) {
            return [
                'state' => 'late',
                'label' => 'Telat '.$this->humanMinutes(abs($left)),
                'pct' => 100,
            ];
        }

        $pct = (int) max(3, min(100, round($left / $total * 100)));

        return [
            'state' => $pct <= 25 ? 'warn' : '',
            'label' => 'Sisa '.$this->humanMinutes($left),
            'pct' => $pct,
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

        // diffInMinutes() mengembalikan float di Carbon 3; dibulatkan ke
        // bawah secara eksplisit supaya tidak ada konversi implisit.
        return (int) $this->created_at->diffInMinutes($this->resolved_at);
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
            'kasir' => $query->where('outlet_id', $user->outlet_id),
            'divisi' => $query->where('forwarded_division', $user->division),
            default => $query,
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

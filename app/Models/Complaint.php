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
 * @property string $bobot
 * @property string|null $layanan
 * @property string $status
 * @property string|null $close_reason
 * @property int $lock_version
 * @property string $description
 * @property int|null $assigned_to
 * @property string|null $forwarded_division
 * @property string|null $resolution
 * @property string|null $tindak_lanjut
 * @property string|null $root_cause
 * @property int $compensation_amount
 * @property Carbon|null $due_response_at
 * @property Carbon|null $due_resolution_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $paused_at
 * @property string|null $pause_reason
 * @property int $paused_minutes
 * @property Carbon|null $resolved_at
 * @property int|null $created_by
 * @property string|null $import_source
 * @property int|null $import_row
 * @property string|null $import_fingerprint
 * @property string|null $legacy_nota_number
 * @property string|null $legacy_outlet_name
 * @property string|null $legacy_pelaku
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
        'outlet_id', 'category', 'sub_category', 'bobot', 'layanan',
        'description', 'assigned_to', 'forwarded_division',
    ];

    /**
     * Default harus ada di model, bukan hanya di kolom database: instance
     * yang baru disimpan masih memegang null sampai di-refresh, dan form
     * halaman complaint membaca nilai ini langsung. (API-8 T6)
     */
    protected $attributes = [
        'lock_version' => 0,
        'paused_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'nevira_snapshot' => 'array',
            'nevira_synced_at' => 'datetime',
            'due_response_at' => 'datetime',
            'due_resolution_at' => 'datetime',
            'first_response_at' => 'datetime',
            'paused_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
            'paused_minutes' => 'integer',
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

    /**
     * Tenggat dihitung dari bobot: respon pertama satu angka untuk semua
     * (janji 1x24 jam yang sudah beredar ke pelanggan), penyelesaian dalam
     * HARI menurut bobot. (API-18 #3)
     */
    public function applySla(): void
    {
        $base = $this->created_at ?? now();
        $hari = config('complaint.sla.resolution_days.'.$this->bobot)
            ?? config('complaint.sla.resolution_days.sedang');

        $this->due_response_at = $base->copy()->addHours((int) config('complaint.sla.response_hours'));
        $this->due_resolution_at = $base->copy()->addDays((int) $hari);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, config('complaint.open_statuses'), true);
    }

    /**
     * Tiket yang dijeda tetap Handling di papan, tapi jam SLA-nya berhenti.
     * Jedanya penanda, bukan status. (API-18 #6)
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null && $this->isOpen();
    }

    /** Lama jeda yang sedang berjalan, dalam menit. */
    public function pauseMinutes(): int
    {
        return $this->paused_at === null
            ? 0
            : (int) round($this->paused_at->diffInMinutes(now()));
    }

    /**
     * Mulai jeda. Tidak menumpuk: memanggilnya dua kali tidak memundurkan
     * titik awal jeda, jadi tenggatnya tidak bisa dimundurkan berulang-ulang
     * hanya dengan menyimpan form yang sama.
     */
    public function pause(string $reason): void
    {
        if ($this->paused_at !== null) {
            $this->pause_reason = $reason;

            return;
        }

        $this->paused_at = now();
        $this->pause_reason = $reason;
    }

    /**
     * Lanjutkan: tenggat mundur sebanyak lama jeda. Yang berhenti adalah
     * hitungan SLA, bukan tiketnya.
     */
    public function resume(): int
    {
        if ($this->paused_at === null) {
            return 0;
        }

        $menit = $this->pauseMinutes();

        if ($this->due_response_at !== null) {
            $this->due_response_at = $this->due_response_at->copy()->addMinutes($menit);
        }

        if ($this->due_resolution_at !== null) {
            $this->due_resolution_at = $this->due_resolution_at->copy()->addMinutes($menit);
        }

        // Ditotalkan, bukan ditimpa: satu tiket bisa dijeda berkali-kali, dan
        // laporan butuh seluruhnya — bukan yang terakhir saja.
        $this->paused_minutes = (int) $this->paused_minutes + $menit;

        $this->paused_at = null;
        $this->pause_reason = null;

        return $menit;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && ! $this->isPaused()
            && $this->due_resolution_at !== null
            && $this->due_resolution_at->isPast();
    }

    public function isResponseOverdue(): bool
    {
        return $this->isOpen()
            && ! $this->isPaused()
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
                    : 'Selesai '.self::humanMinutes($minutes),
                'pct' => 100,
            ];
        }

        if ($this->due_resolution_at === null) {
            return ['state' => '', 'label' => 'Tanpa tenggat', 'pct' => 0];
        }

        $start = $this->created_at ?? now();
        $total = max(1, (int) round($start->diffInMinutes($this->due_resolution_at)));

        // Selama dijeda, jam berhenti di titik jeda — bukan terus berjalan
        // lalu tiba-tiba merah karena pelanggan yang belum membalas.
        $acuan = $this->isPaused() ? $this->paused_at : now();
        $left = (int) round($acuan->diffInMinutes($this->due_resolution_at, false));

        if ($this->isPaused()) {
            return [
                'state' => 'paused',
                'label' => 'Dijeda · '.$this->pauseReasonLabel(),
                'pct' => (int) max(3, min(100, round(max($left, 0) / $total * 100))),
            ];
        }

        if ($left <= 0) {
            return [
                'state' => 'late',
                'label' => 'Telat '.self::humanMinutes(abs($left)),
                'pct' => 100,
            ];
        }

        $pct = (int) max(3, min(100, round($left / $total * 100)));

        return [
            'state' => $pct <= 25 ? 'warn' : '',
            'label' => 'Sisa '.self::humanMinutes($left),
            'pct' => $pct,
        ];
    }

    public static function humanMinutes(int $minutes): string
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

    /**
     * Lama penyelesaian dalam menit — WAKTU KERJA TIM, jeda tidak dihitung.
     * Null kalau belum selesai. (Review PR #1 nomor 3)
     *
     * Tanpa pengurangan ini, satu tiket melaporkan dua kebenaran yang
     * bertabrakan: `isOverdue()` bilang tepat waktu karena tenggatnya ikut
     * mundur selama jeda, sementara laporan bilang sebelas hari karena
     * menghitung mentah dari `created_at` ke `resolved_at`.
     *
     * Yang lebih buruk daripada angkanya salah: makin benar tim memakai jeda,
     * makin lambat mereka terlihat di laporannya sendiri. Itu mengajari orang
     * untuk berhenti memakai jeda — padahal jeda itu yang membuat SLA jujur.
     */
    public function resolutionMinutes(): ?int
    {
        if ($this->resolved_at === null) {
            return null;
        }

        $total = (int) round($this->created_at->diffInMinutes($this->resolved_at));

        // Jeda yang masih berjalan ikut dikurangi. Jalur normal selalu
        // melanjutkan tiket sebelum menutupnya, tapi angka ini tidak boleh
        // bergantung pada urutan pemanggilan orang lain.
        $jeda = (int) $this->paused_minutes + $this->pauseMinutes();

        return max(0, $total - $jeda);
    }

    /** Total lama jeda tiket ini, termasuk jeda yang sedang berjalan. */
    public function totalPauseMinutes(): int
    {
        return (int) $this->paused_minutes + $this->pauseMinutes();
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
            // whereNotNull lebih dulu, bukan hiasan: kasir yang outlet-nya
            // belum diisi punya outlet_id null, dan `where('outlet_id', null)`
            // diterjemahkan Eloquent jadi `whereNull` — yang justru cocok
            // dengan complaint impor yang outletnya tidak punya padanan.
            // Cakupan kosong harus berarti TIDAK MELIHAT APA PUN, bukan
            // melihat semua yang sama-sama kosong. (Review PR #7, P2-2)
            'kasir' => $user->outlet_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('outlet_id', $user->outlet_id),
            'divisi' => $user->division === null
                ? $query->whereRaw('1 = 0')
                : $query->where('forwarded_division', $user->division),
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

    public function bobotLabel(): string
    {
        return config('complaint.bobot.'.$this->bobot, $this->bobot);
    }

    public function layananLabel(): ?string
    {
        return $this->layanan
            ? config('complaint.layanan.'.$this->layanan, $this->layanan)
            : null;
    }

    public function tindakLanjutLabel(): ?string
    {
        return $this->tindak_lanjut
            ? config('complaint.tindak_lanjut.'.$this->tindak_lanjut, $this->tindak_lanjut)
            : null;
    }

    public function closeReasonLabel(): ?string
    {
        return $this->close_reason
            ? config('complaint.close_reasons.'.$this->close_reason, $this->close_reason)
            : null;
    }

    public function pauseReasonLabel(): ?string
    {
        return $this->pause_reason
            ? config('complaint.pause_reasons.'.$this->pause_reason, $this->pause_reason)
            : null;
    }

    /**
     * Status siap tampil, penandanya ikut: papan harus bisa membedakan
     * "Handling" dari "Handling, dijeda" tanpa menambah status keenam.
     */
    public function statusDisplay(): string
    {
        if ($this->isPaused()) {
            return $this->statusLabel().' · '.$this->pauseReasonLabel();
        }

        if ($this->status === 'close' && $this->close_reason) {
            return $this->statusLabel().' · '.$this->closeReasonLabel();
        }

        return $this->statusLabel();
    }

    /**
     * Kanal masuk. Complaint hasil impor data lama memakai kanal `impor`,
     * yang sengaja TIDAK ada di daftar kanal intake — kasir tidak boleh
     * bisa memilihnya — tapi tetap punya label supaya papan dan laporan
     * tidak menampilkan kunci mentah. (API-28)
     */
    public function channelLabel(): string
    {
        return config('complaint.channels.'.$this->channel)
            ?? config('complaint.channels_legacy.'.$this->channel, $this->channel);
    }

    /** Complaint ini masuk lewat impor data lama, bukan dicatat orang. */
    public function isImported(): bool
    {
        return filled($this->import_source);
    }

    /**
     * Tautan WhatsApp ke pelapor, lengkap dengan pembuka pesan.
     *
     * Mengabari pelanggan adalah langkah terakhir yang wajib pada setiap
     * penutupan, dan dua dari tiga kanal masuk memang WhatsApp. (API-38 #10)
     *
     * Nomor Indonesia ditulis tim dalam beberapa bentuk: 08xx, 62xx, +62xx,
     * dengan spasi atau tanda hubung. wa.me hanya menerima angka berformat
     * internasional tanpa tanda apa pun. Yang tidak bisa dinormalkan dengan
     * yakin — nomor terlalu pendek, atau bukan angka sama sekali — dibalas
     * null, dan halaman jatuh ke tautan `tel:` alih-alih menebak.
     */
    public function waLink(?string $pesan = null): ?string
    {
        $angka = preg_replace('/\D+/', '', (string) $this->reporter_phone) ?? '';

        if (str_starts_with($angka, '0')) {
            $angka = '62'.substr($angka, 1);
        } elseif (str_starts_with($angka, '8')) {
            // Ditulis tanpa nol depan, mis. 81234567890.
            $angka = '62'.$angka;
        }

        // 62 + 9 digit adalah nomor seluler Indonesia terpendek yang masuk akal.
        if (! str_starts_with($angka, '62') || strlen($angka) < 11 || strlen($angka) > 15) {
            return null;
        }

        return 'https://wa.me/'.$angka
            .(filled($pesan) ? '?text='.rawurlencode($pesan) : '');
    }
}

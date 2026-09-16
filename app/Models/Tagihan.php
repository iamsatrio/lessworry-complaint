<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu tagihan bulanan atau tahunan — sewa, listrik, internet, langganan
 * perangkat lunak. (API-73)
 *
 * Daftarnya dikelola lewat halaman, bukan lewat berkas config: menambah satu
 * baris tidak boleh menuntut deploy.
 *
 * TIDAK PERNAH DIHAPUS, hanya dinonaktifkan. Riwayat pembayarannya menempel
 * pada barisnya, dan tagihan yang dihapus membawa jejaknya ikut hilang —
 * "kapan terakhir internet outlet Kelapa Gading dibayar" kehilangan jawabannya
 * tanpa siapa pun sadar ia pernah punya satu.
 *
 * @property int $id
 * @property string $nama
 * @property int|null $jumlah
 * @property int $jatuh_tempo_hari
 * @property int|null $jatuh_tempo_bulan
 * @property string $pengulangan
 * @property int|null $outlet_id
 * @property bool $is_active
 * @property-read Outlet|null $outlet
 * @property-read Collection<int, PembayaranTagihan> $pembayaran
 */
class Tagihan extends Model
{
    protected $table = 'tagihan';

    protected $fillable = [
        'nama', 'jumlah', 'jatuh_tempo_hari', 'jatuh_tempo_bulan',
        'pengulangan', 'outlet_id', 'is_active',
    ];

    protected $attributes = [
        'pengulangan' => 'bulanan',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'jatuh_tempo_hari' => 'integer',
            'jatuh_tempo_bulan' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return HasMany<PembayaranTagihan, $this> */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranTagihan::class);
    }

    public function tahunan(): bool
    {
        return $this->pengulangan === 'tahunan';
    }

    /* ---------- Cakupan ---------- */

    /**
     * Tagihan yang boleh dilihat pengguna ini. (API-73 kriteria 7)
     *
     * Tagihan tingkat jaringan (`outlet_id` null) terlihat semua pemegang
     * `dashboard.view` — sewa kantor pusat dan langganan perangkat lunak
     * memang bukan milik satu outlet. Yang ber-outlet mengikuti cakupan outlet
     * pembacanya, dengan aturan yang SAMA seperti Outlet::scopeVisibleTo,
     * termasuk penjagaan null-nya: kasir yang outlet-nya belum diisi tidak
     * melihat tagihan ber-outlet mana pun, bukan melihat semuanya.
     *
     * Ini yang menegakkan, bukan menu yang disembunyikan. Alarm dan halaman
     * dua-duanya lewat sini.
     *
     * @param  Builder<Tagihan>  $query
     * @return Builder<Tagihan>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllOutlets()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user): void {
            $q->whereNull('outlet_id');

            if ($user->outlet_id !== null) {
                $q->orWhere('outlet_id', $user->outlet_id);
            }
        });
    }

    /* ---------- Jatuh tempo ---------- */

    /**
     * Tanggal yang dipakai kalau bulan itu tidak punya tanggal sebanyak itu.
     * (API-73 kriteria 3)
     *
     * Jatuh tempo tanggal 31 di bulan Februari jatuh ke 28 — atau 29 di tahun
     * kabisat. Dipotong SAAT DIHITUNG, tidak saat disimpan: kalau 31 disimpan
     * sebagai 28 karena kebetulan dicatat bulan Februari, seluruh bulan
     * berikutnya ikut jatuh tanggal 28 selamanya.
     */
    public static function amankan(int $tahun, int $bulan, int $hari): CarbonImmutable
    {
        $pertama = CarbonImmutable::createFromDate($tahun, $bulan, 1)->startOfDay();

        return $pertama->setDay(min($hari, $pertama->daysInMonth));
    }

    /**
     * Jatuh tempo yang berada di periode yang sama dengan $acuan — bulan yang
     * sama untuk tagihan bulanan, tahun yang sama untuk tagihan tahunan.
     */
    public function jatuhTempoDi(CarbonImmutable $acuan): CarbonImmutable
    {
        return $this->tahunan()
            ? self::amankan($acuan->year, $this->jatuh_tempo_bulan ?? 1, $this->jatuh_tempo_hari)
            : self::amankan($acuan->year, $acuan->month, $this->jatuh_tempo_hari);
    }

    /** Jatuh tempo satu periode SESUDAH periode yang memuat $acuan. */
    public function jatuhTempoSetelah(CarbonImmutable $acuan): CarbonImmutable
    {
        if ($this->tahunan()) {
            return self::amankan($acuan->year + 1, $this->jatuh_tempo_bulan ?? 1, $this->jatuh_tempo_hari);
        }

        // addMonthNoOverflow di tanggal 1, bukan di tanggal jatuh temponya:
        // 31 Januari + 1 bulan jatuh ke 28 Februari, lalu + 1 bulan lagi jatuh
        // ke 28 Maret — deretnya bergeser turun dan tidak pernah kembali.
        $berikut = CarbonImmutable::createFromDate($acuan->year, $acuan->month, 1)->addMonthNoOverflow();

        return self::amankan($berikut->year, $berikut->month, $this->jatuh_tempo_hari);
    }

    /** Jatuh tempo satu periode SEBELUM periode yang memuat $acuan. */
    public function jatuhTempoSebelum(CarbonImmutable $acuan): CarbonImmutable
    {
        if ($this->tahunan()) {
            return self::amankan($acuan->year - 1, $this->jatuh_tempo_bulan ?? 1, $this->jatuh_tempo_hari);
        }

        $sebelum = CarbonImmutable::createFromDate($acuan->year, $acuan->month, 1)->subMonthNoOverflow();

        return self::amankan($sebelum->year, $sebelum->month, $this->jatuh_tempo_hari);
    }

    /**
     * Kunci periode sebuah jatuh tempo, 'YYYY-MM'.
     *
     * Satu bentuk untuk bulanan maupun tahunan — tagihan tahunan memakai bulan
     * jatuh temponya.
     */
    public static function periode(CarbonImmutable $jatuhTempo): string
    {
        return $jatuhTempo->format('Y-m');
    }

    /** Rupiah, atau tanda pisah kalau nominalnya memang tidak tetap. */
    public function jumlahTerbaca(): string
    {
        return $this->jumlah === null ? '—' : 'Rp '.number_format($this->jumlah, 0, ',', '.');
    }

    /** "Tanggal 5 tiap bulan" · "5 Maret tiap tahun". */
    public function jadwalTerbaca(): string
    {
        if (! $this->tahunan()) {
            return 'Tanggal '.$this->jatuh_tempo_hari.' tiap bulan';
        }

        $bulan = self::amankan(2024, $this->jatuh_tempo_bulan ?? 1, 1)->locale('id')->translatedFormat('F');

        return $this->jatuh_tempo_hari.' '.$bulan.' tiap tahun';
    }
}

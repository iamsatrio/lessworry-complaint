<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Isi rekap ekspor halaman Laporan — kolomnya, tipenya, dan barisnya.
 * (API-62 nomor 4)
 *
 * SATU tempat, dipakai CSV dan `.xlsx` sama-sama. Kolomnya menyangkut aturan
 * yang tidak boleh berbeda antar format:
 *
 * - nomor nota, BUKAN id internal NEVIRA — rekap ini diteruskan lewat
 *   WhatsApp dan email, dan pengenal internal sistem lain tidak boleh ikut
 *   keluar (API-8 T2);
 * - kolom karyawan hanya untuk yang berwenang melihatnya.
 *
 * Dua penulis yang masing-masing menyusun barisnya sendiri berarti dua daftar
 * kolom yang bisa berbeda, dan yang berbeda diam-diam adalah yang lebih jarang
 * dibuka. Di sini keduanya membaca daftar yang sama.
 */
final class RekapEkspor
{
    /** Kolom yang isinya teks apa adanya — termasuk yang KELIHATAN angka. */
    public const TEKS = 'teks';

    /** Bilangan bulat: boleh dijumlah dan dirata-rata di lembar sebarnya. */
    public const ANGKA = 'angka';

    /** Bilangan bulat rupiah; di `.xlsx` diberi format mata uang. */
    public const RUPIAH = 'rupiah';

    /** Tanggal dan jam sungguhan, bukan teks yang berbentuk tanggal. */
    public const TANGGAL = 'tanggal';

    /** @var EloquentCollection<int,ComplaintResponsible>|null */
    private ?EloquentCollection $pelaku = null;

    /**
     * @param  EloquentCollection<int,Complaint>  $complaints  sudah disaring wewenang, tanggal, dan outlet
     */
    public function __construct(
        private readonly User $user,
        private readonly EloquentCollection $complaints,
    ) {}

    /**
     * Kolom rekap: judul dan tipenya, berpasangan.
     *
     * @return list<array{judul:string,tipe:string}>
     */
    public function kolom(): array
    {
        $kolom = [
            ['judul' => 'Nomor Tiket', 'tipe' => self::TEKS],
            ['judul' => 'Dibuat', 'tipe' => self::TANGGAL],
            ['judul' => 'Kanal', 'tipe' => self::TEKS],
            ['judul' => 'Outlet', 'tipe' => self::TEKS],
            ['judul' => 'Pelapor', 'tipe' => self::TEKS],
            // Nomor telepon TEKS, bukan angka: `081234567890` yang disimpan
            // sebagai bilangan kehilangan nol di depannya dan berubah jadi
            // nomor orang lain.
            ['judul' => 'Telepon', 'tipe' => self::TEKS],
            ['judul' => 'Nomor Nota', 'tipe' => self::TEKS],
            ['judul' => 'Kategori', 'tipe' => self::TEKS],
            ['judul' => 'Bobot', 'tipe' => self::TEKS],
            ['judul' => 'Layanan', 'tipe' => self::TEKS],
            ['judul' => 'Status', 'tipe' => self::TEKS],
            ['judul' => 'Alasan Penutupan', 'tipe' => self::TEKS],
            ['judul' => 'Tindak Lanjut', 'tipe' => self::TEKS],
            ['judul' => 'Penanggung Jawab', 'tipe' => self::TEKS],
            ['judul' => 'Selesai', 'tipe' => self::TANGGAL],
            ['judul' => 'Menit Penyelesaian', 'tipe' => self::ANGKA],
            ['judul' => 'Kompensasi', 'tipe' => self::RUPIAH],
            ['judul' => 'Lewat SLA', 'tipe' => self::TEKS],
        ];

        if ($this->user->canSeeStaffAttribution()) {
            foreach (['Karyawan Penanggung Jawab', 'NIP', 'Peran', 'Alasan'] as $judul) {
                $kolom[] = ['judul' => $judul, 'tipe' => self::TEKS];
            }
        }

        return $kolom;
    }

    /** @return list<string> */
    public function judulKolom(): array
    {
        return array_map(fn (array $k) => $k['judul'], $this->kolom());
    }

    /** @return list<string> */
    public function tipeKolom(): array
    {
        return array_map(fn (array $k) => $k['tipe'], $this->kolom());
    }

    /**
     * Nilai apa adanya per baris: tanggal tetap Carbon, angka tetap int.
     * Yang mengubahnya jadi teks penulis formatnya, bukan di sini — itu yang
     * membuat `.xlsx` bisa menyimpan tanggal sebagai tanggal sementara CSV
     * tetap menulis bentuk yang sama seperti sebelumnya.
     *
     * @return iterable<list<Carbon|int|string|null>>
     */
    public function baris(): iterable
    {
        $showStaff = $this->user->canSeeStaffAttribution();

        foreach ($this->complaints as $c) {
            $pelaku = $showStaff ? $this->pelakuComplaint($c->id) : collect();

            yield [
                $c->ticket_number,
                $c->created_at,
                $c->channelLabel(),
                $c->outlet?->name,
                $c->reporter_name,
                $c->reporter_phone,
                // Nomor nota, bukan id internal NEVIRA. (API-8 T2)
                $c->nevira_transaction_number,
                $c->categoryLabel(),
                $c->bobotLabel(),
                $c->layananLabel(),
                $c->statusLabel(),
                $c->closeReasonLabel(),
                $c->tindakLanjutLabel(),
                $c->assignee?->name,
                $c->resolved_at,
                $c->resolutionMinutes(),
                $c->compensation_amount,
                $c->isOverdue() ? 'YA' : 'tidak',
                ...($showStaff ? [
                    $pelaku->pluck('staff_name')->implode('; '),
                    $pelaku->pluck('staff_nip')->implode('; '),
                    $pelaku->map(fn ($p) => $p->roleLabel().($p->stage ? ' ('.$p->stage.')' : ''))->implode('; '),
                    $pelaku->map(fn ($p) => $p->staff_name.': '.$p->reason)->implode(' | '),
                ] : []),
            ];
        }
    }

    /** Nama berkasnya, tanpa ekstensi. */
    public function namaBerkas(): string
    {
        return 'complaint-'.now()->format('Ymd-His');
    }

    /**
     * Semua pelaku satu complaint masuk ke satu baris — rekap ini dibaca per
     * complaint, bukan per orang.
     *
     * @return Collection<int,ComplaintResponsible>
     */
    private function pelakuComplaint(int $complaintId): Collection
    {
        if ($this->pelaku === null) {
            $this->pelaku = ComplaintResponsible::whereIn('complaint_id', $this->complaints->modelKeys())->get();
        }

        return $this->pelaku->where('complaint_id', $complaintId)->values();
    }
}

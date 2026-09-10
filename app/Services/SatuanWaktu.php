<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Satuan waktu sumbu mendatar grafik halaman Laporan. (API-62 nomor 2)
 *
 * Keempatnya tersedia, tapi yang dipakai ditentukan rentang tanggalnya
 * sendiri kecuali orangnya memilih lain. Alasannya ada di kepadatan datanya:
 * dari 545 baris pertama, 248 hari (46%) tidak punya satu pun complaint dan
 * 152 hari punya tepat satu. Harian pada rentang panjang menggambar gigi
 * gergaji nol-dan-satu; tahunan pada dua tahun data menggambar dua titik, dan
 * dua titik bukan grafik.
 *
 * Karena itu Tahunan tidak pernah jadi bawaan — hanya bisa dipilih sendiri.
 *
 * Nama bulan ditulis di sini, bukan diambil dari locale Carbon: locale `id`
 * menyingkat Agustus jadi "Agt" sementara grafik dan tabel halaman ini sudah
 * memakai "Agu" sejak API-52. Label sumbu bukan tempat yang tepat untuk
 * berubah diam-diam mengikuti versi pustaka.
 */
enum SatuanWaktu: string
{
    case Harian = 'harian';
    case Mingguan = 'mingguan';
    case Bulanan = 'bulanan';
    case Tahunan = 'tahunan';

    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const BULAN_PENUH = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * Satuan yang dipakai kalau orangnya tidak memilih apa pun.
     *
     * ≤ 31 hari → harian, ≤ 6 bulan → mingguan, lebih dari itu → bulanan.
     * Tahunan tidak pernah muncul di sini.
     */
    public static function bawaanUntuk(Carbon $dari, Carbon $sampai): self
    {
        if ($sampai->copy()->startOfDay()->lte($dari->copy()->startOfDay()->addDays(30))) {
            return self::Harian;
        }

        if ($sampai->copy()->startOfDay()->lte($dari->copy()->startOfDay()->addMonths(6))) {
            return self::Mingguan;
        }

        return self::Bulanan;
    }

    /**
     * Satuan satu tingkat lebih lebar, atau null kalau sudah paling lebar.
     * Dipakai keterangan kepadatan data untuk menyebut jalan keluarnya dengan
     * nama — "coba yang lebih lebar" menyuruh orang menebak yang mana.
     */
    public function lebihLebar(): ?self
    {
        return match ($this) {
            self::Harian => self::Mingguan,
            self::Mingguan => self::Bulanan,
            self::Bulanan => self::Tahunan,
            self::Tahunan => null,
        };
    }

    /** Satuan yang diminta, atau null kalau nilainya bukan salah satu dari keempatnya. */
    public static function dariNilai(mixed $nilai): ?self
    {
        return is_string($nilai) ? self::tryFrom($nilai) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Harian => 'Harian',
            self::Mingguan => 'Mingguan',
            self::Bulanan => 'Bulanan',
            self::Tahunan => 'Tahunan',
        };
    }

    /** Nama satu periodenya — dipakai sebagai judul kolom tabel. */
    public function satuan(): string
    {
        return match ($this) {
            self::Harian => 'Hari',
            self::Mingguan => 'Minggu',
            self::Bulanan => 'Bulan',
            self::Tahunan => 'Tahun',
        };
    }

    /** Potongan judul grafik: "Complaint per outlet per bulan". */
    public function perSatuan(): string
    {
        return 'per '.mb_strtolower($this->satuan());
    }

    /**
     * Kunci pengelompokan satu waktu.
     *
     * Minggu diwakili tanggal SENINNYA, bukan nomor minggu ISO: nomor minggu
     * punya tahun sendiri yang tidak selalu sama dengan tahun tanggalnya, dan
     * 29 Desember 2025 yang tersimpan sebagai minggu ke-1 tahun 2026 akan
     * mengurutkan dirinya di depan seluruh 2025.
     */
    public function kunci(Carbon $waktu): string
    {
        return match ($this) {
            self::Harian => $waktu->format('Y-m-d'),
            self::Mingguan => $waktu->copy()->startOfWeek()->format('Y-m-d'),
            self::Bulanan => $waktu->format('Y-m'),
            self::Tahunan => $waktu->format('Y'),
        };
    }

    public function mulai(string $kunci): Carbon
    {
        return match ($this) {
            self::Harian, self::Mingguan => Carbon::parse($kunci)->startOfDay(),
            self::Bulanan => Carbon::parse($kunci.'-01')->startOfMonth(),
            self::Tahunan => Carbon::parse($kunci.'-01-01')->startOfYear(),
        };
    }

    public function akhir(string $kunci): Carbon
    {
        $mulai = $this->mulai($kunci);

        return match ($this) {
            self::Harian => $mulai->endOfDay(),
            self::Mingguan => $mulai->addDays(6)->endOfDay(),
            self::Bulanan => $mulai->endOfMonth(),
            self::Tahunan => $mulai->endOfYear(),
        };
    }

    /** Periode sesudah $kunci, dipakai menyusun sumbu tanpa bolong. */
    public function sesudah(string $kunci): string
    {
        $mulai = $this->mulai($kunci);

        return $this->kunci(match ($this) {
            self::Harian => $mulai->addDay(),
            self::Mingguan => $mulai->addWeek(),
            self::Bulanan => $mulai->addMonth(),
            self::Tahunan => $mulai->addYear(),
        });
    }

    /** Label pendek di bawah sumbu mendatar — harus muat berdampingan. */
    public function labelSumbu(string $kunci): string
    {
        $mulai = $this->mulai($kunci);

        return match ($this) {
            self::Harian, self::Mingguan => $mulai->day.' '.self::BULAN[$mulai->month - 1],
            self::Bulanan => self::BULAN[$mulai->month - 1].' '.$mulai->format('y'),
            self::Tahunan => $mulai->format('Y'),
        };
    }

    /**
     * Label panjang di kotak keterangan. Di sini ruangnya cukup untuk menulis
     * periodenya utuh — dan pada satuan mingguan itu bukan kemewahan: label
     * sumbunya cuma menyebut tanggal Seninnya, jadi "3–9 Agustus 2026" yang
     * membuat pembacanya tahu satu titik itu mencakup apa.
     */
    public function labelPenuh(string $kunci): string
    {
        $mulai = $this->mulai($kunci);

        return match ($this) {
            self::Harian => $mulai->day.' '.self::BULAN_PENUH[$mulai->month - 1].' '.$mulai->format('Y'),
            self::Mingguan => $this->rentangMinggu($mulai),
            self::Bulanan => self::BULAN_PENUH[$mulai->month - 1].' '.$mulai->format('Y'),
            self::Tahunan => $mulai->format('Y'),
        };
    }

    /**
     * "3–9 Agustus 2026", dan yang lebih panjang saat minggunya melompati
     * batas bulan atau tahun: bulan dan tahun baru ditulis dua kali kalau
     * memang berbeda, tidak pernah dihilangkan demi ringkas.
     */
    private function rentangMinggu(Carbon $mulai): string
    {
        $akhir = $mulai->copy()->addDays(6);

        if ($mulai->year !== $akhir->year) {
            return $this->tanggalPenuh($mulai).'–'.$this->tanggalPenuh($akhir);
        }

        if ($mulai->month !== $akhir->month) {
            return $mulai->day.' '.self::BULAN_PENUH[$mulai->month - 1].'–'.$this->tanggalPenuh($akhir);
        }

        return $mulai->day.'–'.$akhir->day.' '.self::BULAN_PENUH[$akhir->month - 1].' '.$akhir->format('Y');
    }

    private function tanggalPenuh(Carbon $waktu): string
    {
        return $waktu->day.' '.self::BULAN_PENUH[$waktu->month - 1].' '.$waktu->format('Y');
    }
}

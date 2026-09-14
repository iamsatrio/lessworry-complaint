<?php

namespace App\Services;

use App\Models\Complaint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Isi unduhan halaman Kerugian: satu baris per complaint YANG PUNYA NILAI
 * BIAYA. (API-43)
 *
 * Sengaja bukan seluruh complaint. Halaman Laporan sudah punya rekap lengkap
 * (RekapEkspor); yang belum ada adalah daftar yang menjelaskan dari mana
 * sebuah total biaya berasal, baris per baris, supaya angka di layar bisa
 * ditelusuri di luar sistem.
 *
 * Kolom `Golongan` ikut, bukan supaya cantik: pembaca yang menjumlah sendiri
 * kolom biaya di lembar sebarnya harus bisa memisahkan uang keluar dari kerja
 * ulang tanpa menghafal tindak lanjut mana masuk mana.
 *
 * Data pribadi pelapor TIDAK ikut. Berkas ini beredar lewat WhatsApp dan
 * email; nama dan nomor telepon pelanggan tidak punya urusan dengan
 * pertanyaan "berapa biayanya dan dari mana asalnya". Nomor tiket cukup untuk
 * menelusurinya kembali ke dalam sistem.
 */
final class RekapBiaya implements IsiRekap
{
    /**
     * @param  Collection<int,Complaint>  $complaints  sudah disaring wewenang, tanggal, dan outlet; hanya yang punya nilai
     */
    public function __construct(private readonly Collection $complaints) {}

    public function kolom(): array
    {
        return [
            ['judul' => 'Nomor Tiket', 'tipe' => self::TEKS],
            ['judul' => 'Dibuat', 'tipe' => self::TANGGAL],
            ['judul' => 'Outlet', 'tipe' => self::TEKS],
            ['judul' => 'Kategori', 'tipe' => self::TEKS],
            ['judul' => 'Layanan', 'tipe' => self::TEKS],
            ['judul' => 'Tindak Lanjut', 'tipe' => self::TEKS],
            ['judul' => 'Golongan Biaya', 'tipe' => self::TEKS],
            ['judul' => 'Biaya', 'tipe' => self::RUPIAH],
            // Nomor nota, BUKAN id internal NEVIRA — rekap ini keluar dari
            // sistem, dan pengenal internal sistem lain tidak ikut. (API-8 T2)
            ['judul' => 'Nomor Nota', 'tipe' => self::TEKS],
        ];
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

    /** @return iterable<list<Carbon|int|string|null>> */
    public function baris(): iterable
    {
        foreach ($this->complaints as $c) {
            yield [
                $c->ticket_number,
                $c->created_at,
                $c->outlet?->name,
                $c->categoryLabel(),
                $c->layananLabel(),
                $c->tindak_lanjut === null ? 'Belum diisi' : $c->tindakLanjutLabel(),
                self::labelGolongan(RekapKerugian::golonganDari($c->tindak_lanjut)),
                (int) $c->compensation_amount,
                $c->nevira_transaction_number,
            ];
        }
    }

    public function namaBerkas(): string
    {
        return 'kerugian-'.now()->format('Ymd-His');
    }

    private static function labelGolongan(string $kunci): string
    {
        return match ($kunci) {
            'uang_keluar' => 'Uang keluar',
            'kerja_ulang' => 'Kerja ulang',
            default => 'Belum digolongkan',
        };
    }
}

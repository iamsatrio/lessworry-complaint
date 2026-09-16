<?php

namespace App\Services;

use App\Models\Complaint;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Sebaran jarak hari antara barang diambil pelanggan dan complaint masuk.
 * (API-48)
 *
 * Yang dicari BUKAN mediannya. Dari 26 baris data lama yang punya kedua
 * tanggal, 16 masuk di hari yang sama dan 20 dalam <= 1 hari — mediannya nol,
 * dan nol tidak menyuruh siapa pun melakukan apa pun. Yang perlu terlihat
 * adalah EKORNYA: satu keluhan datang 34 hari setelah pengambilan, dan
 * keluhan seperti itulah yang butuh keputusan berbeda.
 *
 * Tiga hal dihitung terpisah, dan ketiganya harus terbaca di layar:
 *
 * 1. Yang terukur, dipecah menurut rentang hari.
 * 2. Yang tanggalnya TIDAK DIKETAHUI. Tidak disembunyikan, tidak dibulatkan
 *    ke nol, tidak dibuang dari pembagi. Sebaran dari 15% baris tidak boleh
 *    terlihat seperti sebaran dari 100%.
 * 3. Yang complaint-nya masuk SEBELUM pengambilan. Jarak harinya negatif, dan
 *    itu bukan cacat: "cucian saya belum selesai" memang datang sebelum
 *    barangnya diambil. Memampatkannya jadi nol akan menggemukkan "hari yang
 *    sama" dengan keluhan yang bahkan belum punya barang.
 */
final class SebaranJarakKomplain
{
    /** @var array<string,int>|null */
    private ?array $hitungan = null;

    private int $tidakDiketahui = 0;

    private int $sebelumPengambilan = 0;

    /** @param  EloquentCollection<int,Complaint>  $complaints  sudah disaring wewenang dan rentang tanggal */
    public function __construct(private readonly EloquentCollection $complaints) {}

    /**
     * Jumlah complaint per rentang hari.
     *
     * @return array<int,array{kunci:string,label:string,jumlah:int}>
     */
    public function rentang(): array
    {
        $hitungan = $this->hitung();

        return array_map(
            fn (array $r) => [
                'kunci' => (string) $r['kunci'],
                'label' => (string) $r['label'],
                'jumlah' => $hitungan[$r['kunci']] ?? 0,
            ],
            $this->definisi(),
        );
    }

    public function tidakDiketahui(): int
    {
        $this->hitung();

        return $this->tidakDiketahui;
    }

    public function sebelumPengambilan(): int
    {
        $this->hitung();

        return $this->sebelumPengambilan;
    }

    /** Complaint yang jarak harinya bisa dihitung — pembilang cakupan. */
    public function terukur(): int
    {
        return array_sum($this->hitung()) + $this->sebelumPengambilan();
    }

    public function total(): int
    {
        return $this->complaints->count();
    }

    /**
     * Berapa persen complaint pada rentang ini yang tanggal pengambilannya
     * diketahui. Angka inilah yang menahan pembaca menyimpulkan terlalu
     * banyak dari terlalu sedikit.
     */
    public function cakupanPersen(): float
    {
        $total = $this->total();

        return $total === 0 ? 0.0 : round($this->terukur() / $total * 100, 1);
    }

    /** Tidak satu pun complaint pada rentang ini punya tanggal pengambilan. */
    public function kosong(): bool
    {
        return $this->terukur() === 0;
    }

    /**
     * @return array<int,array{kunci:string,label:string,min:int,max:int|null}>
     */
    private function definisi(): array
    {
        /** @var array<int,array{kunci:string,label:string,min:int,max:int|null}> $def */
        $def = (array) config('complaint.jarak_komplain_rentang');

        return $def;
    }

    /** @return array<string,int> */
    private function hitung(): array
    {
        if ($this->hitungan !== null) {
            return $this->hitungan;
        }

        $hitungan = [];

        foreach ($this->definisi() as $r) {
            $hitungan[$r['kunci']] = 0;
        }

        foreach ($this->complaints as $complaint) {
            $jarak = $complaint->jarakKomplainHari();

            if ($jarak === null) {
                $this->tidakDiketahui++;

                continue;
            }

            if ($jarak < 0) {
                $this->sebelumPengambilan++;

                continue;
            }

            $kunci = $this->kunciRentang($jarak);

            if ($kunci !== null) {
                $hitungan[$kunci]++;
            }
        }

        return $this->hitungan = $hitungan;
    }

    private function kunciRentang(int $jarak): ?string
    {
        foreach ($this->definisi() as $r) {
            $max = $r['max'];

            if ($jarak >= $r['min'] && ($max === null || $jarak <= $max)) {
                return $r['kunci'];
            }
        }

        return null;
    }
}

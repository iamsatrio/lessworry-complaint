<?php

namespace App\View\Components\Grafik;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Grafik batang mendatar, terurut, digambar sebagai SVG di server. (API-52)
 *
 * Mendatar karena label kategorinya kata, bukan tanggal: batang tegak memaksa
 * "Barang Tertukar" dimiringkan atau dipotong. Terurut karena urutan besar-ke-
 * kecil itulah isi pesannya — bahwa satu kategori menyedot sebagian besar
 * biaya sementara jumlah kasusnya jauh dari yang terbanyak.
 *
 * Satu ukuran yang digambar. Ukuran kedua (jumlah kasus) ditulis sebagai
 * label, tidak pernah jadi batang kedua.
 */
class Batang extends Component
{
    private const W = 880;

    /**
     * Ruang label kategori di kiri dan ruang angka di kanan batang, dalam
     * satuan viewBox. Ditulis sebagai pecahan supaya seluruh koordinat yang
     * diturunkan darinya bertipe sama — geometri gambar tidak pernah bulat.
     */
    private const KIRI = 150.0;

    /**
     * Batas atas ruang label, dalam satuan viewBox. Di atas ini batangnya yang
     * tersisa terlalu pendek untuk dibandingkan, dan label yang masih lebih
     * panjang dipotong peramban — itu pilihan yang lebih baik daripada grafik
     * tanpa batang.
     */
    private const KIRI_MAKS = 260.0;

    /**
     * Lebar rata-rata satu huruf label pada font-size 14px, dalam satuan
     * viewBox. Taksiran, bukan pengukuran — SVG di server tidak bisa mengukur
     * teks — dan sengaja dilebihkan: label yang kelebaran menyisakan ruang
     * kosong, label yang kekurangan terpotong. (API-43)
     */
    private const LEBAR_HURUF = 7.4;

    private const KANAN = 250.0;

    private const TINGGI_BARIS = 34.0;

    private const ATAS = 6.0;

    /**
     * @param  list<array{label:string,nilai:float,teks:string,judul:string}>  $baris
     */
    public function __construct(
        public string $judul,
        public string $catatan,
        public array $baris,
        public string $warna = 'var(--teal)',
        public string $kosongTeks = 'Belum ada angka yang bisa digambar untuk periode ini.',
    ) {}

    public function render(): View
    {
        return view('components.grafik.batang');
    }

    public function kosong(): bool
    {
        return $this->baris === [];
    }

    public function viewBox(): string
    {
        return '0 0 '.self::W.' '.(self::ATAS + count($this->baris) * self::TINGGI_BARIS + 8);
    }

    /** @return list<array{y:float,lebar:float,labelY:float,teksX:float,label:string,teks:string,judul:string}> */
    public function bidang(): array
    {
        $maks = max(array_map(fn (array $b) => (float) $b['nilai'], $this->baris ?: [['nilai' => 0]]));
        $kiri = $this->kiri();
        $ruang = self::W - $kiri - self::KANAN;
        $bidang = [];

        foreach ($this->baris as $i => $b) {
            // Batang minimal 2px: kategori bernilai kecil tetap terlihat ada,
            // bukan menghilang jadi baris tanpa batang.
            $lebar = $maks > 0 ? max(2.0, ((float) $b['nilai'] / $maks) * $ruang) : 2.0;
            $y = self::ATAS + $i * self::TINGGI_BARIS;

            $bidang[] = [
                'y' => $y,
                'lebar' => round($lebar, 2),
                'labelY' => $y + 18,
                'teksX' => round($kiri + $lebar + 10, 2),
                'label' => $b['label'],
                'teks' => $b['teks'],
                'judul' => $b['judul'],
            ];
        }

        return $bidang;
    }

    /**
     * Ruang label di kiri, MELEBAR kalau labelnya menuntut.
     *
     * Nama outlet ("Less Worry 3.1 - Duren Tiga") dua kali lebih panjang dari
     * nama kategori ("Barang Rusak"), dan ruang tetap 150 memotongnya jadi
     * "Worry 3.1 - Duren Tiga" — terpotong di DEPAN, karena labelnya rata
     * kanan. Grafik yang memotong nama outlet di depan membuat dua outlet
     * terlihat sama. (API-43)
     */
    public function kiri(): float
    {
        $terpanjang = max(array_map(
            fn (array $b) => mb_strlen($b['label']),
            $this->baris ?: [['label' => '']],
        ));

        return min(self::KIRI_MAKS, max(self::KIRI, $terpanjang * self::LEBAR_HURUF + 12));
    }

    public function labelX(): float
    {
        return $this->kiri() - 10;
    }
}

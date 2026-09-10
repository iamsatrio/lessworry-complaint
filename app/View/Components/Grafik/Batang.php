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
        $ruang = self::W - self::KIRI - self::KANAN;
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
                'teksX' => round(self::KIRI + $lebar + 10, 2),
                'label' => $b['label'],
                'teks' => $b['teks'],
                'judul' => $b['judul'],
            ];
        }

        return $bidang;
    }

    public function kiri(): float
    {
        return self::KIRI;
    }

    public function labelX(): float
    {
        return self::KIRI - 10;
    }
}

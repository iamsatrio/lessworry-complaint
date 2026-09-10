<?php

namespace App\View\Components\Grafik;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Grafik garis, digambar sebagai SVG di server. (API-52)
 *
 * Tanpa pustaka grafik dan tanpa satu baris JavaScript: antarmuka aplikasi ini
 * tidak memuat satu pun paket JavaScript pihak ketiga, dan satu grafik bukan
 * alasan yang cukup untuk memulainya. Konsekuensi lain yang disengaja: grafik
 * ini tetap muncul di perangkat outlet yang skripnya diblokir, dan ikut
 * tercetak apa adanya.
 *
 * SATU UKURAN PER GRAFIK. Kalau butuh ukuran kedua, pakai grafik kedua —
 * dua sumbu berskala beda pada satu gambar membuat keduanya salah dibaca.
 */
class Garis extends Component
{
    private const W = 880;

    private const H = 260;

    private const KIRI = 54;

    private const KANAN = 20;

    private const ATAS = 24;

    private const BAWAH = 38;

    /** Jarak antar label sumbu mendatar; 18 bulan tidak muat berdampingan. */
    private const LABEL_MAKS = 9;

    private ?float $maks = null;

    private ?float $langkah = null;

    /**
     * @param  list<array{label:string,nilai:float|null,teks:string}>  $titik
     * @param  array{min:float,max:float,label:string}|null  $pita  rentang acuan, mis. ambang SLA
     */
    public function __construct(
        public string $judul,
        public string $catatan,
        public array $titik,
        public string $warna = 'var(--teal)',
        public ?array $pita = null,
        public string $kosongTeks = 'Belum ada angka yang bisa digambar untuk periode ini.',
    ) {}

    public function render(): View
    {
        return view('components.grafik.garis');
    }

    /** Tidak ada satu pun nilai yang bisa digambar. */
    public function kosong(): bool
    {
        return $this->nilai() === [];
    }

    /**
     * Angka sumbu ditulis dengan pecahan sebanyak yang DIBUTUHKAN langkahnya.
     * Langkah 0,25 yang dibulatkan ke satu angka di belakang koma menghasilkan
     * sumbu 0,3 · 0,5 · 0,8 · 1,0 — jaraknya terlihat tidak sama rata padahal
     * sama, dan pembacanya menyimpulkan skalanya melengkung.
     */
    private function angkaSumbu(float $nilai, float $langkah): string
    {
        $desimal = match (true) {
            fmod($langkah, 1.0) === 0.0 => 0,
            fmod($langkah * 10, 1.0) === 0.0 => 1,
            default => 2,
        };

        return number_format($nilai, $desimal, ',', '.');
    }

    /* ---------- Skala ---------- */

    /** @return list<float> */
    private function nilai(): array
    {
        $nilai = [];

        foreach ($this->titik as $t) {
            if ($t['nilai'] !== null) {
                $nilai[] = (float) $t['nilai'];
            }
        }

        return $nilai;
    }

    /**
     * Batas atas sumbu tegak. Pita acuan ikut dihitung: ambang SLA yang jatuh
     * di luar gambar tidak menjelaskan apa pun.
     */
    private function maks(): float
    {
        if ($this->maks !== null) {
            return $this->maks;
        }

        $puncak = max([0.0, ...$this->nilai(), $this->pita === null ? 0.0 : (float) $this->pita['max']]);
        $kasar = max($puncak * 1.14, 0.0001);
        $pangkat = 10 ** floor(log10($kasar));

        $langkah = $pangkat * 10;

        foreach ([1, 2, 2.5, 5, 10] as $kelipatan) {
            if ($kasar / ($kelipatan * $pangkat) <= 4) {
                $langkah = $kelipatan * $pangkat;
                break;
            }
        }

        $this->langkah = $langkah;

        return $this->maks = ceil($kasar / $langkah) * $langkah;
    }

    /** Garis bantu mendatar, dari nol ke atas. @return list<array{y:float,teks:string}> */
    public function sumbuY(): array
    {
        $maks = $this->maks();
        $langkah = (float) $this->langkah;
        $garis = [];

        for ($nilai = 0.0; $nilai <= $maks + 0.0001; $nilai += $langkah) {
            $garis[] = ['y' => $this->y($nilai), 'teks' => $this->angkaSumbu($nilai, $langkah)];
        }

        return $garis;
    }

    public function x(int $i): float
    {
        $lebar = self::W - self::KIRI - self::KANAN;
        $n = count($this->titik);

        return $n <= 1
            ? self::KIRI + $lebar / 2
            : self::KIRI + $i * $lebar / ($n - 1);
    }

    public function y(float $nilai): float
    {
        $tinggi = self::H - self::ATAS - self::BAWAH;

        return self::ATAS + $tinggi - ($nilai / $this->maks()) * $tinggi;
    }

    /**
     * Jalur garis, dipotong di setiap bulan yang nilainya tidak ada.
     * Menyambung lurus melewati bulan kosong akan menggambar tren yang tidak
     * pernah diukur.
     *
     * @return list<string>
     */
    public function segmen(): array
    {
        $segmen = [];
        $jalur = [];

        foreach ($this->titik as $i => $t) {
            if ($t['nilai'] === null) {
                if (count($jalur) > 1) {
                    $segmen[] = implode(' ', $jalur);
                }
                $jalur = [];

                continue;
            }

            $jalur[] = ($jalur === [] ? 'M' : 'L').$this->bulat($this->x($i)).' '.$this->bulat($this->y((float) $t['nilai']));
        }

        if (count($jalur) > 1) {
            $segmen[] = implode(' ', $jalur);
        }

        return $segmen;
    }

    /** @return list<array{x:float,y:float,teks:string}> */
    public function simpul(): array
    {
        $simpul = [];

        foreach ($this->titik as $i => $t) {
            if ($t['nilai'] === null) {
                continue;
            }

            $simpul[] = [
                'x' => $this->x($i),
                'y' => $this->y((float) $t['nilai']),
                'teks' => $t['label'].' · '.$t['teks'],
            ];
        }

        return $simpul;
    }

    /** @return list<array{x:float,teks:string}> */
    public function labelX(): array
    {
        $n = count($this->titik);
        $lompat = (int) max(1, ceil($n / self::LABEL_MAKS));
        $label = [];

        foreach ($this->titik as $i => $t) {
            if ($i % $lompat === 0 || $i === $n - 1) {
                $label[] = ['x' => $this->x($i), 'teks' => $t['label']];
            }
        }

        return $label;
    }

    /**
     * Pita acuan — digambar sebagai bidang berlabel langsung, bukan seri
     * kedua: warnanya bukan satu-satunya pembedanya.
     *
     * @return array{y:float,tinggi:float,labelY:float,label:string}|null
     */
    public function pitaKotak(): ?array
    {
        if ($this->pita === null) {
            return null;
        }

        $atas = $this->y((float) $this->pita['max']);
        $bawah = $this->y((float) $this->pita['min']);

        return [
            'y' => $atas,
            'tinggi' => max(2.0, $bawah - $atas),
            'labelY' => max(self::ATAS + 10, $atas - 5),
            'label' => $this->pita['label'],
        ];
    }

    public function viewBox(): string
    {
        return '0 0 '.self::W.' '.self::H;
    }

    public function kiri(): float
    {
        return self::KIRI;
    }

    public function kananX(): float
    {
        return self::W - self::KANAN;
    }

    public function labelY(): float
    {
        return self::H - 12;
    }

    private function bulat(float $n): float
    {
        return round($n, 2);
    }
}

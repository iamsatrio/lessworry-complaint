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

    /**
     * Lebar layar terkecil yang dijamin untuk kanvasnya, dalam piksel. Di
     * bawah ini kanvasnya digeser mendatar, tidak dikecilkan lagi — huruf
     * 5px bukan grafik. Sama dengan `min-width` di CSS `.fig svg`.
     */
    private const LEBAR_MIN = 560;

    /**
     * Ruang mendatar minimum per titik, dalam piksel LAYAR. Ini angka yang
     * membuat sasaran tunjuknya lolos: dua titik yang hanya berjarak 18px
     * tidak bisa punya sasaran 28px, berapa pun jari-jari yang ditulis.
     * Grafik yang titiknya rapat karena itu MELEBAR dan digeser mendatar,
     * bukan memampatkan sasarannya. (API-62 nomor 1)
     */
    private const RUANG_TITIK_MIN = 28;

    /** Garis tengah sasaran tunjuk yang dituju, dalam piksel LAYAR. */
    private const SASARAN_PX = 28;

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

    /** @return list<array{x:float,y:float,label:string,nilai:string,teks:string}> */
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
                'label' => $t['label'],
                'nilai' => $t['teks'],
                // Keterangan satu baris untuk <title>: cadangan pembaca layar,
                // dan satu-satunya keterangan yang tersisa kalau CSS gagal
                // dimuat. Tooltipnya sendiri memisahkan kedua bagian ini.
                'teks' => $t['label'].' · '.$t['teks'],
            ];
        }

        return $simpul;
    }

    /* ---------- Sasaran tunjuk dan tooltip (API-62 nomor 1) ---------- */

    /**
     * Lebar terkecil kanvasnya di layar, dalam piksel.
     *
     * Bukan angka tetap: ia tumbuh bersama jumlah titik supaya setiap titik
     * selalu kebagian RUANG_TITIK_MIN piksel layar. Grafik 31 titik harian di
     * kanvas 560px hanya punya 18px per titik — sasaran 28px di situ mustahil
     * secara aritmetika, bukan karena salah tulis. Yang mengalah lebarnya:
     * kanvasnya melebar dan digeser mendatar, sasarannya tidak dipampatkan.
     */
    public function lebarMin(): int
    {
        $n = count($this->titik);

        if ($n <= 1) {
            return self::LEBAR_MIN;
        }

        // Jarak antar titik di layar = jarak dalam satuan viewBox × skala,
        // dan skalanya lebar layar dibagi W. Dibalik: lebar layar terkecil
        // yang membuat jarak itu ≥ RUANG_TITIK_MIN. Satu piksel ditambahkan
        // supaya pembulatan jari-jarinya ke dua angka desimal tidak menggerus
        // hasilnya kembali ke bawah ambang.
        $butuh = (int) ceil(
            self::RUANG_TITIK_MIN * self::W * ($n - 1) / (self::W - self::KIRI - self::KANAN)
        ) + 1;

        return max(self::LEBAR_MIN, $butuh);
    }

    /**
     * Jari-jari sasaran tunjuk tak terlihat, dalam satuan viewBox.
     *
     * Satuan viewBox bukan piksel: SVG-nya diregangkan ke lebar kartu, jadi
     * satu satuan viewBox bernilai `lebarLayar / W` piksel. Yang dijamin di
     * sini garis tengah ≥ SASARAN_PX pada skala TERKECIL — yaitu saat
     * kanvasnya selebar lebarMin(). Di layar lebar sasarannya ikut membesar,
     * dan itu tidak merugikan siapa pun: lingkarannya tak terlihat.
     *
     * Batas keduanya jarak antar titik: sasaran yang lebih lebar dari jarak
     * antar titik saling menimpa, dan yang menang jadi tetangga sebelah —
     * menunjuk Agustus lalu terbaca September. lebarMin() sudah membuat kedua
     * batas ini bisa dipenuhi sekaligus.
     */
    public function jariSasaran(): float
    {
        $skalaTerkecil = $this->lebarMin() / self::W;

        // Dibulatkan KE ATAS, bukan ke terdekat: pembulatan ke bawah sebesar
        // 0,005 satuan sudah cukup membuat garis tengahnya 27,99px, dan
        // ambang yang meleset sepersepuluh piksel tetap ambang yang meleset.
        $butuh = ceil((self::SASARAN_PX / 2) / $skalaTerkecil * 100) / 100;

        $n = count($this->titik);

        if ($n <= 1) {
            return $butuh;
        }

        // lebarMin() sudah menjamin jarak antar titik ≥ SASARAN_PX di layar,
        // jadi batas ini praktis tidak pernah menggigit — ia berjaga kalau
        // salah satu angka di atas kelak diubah tanpa yang lain ikut.
        $jarak = (self::W - self::KIRI - self::KANAN) / ($n - 1);

        return min($butuh, floor($jarak / 2 * 100) / 100);
    }

    /**
     * Kotak keterangan yang muncul saat titiknya ditunjuk — muncul SEKETIKA
     * dan bergaya halaman, bukan tooltip sistem operasi yang tertunda sedetik.
     *
     * Digambar di server bersama grafiknya dan ditampilkan CSS `:hover`, jadi
     * tetap tanpa satu baris skrip. Lebarnya ditaksir dari jumlah huruf: SVG
     * tidak bisa mengukur teks sebelum digambar, dan taksiran yang sedikit
     * kelebihan hanya menyisakan ruang kosong di ujung kotaknya.
     *
     * @return array{x:float,y:float,lebar:float,tinggi:float,labelY:float,nilaiY:float,teksX:float,ekor:string}
     */
    public function tooltip(float $x, float $y, string $label, string $nilai): array
    {
        $lebar = max(96.0, round(max(mb_strlen($label), mb_strlen($nilai)) * 6.9 + 26, 2));
        $tinggi = 48.0;

        // Di atas titiknya kalau muat; kalau tidak, di bawahnya. Kotak yang
        // terpotong tepi atas gambar tidak menjelaskan apa pun.
        $atas = $y - 13 - $tinggi;
        $diAtas = $atas >= 2;
        $kotakY = $diAtas ? $atas : $y + 13;

        // Digeser ke dalam supaya tidak terpotong tepi kiri/kanan gambar.
        $kotakX = min(max($x - $lebar / 2, 4.0), self::W - 4 - $lebar);

        // Ekor segitiga tetap menunjuk titiknya walau kotaknya sudah digeser.
        $ekorX = min(max($x, $kotakX + 12), $kotakX + $lebar - 12);
        $ekor = $diAtas
            ? ($ekorX - 6).','.($kotakY + $tinggi).' '.($ekorX + 6).','.($kotakY + $tinggi).' '.$ekorX.','.($y - 4)
            : ($ekorX - 6).','.$kotakY.' '.($ekorX + 6).','.$kotakY.' '.$ekorX.','.($y + 4);

        return [
            'x' => round($kotakX, 2),
            'y' => round($kotakY, 2),
            'lebar' => $lebar,
            'tinggi' => $tinggi,
            'labelY' => round($kotakY + 20, 2),
            'nilaiY' => round($kotakY + 37, 2),
            'teksX' => round($kotakX + 13, 2),
            'ekor' => $ekor,
        ];
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

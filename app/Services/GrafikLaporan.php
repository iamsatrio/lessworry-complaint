<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Angka untuk keempat grafik halaman Laporan. (API-52)
 *
 * Semuanya dihitung dari koleksi complaint YANG SAMA dengan tabel di halaman
 * itu — koleksi yang sudah melewati `visibleTo()` dan rentang tanggal. Grafik
 * tidak boleh punya jalur pengambilan data sendiri: kalau punya, kebocoran
 * wewenang di grafik tidak akan terlihat karena tabelnya tetap benar.
 *
 * Satu-satunya kueri tambahan di sini — bulan complaint pertama tiap outlet —
 * juga lewat `visibleTo()`, karena jumlah outlet itu sendiri adalah informasi:
 * kasir tidak boleh menyimpulkan ada berapa outlet dari pembagi grafiknya.
 */
final class GrafikLaporan
{
    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Kategori yang menanggung sebagian besar biaya dan punya grafiknya sendiri. */
    private const KATEGORI_SOROTAN = 'barang_rusak';

    /** @var list<string>|null */
    private ?array $sumbu = null;

    /** @var list<string>|null bulan complaint pertama tiap outlet, 'Y-m' */
    private ?array $mulaiOutlet = null;

    /** @var list<array{bulan:string,label:string,complaint:int,rusak:int,outlet:int,per:float|null,perRusak:float|null}>|null */
    private ?array $bulanan = null;

    /**
     * @param  EloquentCollection<int,Complaint>  $complaints  sudah disaring wewenang dan rentang tanggal
     */
    public function __construct(
        private readonly User $user,
        private readonly EloquentCollection $complaints,
    ) {}

    /* ---------- Grafik 1 dan 3: per outlet per bulan ---------- */

    /**
     * Complaint per outlet per bulan, plus angka yang sama untuk Barang Rusak.
     *
     * JUMLAH MENTAH TIDAK DIPAKAI sebagai ukuran mutu. Jaringan tumbuh dari 5
     * ke 11 outlet; grafik yang menjumlah saja membaca setiap pembukaan outlet
     * baru sebagai penurunan mutu. April 2026 adalah bulan tertinggi secara
     * mentah dan bertepatan dengan pembukaan Jagakarsa — padahal per outlet
     * angkanya di bawah rata-rata 2025.
     *
     * @return list<array{bulan:string,label:string,complaint:int,rusak:int,outlet:int,per:float|null,perRusak:float|null}>
     */
    public function perBulan(): array
    {
        if ($this->bulanan !== null) {
            return $this->bulanan;
        }

        // Hanya complaint yang punya outlet yang masuk pembilang: keluhan yang
        // tidak bisa ditautkan ke satu outlet tidak bisa dibagi per outlet.
        // Jumlah yang dikeluarkan diumumkan lewat tanpaOutlet(), bukan diam.
        $berOutlet = $this->complaints
            ->filter(fn (Complaint $c) => $c->created_at !== null && $c->outlet_id !== null)
            ->groupBy(fn (Complaint $c) => $c->created_at->format('Y-m'));

        $baris = [];

        foreach ($this->sumbuBulan() as $bulan) {
            $isi = $berOutlet->get($bulan);
            $total = $isi?->count() ?? 0;
            $rusak = $isi?->where('category', self::KATEGORI_SOROTAN)->count() ?? 0;
            $outlet = $this->outletAktifPada($bulan);

            $baris[] = [
                'bulan' => $bulan,
                'label' => $this->labelBulan($bulan),
                'complaint' => $total,
                'rusak' => $rusak,
                'outlet' => $outlet,
                // Bulan tanpa satu pun outlet aktif bukan nol — angkanya TIDAK
                // ADA. Membaginya dengan nol, atau menggambarnya sebagai 0,
                // sama-sama mengarang.
                'per' => $outlet > 0 ? round($total / $outlet, 2) : null,
                'perRusak' => $outlet > 0 ? round($rusak / $outlet, 2) : null,
            ];
        }

        return $this->bulanan = $baris;
    }

    /**
     * Jumlah outlet yang SUDAH AKTIF pada bulan itu — bukan jumlah outlet
     * hari ini. Outlet yang belum buka tidak boleh ikut membagi.
     *
     * Aktif diturunkan dari bulan complaint pertamanya, karena tanggal buka
     * outlet tidak ada di sistem ini. Akibatnya outlet yang sudah buka tapi
     * belum pernah dikeluhkan tidak ikut membagi — angkanya jadi sedikit lebih
     * tinggi, bukan lebih rendah. Itu arah kesalahan yang aman: ia tidak
     * membuat mutu terlihat lebih baik daripada kenyataannya.
     */
    private function outletAktifPada(string $bulan): int
    {
        return count(array_filter(
            $this->mulaiOutlet(),
            fn (string $mulai) => $mulai <= $bulan,
        ));
    }

    /**
     * Bulan complaint pertama tiap outlet, dari SELURUH sejarah — bukan dari
     * rentang yang sedang dilihat. Kalau dibatasi rentang, memilih Juli–Agustus
     * membuat semua outlet seolah-olah baru mulai di bulan Juli.
     *
     * @return list<string>
     */
    private function mulaiOutlet(): array
    {
        if ($this->mulaiOutlet !== null) {
            return $this->mulaiOutlet;
        }

        $baris = Complaint::query()
            ->visibleTo($this->user)
            ->whereNotNull('outlet_id')
            ->selectRaw('outlet_id, MIN(created_at) as mulai')
            ->groupBy('outlet_id')
            ->get();

        $mulai = [];

        foreach ($baris as $row) {
            $nilai = $row->getAttribute('mulai');

            if (blank($nilai)) {
                continue;
            }

            $mulai[] = Carbon::parse((string) $nilai)->format('Y-m');
        }

        return $this->mulaiOutlet = $mulai;
    }

    /* ---------- Grafik 2: biaya per kategori ---------- */

    /**
     * Biaya per kategori, terurut menurun. Satu ukuran yang digambar (biaya);
     * jumlah kasus jadi label, bukan batang kedua.
     *
     * `terisi` bukan hiasan: total biaya tanpa cakupannya menyesatkan, karena
     * kolom biaya terisi 96% pada 2025 dan 38% pada 2026 — yang turun
     * pencatatannya, bukan biayanya.
     *
     * @return list<array{kategori:string,label:string,kasus:int,terisi:int,biaya:int,rata:int|null}>
     */
    public function biayaPerKategori(): array
    {
        $baris = $this->complaints
            ->groupBy('category')
            ->map(function ($isi, $kategori) {
                $bernilai = $isi->filter(fn (Complaint $c) => $this->punyaNilai($c));

                return [
                    'kategori' => (string) $kategori,
                    'label' => (string) config('complaint.categories.'.$kategori.'.label', $kategori),
                    'kasus' => $isi->count(),
                    'terisi' => $bernilai->count(),
                    'biaya' => (int) $bernilai->sum('compensation_amount'),
                    // Rata-rata dihitung dari yang PUNYA nilai saja. Kalau
                    // complaint tanpa nilai ikut jadi pembagi, rata-ratanya
                    // turun setiap kali ada orang yang lupa mengisi kolom.
                    'rata' => $bernilai->isEmpty()
                        ? null
                        : (int) round($bernilai->sum('compensation_amount') / $bernilai->count()),
                ];
            })
            ->sortByDesc('biaya')
            ->values()
            ->all();

        /** @var list<array{kategori:string,label:string,kasus:int,terisi:int,biaya:int,rata:int|null}> $baris */
        return $baris;
    }

    /* ---------- Grafik 4: median waktu penyelesaian ---------- */

    /**
     * Median waktu penyelesaian per bulan, dalam hari.
     *
     * Median, bukan rata-rata: satu kasus 41 hari menarik rata-rata dan
     * membuat bulan yang baik terlihat buruk.
     *
     * Dikelompokkan menurut bulan MASUKNYA complaint, bukan bulan selesainya,
     * supaya sumbu mendatarnya sama persis dengan dua grafik di atasnya —
     * rentang tanggal halaman ini menyaring `created_at`.
     *
     * @return list<array{bulan:string,label:string,median:float|null,n:int}>
     */
    public function medianPenyelesaian(): array
    {
        $selesai = $this->complaints
            ->filter(fn (Complaint $c) => $c->created_at !== null && $c->resolutionMinutes() !== null)
            ->groupBy(fn (Complaint $c) => $c->created_at->format('Y-m'));

        $baris = [];

        foreach ($this->sumbuBulan() as $bulan) {
            $isi = $selesai->get($bulan);

            $hari = $isi === null
                ? []
                : $isi->map(fn (Complaint $c) => ((int) $c->resolutionMinutes()) / 1440)->values()->all();

            $baris[] = [
                'bulan' => $bulan,
                'label' => $this->labelBulan($bulan),
                'median' => $hari === [] ? null : round($this->median($hari), 2),
                'n' => count($hari),
            ];
        }

        return $baris;
    }

    /**
     * Ambang SLA penyelesaian, dalam hari. Tiga angka menurut bobot — jadi
     * yang digambar rentangnya, bukan satu garis: median di grafik itu
     * mencampur ketiga bobot, dan satu garis tunggal akan menyiratkan seluruh
     * seri diukur terhadap ambang yang sama.
     *
     * @return array{min:int,max:int}
     */
    public function ambangSla(): array
    {
        /** @var array<string,int> $hari */
        $hari = config('complaint.sla.resolution_days', []);

        return ['min' => (int) min($hari), 'max' => (int) max($hari)];
    }

    /* ---------- Cakupan biaya ---------- */

    /**
     * Berapa banyak complaint pada periode ini yang benar-benar punya nilai
     * biaya. Wajib menyertai SETIAP total biaya yang ditampilkan. (API-52)
     *
     * @return array{terisi:int,total:int,biaya:int,persen:int|null,rendah:bool}
     */
    public function cakupanBiaya(): array
    {
        $total = $this->complaints->count();
        $bernilai = $this->complaints->filter(fn (Complaint $c) => $this->punyaNilai($c));
        $persen = $total === 0 ? null : (int) round($bernilai->count() / $total * 100);

        return [
            'terisi' => $bernilai->count(),
            'total' => $total,
            'biaya' => (int) $bernilai->sum('compensation_amount'),
            'persen' => $persen,
            'rendah' => $persen !== null && $persen < 50,
        ];
    }

    /**
     * Complaint ini punya nilai biaya yang benar-benar dicatat?
     *
     * Kolomnya `unsignedBigInteger default 0` dan tidak nullable, jadi basis
     * data TIDAK BISA membedakan "kompensasi Rp 0" dari "tidak pernah diisi" —
     * impor data lama pun menulis 0 untuk sel kosong. Selama kolomnya masih
     * begitu, nol dibaca sebagai tidak tercatat, dan complaint tersebut tidak
     * ikut dihitung dalam rata-rata maupun cakupan. Menghitungnya sebagai
     * Rp 0 akan menarik turun setiap rata-rata biaya dengan angka yang tidak
     * pernah ada orang yang mencatatnya.
     */
    private function punyaNilai(Complaint $complaint): bool
    {
        return (int) $complaint->compensation_amount > 0;
    }

    /** Complaint pada periode ini yang tidak punya outlet — tidak masuk grafik 1 dan 3. */
    public function tanpaOutlet(): int
    {
        return $this->complaints->whereNull('outlet_id')->count();
    }

    /* ---------- Bentuk siap gambar ---------- */

    /**
     * Titik grafik 1. Keterangan tiap titik menyebut kedua angka penyusunnya —
     * pembagi yang tidak kelihatan adalah pembagi yang tidak bisa diperiksa.
     *
     * @return list<array{label:string,nilai:float|null,teks:string}>
     */
    public function titikPerOutlet(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'nilai' => $b['per'],
            'teks' => $b['per'] === null
                ? 'belum ada outlet aktif'
                : $this->desimal($b['per']).' per outlet ('.$b['complaint'].' complaint, '.$b['outlet'].' outlet)',
        ], $this->perBulan());
    }

    /**
     * Titik grafik 3 — kategori yang menanggung sebagian besar biaya, dengan
     * pembagi yang sama seperti grafik 1.
     *
     * @return list<array{label:string,nilai:float|null,teks:string}>
     */
    public function titikBarangRusak(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'nilai' => $b['perRusak'],
            'teks' => $b['perRusak'] === null
                ? 'belum ada outlet aktif'
                : $this->desimal($b['perRusak']).' per outlet ('.$b['rusak'].' kasus, '.$b['outlet'].' outlet)',
        ], $this->perBulan());
    }

    /** @return list<array{label:string,nilai:float|null,teks:string}> */
    public function titikMedian(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'nilai' => $b['median'],
            'teks' => $b['median'] === null
                ? 'belum ada complaint yang selesai'
                : $this->desimal($b['median']).' hari (median dari '.$b['n'].' complaint selesai)',
        ], $this->medianPenyelesaian());
    }

    /**
     * Batang grafik 2. Jumlah kasus ikut sebagai LABEL, bukan batang kedua.
     *
     * @return list<array{label:string,nilai:float,teks:string,judul:string}>
     */
    public function batangBiaya(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'nilai' => (float) $b['biaya'],
            'teks' => self::rupiah($b['biaya']).' · '.$b['kasus'].' kasus',
            'judul' => $b['label'].' · '.self::rupiah($b['biaya']).' · '.$b['kasus'].' kasus · '
                .$this->cakupanTeks($b['terisi'], $b['kasus']),
        ], $this->biayaPerKategori());
    }

    /** Cakupan yang wajib menempel pada setiap total biaya. */
    public function cakupanTeks(int $terisi, int $total): string
    {
        return 'dari '.$terisi.' dari '.$total.' complaint yang punya nilai biaya';
    }

    public static function rupiah(int $nilai): string
    {
        return 'Rp '.number_format($nilai, 0, ',', '.');
    }

    public function desimal(float $nilai): string
    {
        return number_format($nilai, $nilai == (int) $nilai ? 0 : 1, ',', '.');
    }

    /* ---------- Sumbu waktu ---------- */

    /**
     * Bulan-bulan yang digambar: dari bulan complaint pertama sampai bulan
     * complaint terakhir DI DALAM rentang yang dipilih, tanpa bolong.
     *
     * Bulan kosong di tengah tetap digambar — nol complaint sebulan itu
     * informasi. Bulan kosong di ujung tidak, supaya rentang setahun yang
     * datanya cuma dua bulan tidak menghasilkan grafik yang 83% ruang mati.
     *
     * @return list<string>
     */
    private function sumbuBulan(): array
    {
        if ($this->sumbu !== null) {
            return $this->sumbu;
        }

        $kunci = $this->complaints
            ->filter(fn (Complaint $c) => $c->created_at !== null)
            ->map(fn (Complaint $c) => $c->created_at->format('Y-m'))
            ->unique()->sort()->values();

        if ($kunci->isEmpty()) {
            return $this->sumbu = [];
        }

        $bulan = Carbon::parse(((string) $kunci->first()).'-01')->startOfMonth();
        $akhir = Carbon::parse(((string) $kunci->last()).'-01')->startOfMonth();
        $sumbu = [];

        while ($bulan->lte($akhir)) {
            $sumbu[] = $bulan->format('Y-m');
            $bulan = $bulan->copy()->addMonth();
        }

        return $this->sumbu = $sumbu;
    }

    private function labelBulan(string $bulan): string
    {
        [$tahun, $ke] = explode('-', $bulan);

        return self::BULAN[((int) $ke) - 1].' '.substr($tahun, 2);
    }

    /** @param  list<float>  $nilai */
    private function median(array $nilai): float
    {
        sort($nilai);
        $n = count($nilai);
        $tengah = intdiv($n, 2);

        return $n % 2 === 1
            ? $nilai[$tengah]
            : ($nilai[$tengah - 1] + $nilai[$tengah]) / 2;
    }
}

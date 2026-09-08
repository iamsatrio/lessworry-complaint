<?php

namespace App\Services;

/**
 * Laporan hasil impor. (API-28 bagian 4)
 *
 * Keluaran perintah impor bukan kata "berhasil" — melainkan berkas ini.
 * Angka-angka di dalamnya adalah alasan impor ini dikerjakan sama sekali:
 * setiap nilai yang tidak punya padanan adalah kandidat cacat model data,
 * dan setiap kolom yang jarang terisi adalah bukti untuk keputusan yang
 * sedang menunggu.
 *
 * Isinya ANGKA, bukan baris. Tidak ada nama pelanggan, nomor telepon, atau
 * uraian keluhan yang ikut keluar dari sini: laporan ini dibaca di terminal,
 * disimpan sebagai berkas, dan ditempel ke issue.
 */
class LaporanImpor
{
    /** Ambang API-24: di bawah ini fitur pelacakan pelaku dicabut. */
    public const AMBANG_PELAKU = 25.0;

    public int $totalBaris = 0;

    public int $masuk = 0;

    public int $dilewati = 0;

    /**
     * Dilewati karena lebih tua dari tanggal potong.
     *
     * Punya barisnya sendiri di laporan, terpisah dari `dilewati`: keduanya
     * sama-sama "tidak masuk", tapi yang satu berarti sudah ada dan yang lain
     * berarti sengaja tidak diambil. Tanpa angka ini, orang yang membaca
     * "88 masuk" dari berkas 545 baris akan mengira berkasnya memang hanya
     * berisi 88.
     */
    public int $dilewatiTua = 0;

    /** @var list<array{baris:int,alasan:string}> */
    public array $gagal = [];

    /** @var array<string,array<string,int>> */
    public array $anomali = [];

    /** @var array<string,int> */
    public array $nota = ['kosong' => 0, 'angka' => 0, 'angka_bulan' => 0, 'angka_sub' => 0, 'tidak_terbaca' => 0];

    public int $pelakuTotal = 0;

    /** Baris yang lolos tanggal potong — denominator setiap angka keputusan. */
    public int $barisDiimpor = 0;

    public int $pelakuDiimpor = 0;

    public int $qiTotal = 0;

    public int $qiDiimpor = 0;

    /** @var array<string,int> */
    public array $sebaranCsv = [];

    /** @var array<string,int> */
    public array $sebaranDb = [];

    /** @var array<string,int> */
    public array $keanehan = [];

    /**
     * Biaya tercatat dan jumlah baris, dipecah menurut era NEVIRA.
     *
     * satrio ingin melihat besaran kerugian complaint SEJAK AWAL. Angka itu
     * berasal dari kolom biaya pada complaint-nya sendiri, bukan dari
     * transaksinya — jadi era pra-NEVIRA tetap terhitung penuh meski ordernya
     * tidak bisa ditautkan. Dipecah supaya dua era yang tidak sepadan tidak
     * diam-diam dijumlahkan jadi satu angka tanpa keterangan. (API-28)
     *
     * @var array<string,array{baris:int,biaya:int}>
     */
    public array $era = [
        'pra' => ['baris' => 0, 'biaya' => 0],
        'sejak' => ['baris' => 0, 'biaya' => 0],
    ];

    public function __construct(
        public readonly string $sumber,
        public readonly string $berkas,
        public readonly bool $kering,
        /** Tanggal potong yang berlaku, `YYYY-MM-DD`. Null berarti tanpa batas. */
        public readonly ?string $sejak = null,
    ) {}

    public function catatAnomali(string $kolom, string $alasan): void
    {
        $this->anomali[$kolom][$alasan] = ($this->anomali[$kolom][$alasan] ?? 0) + 1;
    }

    public function catatKeanehan(string $label): void
    {
        $this->keanehan[$label] = ($this->keanehan[$label] ?? 0) + 1;
    }

    public function catatEra(bool $praNevira, int $biaya): void
    {
        $kunci = $praNevira ? 'pra' : 'sejak';

        $this->era[$kunci]['baris']++;
        $this->era[$kunci]['biaya'] += $biaya;
    }

    /**
     * Bentuk nomor nota. Tidak satu pun dari lima bentuk ini boleh masuk ke
     * `nevira_transaction_id` — angka pendeknya tidak unik.
     */
    public function catatNota(string $mentah): void
    {
        $bentuk = match (true) {
            $mentah === '' => 'kosong',
            (bool) preg_match('/^\d{1,6}$/', $mentah) => 'angka',
            (bool) preg_match('/^\d+\s*\(.+\)$/', $mentah) => 'angka_bulan',
            (bool) preg_match('#^\d+/\d+$#', $mentah) => 'angka_sub',
            default => 'tidak_terbaca',
        };

        $this->nota[$bentuk]++;
    }

    /**
     * Dihitung atas baris yang DIIMPOR, bukan atas seluruh berkas: yang
     * ditanyakan adalah berapa banyak complaint di dalam sistem yang tidak
     * punya nomor nota terpakai.
     */
    public function persenNotaTakTerpakai(): float
    {
        return $this->barisDiimpor === 0
            ? 0.0
            : ($this->nota['kosong'] + $this->nota['tidak_terbaca']) / $this->barisDiimpor * 100;
    }

    public function persenPelaku(): float
    {
        return $this->barisDiimpor === 0 ? 0.0 : $this->pelakuDiimpor / $this->barisDiimpor * 100;
    }

    public function render(string $waktu): string
    {
        return implode("\n", [
            '# Laporan impor complaint historis',
            '',
            '- Sumber: `'.$this->sumber.'`',
            '- Berkas: `'.basename($this->berkas).'`',
            '- Dijalankan: '.$waktu,
            '- Mode: '.($this->kering ? '**dry-run** — tidak ada satu baris pun ditulis' : 'tulis'),
            '- Tanggal potong: '.($this->sejak === null
                ? 'tidak ada — seluruh berkas diimpor'
                : '`'.$this->sejak.'` (inklusif; baris lebih tua dilewati)'),
            '',
            ...$this->bagianBaris(),
            ...$this->bagianEnum(),
            ...$this->bagianNota(),
            ...$this->bagianPelaku(),
            ...$this->bagianSebaran(),
            ...$this->bagianEra(),
            ...$this->bagianKeanehan(),
        ])."\n";
    }

    /* ---------- bagian ---------- */

    /** @return list<string> */
    private function bagianBaris(): array
    {
        $baris = [
            '## 1. Baris',
            '',
            '| | Jumlah |',
            '|---|---|',
            '| Dibaca dari berkas | '.$this->totalBaris.' |',
            '| Dilewati (lebih tua dari tanggal potong) | '.$this->dilewatiTua.' |',
            '| Lolos tanggal potong | '.$this->barisDiimpor.' |',
            '| '.($this->kering ? 'Akan masuk' : 'Masuk').' | '.$this->masuk.' |',
            '| Dilewati (sudah ada dari impor sebelumnya) | '.$this->dilewati.' |',
            '| Gagal | '.count($this->gagal).' |',
            '',
            'Angka di bagian 2–7 dihitung atas baris yang **lolos tanggal potong**, '
                .'bukan atas seluruh berkas.',
            '',
        ];

        if ($this->gagal === []) {
            return [...$baris, 'Tidak ada baris yang gagal.', ''];
        }

        // Nomor baris dan jenis galatnya saja. Isi barisnya TIDAK ikut, dan
        // itu ditegakkan di sumbernya: ImporComplaint tidak pernah memanggil
        // getMessage(), yang pada QueryException memuat seluruh nilai baris.
        // (Review PR #7, P1-2)
        $baris[] = 'Alasan tiap kegagalan — nomor baris dan jenis galat saja, isinya tidak dikutip:';
        $baris[] = '';

        foreach ($this->gagal as $g) {
            $baris[] = '- baris '.$g['baris'].': '.$g['alasan'];
        }

        return [...$baris, ''];
    }

    /** @return list<string> */
    private function bagianEnum(): array
    {
        $baris = [
            '## 2. Nilai yang tidak punya padanan di enum',
            '',
            'Setiap satu adalah kandidat nilai yang kurang di sistem, bukan sekadar data kotor.',
            '',
        ];

        if ($this->anomali === []) {
            return [...$baris, 'Tidak ada.', ''];
        }

        $baris[] = '| Kolom | Alasan | Jumlah |';
        $baris[] = '|---|---|---|';

        foreach ($this->anomali as $kolom => $alasan) {
            arsort($alasan);

            foreach ($alasan as $teks => $jumlah) {
                $baris[] = '| '.$kolom.' | '.$teks.' | '.$jumlah.' |';
            }
        }

        return [...$baris, ''];
    }

    /** @return list<string> */
    private function bagianNota(): array
    {
        return [
            '## 3. Nomor nota',
            '',
            '| Bentuk | Jumlah |',
            '|---|---|',
            '| Angka polos | '.$this->nota['angka'].' |',
            '| Angka + nama bulan | '.$this->nota['angka_bulan'].' |',
            '| Angka/sub | '.$this->nota['angka_sub'].' |',
            '| Kosong | '.$this->nota['kosong'].' |',
            '| Tidak terbaca | '.$this->nota['tidak_terbaca'].' |',
            '',
            'Tanpa nomor nota yang terpakai: **'.$this->angka($this->persenNotaTakTerpakai()).'%** '
                .'('.$this->nota['kosong'].' kosong + '.$this->nota['tidak_terbaca'].' tidak terbaca '
                .'dari '.$this->barisDiimpor.' baris yang diimpor).',
            '',
            'Semuanya disimpan di `legacy_nota_number`. Tidak ada satu baris pun yang mengisi '
                .'`nevira_transaction_id`, dan NEVIRA tidak dipanggil sekali pun selama impor.',
            '',
        ];
    }

    /** @return list<string> */
    private function bagianPelaku(): array
    {
        $persen = $this->persenPelaku();

        return [
            '## 4. Pengisian kolom `Pelaku`',
            '',
            '| Rentang | Terisi | Baris | Porsi |',
            '|---|---|---|---|',
            '| **Diimpor** (lolos tanggal potong) | '.$this->pelakuDiimpor.' | '.$this->barisDiimpor.' | '
                .$this->angka($persen).'% |',
            '| Seluruh berkas | '.$this->pelakuTotal.' | '.$this->totalBaris.' | '
                .$this->angka($this->totalBaris === 0 ? 0 : $this->pelakuTotal / $this->totalBaris * 100).'% |',
            '',
            // Yang dipakai memutuskan adalah baris yang diimpor: itulah satu-
            // satunya riwayat yang akan dimiliki sistem. Angka seluruh berkas
            // ada sebagai pembanding, bukan sebagai dasar keputusan.
            'Ambang KB Landasan Produk (API-24): **'.$this->angka(self::AMBANG_PELAKU).'%**. '
                .'Angka baris yang diimpor '
                .($persen < self::AMBANG_PELAKU ? '**di bawah** ambang' : 'di atas ambang').'.',
            '',
            'Nilai `-` dihitung sebagai tidak terisi. Keputusan mencabut atau mempertahankan '
                .'fitur pelacakan pelaku ada di luar perintah ini.',
            '',
        ];
    }

    /** @return list<string> */
    private function bagianSebaran(): array
    {
        $baris = [
            '## 5. Sebaran per bulan',
            '',
            'Kalau kolom CSV dan basis data berbeda, ada baris yang hilang diam-diam.',
            '',
            '| Bulan | CSV | Basis data | Selisih |',
            '|---|---|---|---|',
        ];

        $bulan = array_unique([...array_keys($this->sebaranCsv), ...array_keys($this->sebaranDb)]);
        sort($bulan);
        $selisihTotal = 0;

        // Dry-run tidak membaca basis data sama sekali, jadi kolomnya `—`
        // dan tidak ada vonis cocok/tidak. Tabel yang menyatakan sesuatu yang
        // tidak diperiksa lebih buruk daripada tabel yang mengaku tidak tahu.
        // (Review PR #7, P3-1)
        $diperiksa = ! $this->kering;

        foreach ($bulan as $b) {
            $csv = $this->sebaranCsv[$b] ?? 0;
            $db = $this->sebaranDb[$b] ?? 0;
            $selisihTotal += abs($csv - $db);
            $baris[] = $diperiksa
                ? '| '.$b.' | '.$csv.' | '.$db.' | '.($db - $csv).' |'
                : '| '.$b.' | '.$csv.' | — | — |';
        }

        $baris[] = $diperiksa
            ? '| **Total** | **'.array_sum($this->sebaranCsv).'** | **'
                .array_sum($this->sebaranDb).'** | **'
                .(array_sum($this->sebaranDb) - array_sum($this->sebaranCsv)).'** |'
            : '| **Total** | **'.array_sum($this->sebaranCsv).'** | **—** | **—** |';
        $baris[] = '';
        $baris[] = match (true) {
            ! $diperiksa => 'Belum dibandingkan: dry-run tidak membaca basis data.',
            $selisihTotal === 0 => 'Sebarannya cocok bulan per bulan.',
            default => '**Tidak cocok** — selisih mutlak '.$selisihTotal.' baris.',
        };

        return [...$baris, ''];
    }

    /** @return list<string> */
    private function bagianEra(): array
    {
        $pra = $this->era['pra'];
        $sejak = $this->era['sejak'];
        $totalBiaya = $pra['biaya'] + $sejak['biaya'];
        $totalBaris = $pra['baris'] + $sejak['baris'];

        return [
            '## 6. Biaya tercatat, dipecah menurut era NEVIRA',
            '',
            'Angka ini dari kolom biaya pada complaint-nya sendiri, **bukan** dari transaksi '
                .'NEVIRA — jadi era pra-NEVIRA terhitung penuh meski ordernya tidak bisa ditautkan.',
            '',
            '| Era | Baris | Biaya tercatat | Porsi biaya |',
            '|---|---|---|---|',
            '| Sebelum NEVIRA | '.$pra['baris'].' | Rp '.$this->rupiah($pra['biaya']).' | '
                .$this->angka($totalBiaya === 0 ? 0 : $pra['biaya'] / $totalBiaya * 100).'% |',
            '| Sejak NEVIRA | '.$sejak['baris'].' | Rp '.$this->rupiah($sejak['biaya']).' | '
                .$this->angka($totalBiaya === 0 ? 0 : $sejak['biaya'] / $totalBiaya * 100).'% |',
            '| **Total** | **'.$totalBaris.'** | **Rp '.$this->rupiah($totalBiaya).'** | **100,0%** |',
            '',
            'Dua era ini tidak sepadan — yang satu punya order untuk dirujuk, yang satu tidak. '
                .'Dipecah supaya keduanya tidak diam-diam dijumlahkan jadi satu angka tanpa keterangan.',
            '',
        ];
    }

    /** @return list<string> */
    private function bagianKeanehan(): array
    {
        $baris = [
            '## 7. Keanehan yang perlu keputusan orang',
            '',
            'Diimpor apa adanya. Tidak ada satu pun yang dirapikan diam-diam — merapikannya '
                .'di sini akan menyembunyikan bahwa datanya memang begitu.',
            '',
        ];

        if ($this->keanehan === []) {
            return [...$baris, 'Tidak ada.', ''];
        }

        $baris[] = '| Keanehan | Jumlah |';
        $baris[] = '|---|---|';

        arsort($this->keanehan);

        foreach ($this->keanehan as $label => $jumlah) {
            $baris[] = '| '.$label.' | '.$jumlah.' |';
        }

        return [...$baris, ''];
    }

    private function angka(float $nilai): string
    {
        return number_format($nilai, 1, ',', '.');
    }

    private function rupiah(int $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }
}

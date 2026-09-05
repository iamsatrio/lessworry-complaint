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

    /** @var list<array{baris:int,alasan:string}> */
    public array $gagal = [];

    /** @var array<string,array<string,int>> */
    public array $anomali = [];

    /** @var array<string,int> */
    public array $nota = ['kosong' => 0, 'angka' => 0, 'angka_bulan' => 0, 'angka_sub' => 0, 'tidak_terbaca' => 0];

    public int $pelakuTotal = 0;

    public int $pelaku2026 = 0;

    public int $baris2026 = 0;

    public int $qiTotal = 0;

    public int $qi2026 = 0;

    /** @var array<string,int> */
    public array $sebaranCsv = [];

    /** @var array<string,int> */
    public array $sebaranDb = [];

    /** @var array<string,int> */
    public array $keanehan = [];

    public function __construct(
        public readonly string $sumber,
        public readonly string $berkas,
        public readonly bool $kering,
    ) {}

    public function catatAnomali(string $kolom, string $alasan): void
    {
        $this->anomali[$kolom][$alasan] = ($this->anomali[$kolom][$alasan] ?? 0) + 1;
    }

    public function catatKeanehan(string $label): void
    {
        $this->keanehan[$label] = ($this->keanehan[$label] ?? 0) + 1;
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

    public function persenNotaTakTerpakai(): float
    {
        return $this->totalBaris === 0
            ? 0.0
            : ($this->nota['kosong'] + $this->nota['tidak_terbaca']) / $this->totalBaris * 100;
    }

    public function persenPelaku2026(): float
    {
        return $this->baris2026 === 0 ? 0.0 : $this->pelaku2026 / $this->baris2026 * 100;
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
            '',
            ...$this->bagianBaris(),
            ...$this->bagianEnum(),
            ...$this->bagianNota(),
            ...$this->bagianPelaku(),
            ...$this->bagianSebaran(),
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
            '| '.($this->kering ? 'Akan masuk' : 'Masuk').' | '.$this->masuk.' |',
            '| Dilewati (sudah ada dari impor sebelumnya) | '.$this->dilewati.' |',
            '| Gagal | '.count($this->gagal).' |',
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
                .'('.$this->nota['kosong'].' kosong + '.$this->nota['tidak_terbaca'].' tidak terbaca).',
            '',
            'Semuanya disimpan di `legacy_nota_number`. Tidak ada satu baris pun yang mengisi '
                .'`nevira_transaction_id`, dan NEVIRA tidak dipanggil sekali pun selama impor.',
            '',
        ];
    }

    /** @return list<string> */
    private function bagianPelaku(): array
    {
        $persen = $this->persenPelaku2026();

        return [
            '## 4. Pengisian kolom `Pelaku`',
            '',
            '| Rentang | Terisi | Baris | Porsi |',
            '|---|---|---|---|',
            '| 2026 saja | '.$this->pelaku2026.' | '.$this->baris2026.' | '.$this->angka($persen).'% |',
            '| Seluruh data | '.$this->pelakuTotal.' | '.$this->totalBaris.' | '
                .$this->angka($this->totalBaris === 0 ? 0 : $this->pelakuTotal / $this->totalBaris * 100).'% |',
            '',
            'Ambang KB Landasan Produk (API-24): **'.$this->angka(self::AMBANG_PELAKU).'%**. '
                .'Angka 2026 '.($persen < self::AMBANG_PELAKU ? '**di bawah** ambang' : 'di atas ambang').'.',
            '',
            'Nilai `-` dihitung sebagai tidak terisi.',
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
    private function bagianKeanehan(): array
    {
        $baris = [
            '## 6. Keanehan yang perlu keputusan orang',
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
}

<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Services\JejakComplaint;
use App\Services\LaporanImpor;
use App\Services\PemetaBarisImpor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Impor complaint historis dari spreadsheet ke sistem. (API-28)
 *
 * Tiga sifat yang bukan kenyamanan, melainkan syarat:
 *
 * 1. **Menghitung dulu, menulis kemudian.** Tanpa `--tulis` perintah ini
 *    tidak menyentuh basis data sama sekali. Impor pertama ke sistem yang
 *    akan dipakai kasir tidak boleh bisa terjadi karena salah ketik.
 * 2. **Bisa diulang.** Pasangan `import_source` + `import_row` unik, dan
 *    baris yang sudah masuk dilewati. Menjalankan perintah ini dua kali
 *    tidak menggandakan satu baris pun.
 * 3. **Satu baris gagal tidak menghentikan sisanya.** Kegagalan dikumpulkan
 *    dan dilaporkan di akhir, bukan melempar 544 baris lain ke luar.
 *
 * NEVIRA tidak dipanggil sekali pun. Nomor nota data lama tidak unik;
 * menautkannya akan menempelkan keluhan ke order pelanggan lain.
 */
class ImporComplaint extends Command
{
    protected $signature = 'complaint:import
        {berkas : Berkas CSV hasil ekspor spreadsheet}
        {--tulis : Benar-benar menyimpan. Tanpa ini perintah hanya menghitung}
        {--dry-run : Paksa hanya menghitung, walau --tulis diberikan}
        {--sumber= : Penanda asal. Bawaannya nama berkas}
        {--laporan= : Tujuan berkas laporan. Bawaannya storage/app/impor}';

    protected $description = 'Impor complaint historis dari CSV, hitung dulu sebelum menulis';

    /**
     * Nomor tiket terakhir per tanggal, supaya penomoran nyambung antar-jalan.
     *
     * @var array<string,int>
     */
    private array $urutTiket = [];

    public function handle(PemetaBarisImpor $pemeta, JejakComplaint $jejak): int
    {
        $berkas = (string) $this->argument('berkas');

        if (! is_readable($berkas)) {
            $this->error('Berkas tidak terbaca: '.$berkas);

            return self::FAILURE;
        }

        $kering = ! $this->option('tulis') || (bool) $this->option('dry-run');
        $sumber = (string) ($this->option('sumber') ?: basename($berkas));

        $laporan = new LaporanImpor($sumber, $berkas, $kering);

        $baris = $this->baca($berkas);

        if ($baris === null) {
            return self::FAILURE;
        }

        foreach ($baris as $nomor => $isi) {
            $this->olah($isi, $nomor, $sumber, $kering, $pemeta, $jejak, $laporan);
        }

        $laporan->sebaranDb = $kering ? $laporan->sebaranCsv : $this->sebaranDb($sumber);

        $tujuan = $this->simpanLaporan($laporan);

        $this->line($laporan->render(now()->format('Y-m-d H:i:s')));
        $this->info('Laporan disimpan: '.$tujuan);

        if ($kering) {
            $this->warn('Mode hitung saja — tidak ada baris yang ditulis. Tambahkan --tulis untuk menyimpan.');
        }

        return $laporan->gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /* ---------- baca ---------- */

    /**
     * @return array<int,array<string,string>>|null nomor baris berkas => isi
     */
    private function baca(string $berkas): ?array
    {
        $fh = fopen($berkas, 'r');

        if ($fh === false) {
            $this->error('Berkas tidak bisa dibuka: '.$berkas);

            return null;
        }

        $judul = fgetcsv($fh, 0, ',', '"', '');

        if (! is_array($judul)) {
            fclose($fh);
            $this->error('Berkas kosong atau tanpa baris judul.');

            return null;
        }

        // BOM dari ekspor spreadsheet menempel pada nama kolom PERTAMA, dan
        // membuat pencarian kolom `Date` gagal untuk seluruh berkas.
        $judul = array_map(fn ($k) => trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $k)), $judul);

        $hasil = [];
        $nomor = 1;

        while (($data = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $nomor++;

            // Baris kosong di ujung berkas: fgetcsv mengembalikannya sebagai
            // satu kolom berisi null, bukan sebagai larik kosong.
            if ($data === [null]) {
                continue;
            }

            $isi = [];

            foreach ($judul as $i => $kolom) {
                $isi[$kolom] = (string) ($data[$i] ?? '');
            }

            $hasil[$nomor] = $isi;
        }

        fclose($fh);

        return $hasil;
    }

    /* ---------- olah satu baris ---------- */

    /** @param array<string,string> $isi */
    private function olah(
        array $isi,
        int $nomor,
        string $sumber,
        bool $kering,
        PemetaBarisImpor $pemeta,
        JejakComplaint $jejak,
        LaporanImpor $laporan,
    ): void {
        $laporan->totalBaris++;

        $hasil = $pemeta->petakan($isi);

        $this->hitungStatistik($isi, $pemeta, $laporan);

        if ($hasil['galat'] !== []) {
            $laporan->gagal[] = ['baris' => $nomor, 'alasan' => implode('; ', $hasil['galat'])];

            return;
        }

        foreach ($hasil['anomali'] as $a) {
            $laporan->catatAnomali($a['kolom'], $a['alasan']);
        }

        $this->hitungKeanehan($isi, $hasil, $laporan);

        /** @var Carbon $masuk */
        $masuk = $hasil['data']['created_at'];
        $laporan->sebaranCsv[$masuk->format('Y-m')] = ($laporan->sebaranCsv[$masuk->format('Y-m')] ?? 0) + 1;

        // Sudah pernah masuk: dilewati, tidak ditulis ulang. Ini yang membuat
        // perintah aman dijalankan dua kali.
        if (Complaint::where('import_source', $sumber)->where('import_row', $nomor)->exists()) {
            $laporan->dilewati++;

            return;
        }

        if ($kering) {
            $laporan->masuk++;

            return;
        }

        try {
            $this->simpan($hasil['data'], $nomor, $sumber, $pemeta->catatan($isi), $jejak);
            $laporan->masuk++;
        } catch (Throwable $e) {
            // Pesan galat tidak boleh membawa isi baris: di dalamnya ada nama
            // dan keluhan pelanggan, dan laporan ini ditempel ke issue.
            $laporan->gagal[] = ['baris' => $nomor, 'alasan' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /** @param array<string,mixed> $data */
    private function simpan(array $data, int $nomor, string $sumber, ?string $catatan, JejakComplaint $jejak): void
    {
        /** @var Carbon $masuk */
        $masuk = $data['created_at'];

        $complaint = new Complaint;
        $complaint->forceFill($data);
        $complaint->import_source = $sumber;
        $complaint->import_row = $nomor;
        $complaint->ticket_number = $this->nomorTiket($masuk);
        $complaint->updated_at = $data['resolved_at'] ?? $masuk;
        $complaint->applySla();
        $complaint->save();

        $jejak->diimpor($complaint, $sumber, $nomor);

        if ($catatan !== null) {
            $jejak->catatanImpor($complaint, $catatan);
        }
    }

    /**
     * Nomor tiket data lama berawalan `IMP-`, bukan `LW-`.
     *
     * Penomoran harian yang berjalan dipakai complaint yang benar-benar
     * dicatat hari itu. Menumpangkan 545 baris historis ke dalamnya akan
     * bertabrakan dengan tiket yang sudah ada — kolomnya unik, dan
     * penyimpanannya gagal.
     */
    private function nomorTiket(Carbon $tanggal): string
    {
        $prefix = 'IMP-'.$tanggal->format('Ymd');

        if (! isset($this->urutTiket[$prefix])) {
            $terakhir = Complaint::where('ticket_number', 'like', $prefix.'-%')
                ->orderByDesc('ticket_number')
                ->value('ticket_number');

            $this->urutTiket[$prefix] = $terakhir ? (int) substr($terakhir, -3) : 0;
        }

        $urut = ++$this->urutTiket[$prefix];

        return $prefix.'-'.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }

    /* ---------- statistik untuk laporan ---------- */

    /** @param array<string,string> $isi */
    private function hitungStatistik(array $isi, PemetaBarisImpor $pemeta, LaporanImpor $laporan): void
    {
        $laporan->catatNota(trim($isi['Nomor Nota'] ?? ''));

        $pelaku = trim($isi['Pelaku'] ?? '');
        $terisi = $pelaku !== '' && $pelaku !== '-';
        $tahun = $pemeta->tanggal(trim($isi['Date'] ?? ''))?->year;
        $qi = $pemeta->tipe($isi) === 'Quality Incident';

        $laporan->pelakuTotal += $terisi ? 1 : 0;
        $laporan->qiTotal += $qi ? 1 : 0;

        if ($tahun === 2026) {
            $laporan->baris2026++;
            $laporan->pelaku2026 += $terisi ? 1 : 0;
            $laporan->qi2026 += $qi ? 1 : 0;
        }
    }

    /**
     * Keanehan yang harus dilihat orang, bukan diselesaikan parser.
     *
     * @param  array<string,string>  $isi
     * @param  array{data:array<string,mixed>,anomali:list<array{kolom:string,alasan:string}>,galat:list<string>}  $hasil
     */
    private function hitungKeanehan(array $isi, array $hasil, LaporanImpor $laporan): void
    {
        $biaya = trim($isi['Tindak lanjut cost'] ?? '');

        if (preg_match('/^rp/i', $biaya)) {
            $laporan->catatKeanehan('Nilai kompensasi berawalan `Rp`');
        }

        // Rp207 bukan salah baca — memang tertulis begitu. Diimpor apa adanya;
        // menaikkannya jadi Rp207.000 berarti mengarang angka kompensasi.
        if ($hasil['data']['compensation_amount'] > 0 && $hasil['data']['compensation_amount'] < 1000) {
            $laporan->catatKeanehan('Kompensasi di bawah Rp1.000 (diimpor apa adanya)');
        }

        if ($hasil['data']['status'] === 'close' && $hasil['data']['resolved_at'] === null) {
            $laporan->catatKeanehan('Close tanpa tanggal tutup — waktu penyelesaian tidak diketahui');
        }

        if ($hasil['data']['legacy_outlet_name'] !== null) {
            $laporan->catatKeanehan('Outlet tanpa padanan: '.$hasil['data']['legacy_outlet_name']);
        }

        // `Tipe` tidak diimpor, tapi jumlahnya dicatat: kalau Quality Incident
        // naik lagi, itu buktinya, bukan perasaan.
        if (trim($isi['Tipe'] ?? '') === 'Quality Incident') {
            $laporan->catatKeanehan('Baris `Quality Incident` (tidak diimpor sebagai jenis tersendiri)');
        }

        foreach ($hasil['anomali'] as $a) {
            if ($a['kolom'] === 'Tindak lanjut cost') {
                $laporan->catatKeanehan('Kolom biaya: '.$a['alasan']);
            }
        }
    }

    /** @return array<string,int> */
    private function sebaranDb(string $sumber): array
    {
        $sebaran = Complaint::where('import_source', $sumber)
            ->pluck('created_at')
            ->countBy(fn (Carbon $t) => $t->format('Y-m'))
            ->all();

        ksort($sebaran);

        return $sebaran;
    }

    private function simpanLaporan(LaporanImpor $laporan): string
    {
        $tujuan = (string) ($this->option('laporan') ?: storage_path(
            'app/impor/'.now()->format('Ymd-His').'-'.($laporan->kering ? 'dry-run' : 'tulis').'.md'
        ));

        $folder = dirname($tujuan);

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        file_put_contents($tujuan, $laporan->render(now()->format('Y-m-d H:i:s')));

        return $tujuan;
    }
}

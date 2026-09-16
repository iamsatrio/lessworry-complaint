<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Services\BerkasMasukan;
use App\Services\PembacaCsvImpor;
use App\Services\PemetaBarisImpor;
use App\Services\TanggalPengambilan;
use Illuminate\Console\Command;

/**
 * Isi `tanggal_pengambilan` complaint yang SUDAH diimpor, dari kolom
 * `Cucian Diterima Cust` di berkas sumbernya. (API-48)
 *
 * Kenapa perintah tersendiri, bukan impor ulang: `complaint:import` sengaja
 * MELEWATI baris yang sudah pernah masuk — itu yang membuatnya aman
 * dijalankan dua kali. 84 baris yang punya tanggal pengambilan sudah telanjur
 * masuk sebelum kolomnya ada, dan impor ulang tidak akan menyentuhnya.
 *
 * Yang mempertemukan baris berkas dengan complaint-nya adalah sidik jari isi
 * baris — sidik jari yang sama persis dengan yang dipakai impor, karena
 * keduanya memakai PemetaBarisImpor dan PembacaCsvImpor yang sama.
 * `Cucian Diterima Cust` TIDAK ikut dihitung dalam sidik jari, jadi menambah
 * kolom ini tidak mengubah satu pun sidik jari yang sudah tersimpan.
 *
 * Tidak pernah menimpa tanggal yang sudah ada. Complaint yang tanggalnya
 * datang dari jejak NEVIRA lebih tahu daripada spreadsheet.
 */
class BackfillPengambilan extends Command
{
    protected $signature = 'complaint:backfill-pengambilan
        {berkas : Berkas CSV yang sama dengan yang dipakai complaint:import}
        {--tulis : Benar-benar menyimpan. Tanpa ini perintah hanya menghitung}';

    protected $description = 'Isi tanggal pengambilan complaint hasil impor dari kolom Cucian Diterima Cust';

    /**
     * Berapa kali sebuah sidik jari sudah muncul di berkas ini — persis
     * seperti di `complaint:import`, karena baris kedua yang isinya sama
     * disimpan dengan sidik jari berakhiran ':2'.
     *
     * @var array<string,int>
     */
    private array $urutSidik = [];

    public function handle(PemetaBarisImpor $pemeta): int
    {
        $berkas = new BerkasMasukan((string) $this->argument('berkas'));

        if (! $berkas->ada() || ! $berkas->bisaDibaca()) {
            $this->error('Berkas tidak ada atau tidak bisa dibaca: '.$berkas->absolut);

            return self::FAILURE;
        }

        $hasil = (new PembacaCsvImpor)->baca($berkas->absolut);

        if ($hasil['galat'] !== null) {
            $this->error($hasil['galat']);

            return self::FAILURE;
        }

        $this->urutSidik = [];
        $kering = ! $this->option('tulis');

        $punyaTanggal = 0;
        $terisi = 0;
        $tidakKetemu = 0;
        $sudahPunyaSumber = 0;
        $tidakTerbaca = 0;

        foreach ($hasil['baris'] as $isi) {
            $sidik = $this->sidikJari($isi, $pemeta);
            $mentah = trim($isi['Cucian Diterima Cust'] ?? '');

            if ($mentah === '') {
                continue;
            }

            $punyaTanggal++;
            $tanggal = $pemeta->tanggal($mentah);

            if ($tanggal === null) {
                $tidakTerbaca++;

                continue;
            }

            $complaint = Complaint::where('import_fingerprint', $sidik)->first();

            if (! $complaint) {
                $tidakKetemu++;

                continue;
            }

            // Sumber apa pun selain "tidak diketahui" berarti sudah ada yang
            // menjawab pertanyaan ini dengan bahan yang lebih baik.
            if ($complaint->sumber_tanggal_pengambilan !== TanggalPengambilan::TIDAK_DIKETAHUI) {
                $sudahPunyaSumber++;

                continue;
            }

            $terisi++;

            if (! $kering) {
                $complaint->forceFill([
                    'tanggal_pengambilan' => $tanggal,
                    'sumber_tanggal_pengambilan' => TanggalPengambilan::MANUAL,
                ])->save();
            }
        }

        $this->line('Baris berkas dengan Cucian Diterima Cust terisi : '.$punyaTanggal);
        $this->line('  tanggalnya tidak terbaca                       : '.$tidakTerbaca);
        $this->line('  complaint-nya tidak ditemukan di sistem        : '.$tidakKetemu);
        $this->line('  sudah punya tanggal dari sumber lain           : '.$sudahPunyaSumber);
        $this->line('  '.($kering ? 'akan diisi' : 'diisi').' dengan sumber manual              : '.$terisi);

        if ($kering) {
            $this->warn('Mode hitung saja — tidak ada baris yang ditulis. Tambahkan --tulis untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    /** @param  array<string,string>  $isi */
    private function sidikJari(array $isi, PemetaBarisImpor $pemeta): string
    {
        $sidik = $pemeta->sidikJari($isi);
        $urut = ($this->urutSidik[$sidik] ?? 0) + 1;
        $this->urutSidik[$sidik] = $urut;

        return $urut === 1 ? $sidik : $sidik.':'.$urut;
    }
}

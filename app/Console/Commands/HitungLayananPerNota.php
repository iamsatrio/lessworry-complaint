<?php

namespace App\Console\Commands;

use App\Services\LayananNota;
use App\Services\NeviraClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gerbang bukti untuk baris layanan NEVIRA. (API-51, dari API-49)
 *
 * Menjawab empat pertanyaan sekaligus, dari respons sungguhan — bukan dari
 * dokumen dan bukan dari tebakan:
 *
 *   0. Berapa banyak nota yang berisi lebih dari satu baris layanan?
 *      Menentukan seberapa sering pemilih "barang yang mana" muncul di form
 *      intake. Kalau mayoritas nota berisi satu baris, pemilih itu hampir
 *      tidak pernah terlihat.
 *   1. Apakah `services[].service.service_name` benar-benar ada?
 *      `docs/nevira-api.md` tidak menjaminnya.
 *   2. Kalau tidak — apa isi `service_number`: angka, kode, atau teks?
 *   3. Berapa nilai unik yang muncul, dan berapa yang bisa dipetakan ke
 *      enam nilai `config('complaint.layanan')`?
 *
 * HANYA MEMBACA. Yang dicetak hanya angka, nama layanan, dan kode layanan —
 * tidak ada nomor nota, nama pelanggan, atau nama karyawan yang keluar dari
 * perintah ini.
 */
class HitungLayananPerNota extends Command
{
    protected $signature = 'nevira:hitung-layanan {--jumlah=40 : Berapa transaksi terakhir yang diperiksa}';

    protected $description = 'Periksa baris layanan pada nota NEVIRA: berapa yang lebih dari satu, dan apakah nama layanannya ada';

    /** Berapa contoh nilai yang dicetak sebelum sisanya diringkas. */
    private const CONTOH = 8;

    public function handle(NeviraClient $nevira): int
    {
        if (! $nevira->isConfigured()) {
            $this->error('Kredensial NEVIRA belum diisi. Set NEVIRA_EMAIL dan NEVIRA_PASSWORD di .env');

            return self::FAILURE;
        }

        $jumlah = max(1, (int) $this->option('jumlah'));

        try {
            // Keyword kosong: daftar transaksi terakhir apa adanya.
            $daftar = $nevira->searchTransactions('', $jumlah);
        } catch (Throwable $e) {
            $this->error('Gagal mengambil daftar transaksi: '.$e->getMessage());

            return self::FAILURE;
        }

        $sebaran = [];
        $barisTotal = $barisBernama = $barisPunyaObjek = $gagal = 0;
        $nama = $kode = [];

        foreach ($daftar as $row) {
            $id = $row['id_transaction'] ?? null;

            if (blank($id)) {
                continue;
            }

            try {
                $baris = $this->barisLayanan($nevira->transaction((string) $id));
            } catch (Throwable) {
                // Satu transaksi yang tidak terbaca tidak membatalkan
                // pengukuran; jumlahnya dilaporkan di bawah.
                $gagal++;

                continue;
            }

            $n = count($baris);
            $sebaran[$n] = ($sebaran[$n] ?? 0) + 1;
            $barisTotal += $n;

            foreach ($baris as $b) {
                $barisPunyaObjek += is_array($b['service'] ?? null) ? 1 : 0;

                $n1 = $this->teks($b['service']['service_name'] ?? null);
                $n2 = $this->teks($b['service_number'] ?? null);

                if ($n1 !== null) {
                    $barisBernama++;
                    $nama[$n1] = ($nama[$n1] ?? 0) + 1;
                }

                if ($n2 !== null) {
                    $kode[$n2] = ($kode[$n2] ?? 0) + 1;
                }
            }
        }

        if (array_sum($sebaran) === 0) {
            $this->error('Tidak ada transaksi yang bisa dibaca.'.($gagal ? ' Gagal: '.$gagal.'.' : ''));

            return self::FAILURE;
        }

        $this->laporkanSebaran($sebaran, $gagal, $barisTotal);
        $this->laporkanNama($barisTotal, $barisBernama, $barisPunyaObjek);
        $this->laporkanKode($kode, $barisBernama);
        $this->laporkanPemetaan($nama !== [] ? $nama : $kode, $nama !== []);

        return self::SUCCESS;
    }

    /**
     * Baris layanan mentah dari satu payload transaksi.
     *
     * Sengaja TIDAK lewat summarizeTransaction(): yang diperiksa di sini
     * justru kunci aslinya, dan peringkasan sudah membuang bentuk aslinya.
     *
     * @param  array<string,mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function barisLayanan(array $payload): array
    {
        $d = $payload['data'] ?? $payload;

        if (isset($d[0]) && is_array($d[0])) {
            $d = $d[0];
        }

        $rows = $d['services'] ?? [];

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($s) => is_array($s))
            ->values()->all();
    }

    private function teks(mixed $nilai): ?string
    {
        return is_scalar($nilai) && filled($nilai) ? trim((string) $nilai) : null;
    }

    /** @param  array<int,int>  $sebaran */
    private function laporkanSebaran(array $sebaran, int $gagal, int $barisTotal): void
    {
        ksort($sebaran);
        $diperiksa = array_sum($sebaran);
        $banyak = collect($sebaran)->filter(fn ($v, $n) => $n > 1)->sum();

        $this->info('Berapa banyak nota berisi lebih dari satu baris layanan');
        $this->line('  Diperiksa       : '.$diperiksa.' nota terakhir, '.$barisTotal.' baris layanan'
            .($gagal ? ' ('.$gagal.' nota gagal dibaca)' : ''));
        $this->line('  Lebih dari satu : '.$banyak.' nota ('.round($banyak / $diperiksa * 100, 1).'%)');
        $this->newLine();
        $this->table(
            ['Baris layanan per nota', 'Jumlah nota'],
            collect($sebaran)->map(fn ($v, $n) => [$n, $v])->values()->all()
        );
    }

    private function laporkanNama(int $barisTotal, int $barisBernama, int $barisPunyaObjek): void
    {
        $this->newLine();
        $this->info('1. Apakah services[].service.service_name ada?');
        $this->line('  Baris yang punya objek `service`  : '.$barisPunyaObjek.' dari '.$barisTotal
            .' ('.$this->persen($barisPunyaObjek, $barisTotal).')');
        $this->line('  Baris yang punya `service_name`   : '.$barisBernama.' dari '.$barisTotal
            .' ('.$this->persen($barisBernama, $barisTotal).')');

        if ($barisBernama === 0) {
            $this->warn('  JAWABAN: TIDAK ADA. Pemilih barang tidak bisa memakai nama layanan.');
        } elseif ($barisBernama < $barisTotal) {
            $this->warn('  JAWABAN: ADA TAPI TIDAK SELALU. Sebagian baris jatuh ke nomor urut.');
        } else {
            $this->line('  JAWABAN: ADA di semua baris yang diperiksa.');
        }
    }

    /** @param  array<string,int>  $kode */
    private function laporkanKode(array $kode, int $barisBernama): void
    {
        $this->newLine();
        $this->info('2. Apa isi service_number?');

        if ($kode === []) {
            $this->line('  Tidak ada nilai `service_number` sama sekali pada baris yang diperiksa.');

            return;
        }

        $nilai = array_keys($kode);

        $this->line('  Bentuknya : '.$this->bentuk($nilai));
        $this->line('  Nilai unik: '.count($nilai));
        $this->line('  Contoh    : '.implode(' · ', array_slice($nilai, 0, self::CONTOH))
            .(count($nilai) > self::CONTOH ? ' … (+'.(count($nilai) - self::CONTOH).' lagi)' : ''));

        if ($barisBernama === 0) {
            $this->warn('  Karena nama layanan tidak ada, INI yang akan tampil kalau pemilih memakainya mentah.');
        }
    }

    /**
     * @param  array<string,int>  $nilai
     * @param  bool  $dariNama  nilai ini nama layanan, bukan kode cadangannya
     */
    private function laporkanPemetaan(array $nilai, bool $dariNama): void
    {
        $this->newLine();
        $this->info('3. Berapa nilai unik, dan berapa yang terpetakan ke config(complaint.layanan)?');

        if ($nilai === []) {
            $this->line('  Tidak ada nilai yang bisa dipetakan.');

            return;
        }

        arsort($nilai);
        $sumber = $dariNama ? 'service_name' : 'service_number (cadangan — nama tidak ada)';
        $this->line('  Sumber nilai: '.$sumber);
        $this->line('  Nilai unik  : '.count($nilai));

        $terpetakan = $gagal = [];

        foreach ($nilai as $v => $n) {
            $layanan = LayananNota::dariNama((string) $v);

            if ($layanan === null) {
                $gagal[] = [(string) $v, $n];
            } else {
                $terpetakan[] = [(string) $v, $n, $layanan];
            }
        }

        $this->line('  Terpetakan  : '.count($terpetakan).' nilai · TIDAK terpetakan: '.count($gagal).' nilai');

        if ($terpetakan !== []) {
            $this->newLine();
            $this->table(['Nilai NEVIRA', 'n baris', 'Jadi layanan'], array_slice($terpetakan, 0, 30));
        }

        if ($gagal !== []) {
            $this->newLine();
            $this->warn('  Belum punya pemetaan — perlu keputusan sebelum dipakai mengisi kolom layanan:');
            $this->table(['Nilai NEVIRA', 'n baris'], array_slice($gagal, 0, 30));
            $this->line('  Nilai yang tidak terpetakan TIDAK mengisi kolom layanan; kasir tetap memilih sendiri.');
        }
    }

    /** @param  array<int,string>  $nilai */
    private function bentuk(array $nilai): string
    {
        if (collect($nilai)->every(fn ($v) => ctype_digit($v))) {
            return 'angka murni — tidak terbaca sebagai nama barang';
        }

        if (collect($nilai)->contains(fn ($v) => str_contains($v, ' '))) {
            return 'teks berspasi — kemungkinan terbaca manusia';
        }

        return 'kode tanpa spasi — tidak terbaca sebagai nama barang';
    }

    private function persen(int $bagian, int $total): string
    {
        return $total === 0 ? '—' : round($bagian / $total * 100, 1).'%';
    }
}

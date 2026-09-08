<?php

namespace App\Console\Commands;

use App\Services\NeviraClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Berapa banyak nota yang berisi lebih dari satu baris layanan? (API-51)
 *
 * Pertanyaannya menentukan seberapa sering pilihan "barang yang mana" muncul
 * di form intake. Kalau mayoritas nota hanya berisi satu baris, pilihan itu
 * hampir tidak pernah terlihat dan kasir tidak kehilangan waktu. Kalau
 * mayoritasnya banyak, rancangannya perlu ditinjau lagi.
 *
 * HANYA MEMBACA. Yang dicetak cuma angka — tidak ada nomor nota, nama
 * pelanggan, atau nama karyawan yang keluar dari perintah ini.
 */
class HitungLayananPerNota extends Command
{
    protected $signature = 'nevira:hitung-layanan {--jumlah=40 : Berapa transaksi terakhir yang diperiksa}';

    protected $description = 'Hitung berapa banyak nota NEVIRA yang berisi lebih dari satu baris layanan';

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
        $gagal = 0;

        foreach ($daftar as $row) {
            $id = $row['id_transaction'] ?? null;

            if (blank($id)) {
                continue;
            }

            try {
                $summary = $nevira->summarizeTransaction($nevira->transaction((string) $id));
            } catch (Throwable) {
                // Satu transaksi yang tidak bisa dibaca tidak membatalkan
                // pengukuran; jumlahnya dilaporkan di bawah.
                $gagal++;

                continue;
            }

            $n = count($summary['services']);
            $sebaran[$n] = ($sebaran[$n] ?? 0) + 1;
        }

        $diperiksa = array_sum($sebaran);

        if ($diperiksa === 0) {
            $this->error('Tidak ada transaksi yang bisa dibaca.'.($gagal ? ' Gagal: '.$gagal.'.' : ''));

            return self::FAILURE;
        }

        ksort($sebaran);
        $banyak = collect($sebaran)->filter(fn ($v, $n) => $n > 1)->sum();

        $this->line('Diperiksa       : '.$diperiksa.' nota terakhir'.($gagal ? ' ('.$gagal.' gagal dibaca)' : ''));
        $this->line('Lebih dari satu : '.$banyak.' nota ('.round($banyak / $diperiksa * 100, 1).'%)');
        $this->newLine();
        $this->table(
            ['Baris layanan per nota', 'Jumlah nota'],
            collect($sebaran)->map(fn ($v, $n) => [$n, $v])->values()->all()
        );

        return self::SUCCESS;
    }
}

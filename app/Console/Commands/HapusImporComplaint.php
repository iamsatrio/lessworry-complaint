<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use Illuminate\Console\Command;

/**
 * Jalan mundur untuk impor. (API-28)
 *
 * Ini SATU-SATUNYA penghapusan complaint yang diizinkan di sistem ini, dan
 * hanya untuk baris yang punya `import_source` — complaint yang dicatat orang
 * tidak bisa disentuh dari sini.
 *
 * Impor pertama ke sistem yang belum pernah memegang data nyata harus punya
 * jalan pulang. Tanpanya, satu pemetaan yang salah berarti 545 baris kotor
 * yang dibersihkan tangan satu per satu.
 */
class HapusImporComplaint extends Command
{
    protected $signature = 'complaint:import-hapus
        {sumber : Penanda asal yang dipakai saat impor}
        {--paksa : Lewati pertanyaan konfirmasi}';

    protected $description = 'Hapus seluruh complaint hasil satu impor, dikenali dari import_source';

    public function handle(): int
    {
        $sumber = (string) $this->argument('sumber');

        $query = Complaint::query()->where('import_source', $sumber);
        $jumlah = $query->count();

        if ($jumlah === 0) {
            $this->warn('Tidak ada complaint dengan import_source '.$sumber.'. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('paksa') && ! $this->confirm('Hapus '.$jumlah.' complaint hasil impor '.$sumber.'?')) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        // Riwayat dan lampirannya ikut lewat cascade di skema; yang dihapus
        // di sini hanya baris yang dibuat perintah impor.
        $query->delete();

        $this->info($jumlah.' complaint hasil impor '.$sumber.' dihapus.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\NeviraClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Selaraskan daftar outlet dengan NEVIRA.
 *
 * Tanpa pemetaan ini, complaint tidak bisa menentukan outletnya sendiri dari
 * nota, dan pembatasan kasir per outlet tidak punya dasar untuk dibandingkan.
 *
 * Hanya membaca dari NEVIRA. Outlet yang ada di sistem ini tapi tidak ada di
 * NEVIRA tidak dihapus — complaint lama menunjuk ke sana.
 */
class SyncNeviraOutlets extends Command
{
    protected $signature = 'nevira:sync-outlets {--dry-run : Tampilkan rencananya tanpa menyimpan}';

    protected $description = 'Tarik daftar outlet dari NEVIRA dan petakan ke outlet di sistem ini';

    public function handle(NeviraClient $nevira): int
    {
        if (! $nevira->isConfigured()) {
            $this->error('Kredensial NEVIRA belum diisi. Set NEVIRA_EMAIL dan NEVIRA_PASSWORD di .env');

            return self::FAILURE;
        }

        try {
            $rows = $nevira->outlets();
        } catch (Throwable $e) {
            $this->error('Gagal mengambil outlet: '.$e->getMessage());

            return self::FAILURE;
        }

        $kering = $this->option('dry-run');
        $baru = $dipetakan = $tetap = 0;

        foreach ($rows as $row) {
            $idNevira = (string) ($row['id_outlet'] ?? '');
            $nama = trim((string) ($row['outlet_name'] ?? ''));

            if ($idNevira === '' || $nama === '') {
                continue;
            }

            $outlet = Outlet::where('nevira_outlet_id', $idNevira)->first();

            if ($outlet) {
                $tetap++;

                continue;
            }

            // Cocokkan dengan outlet yang sudah ada tapi belum dipetakan,
            // supaya complaint lama tidak kehilangan outletnya.
            $outlet = Outlet::whereNull('nevira_outlet_id')
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($nama).'%'])
                ->first();

            if ($outlet) {
                $this->line("  petakan  <info>{$outlet->name}</info> -> NEVIRA {$idNevira} ({$nama})");
                if (! $kering) {
                    $outlet->update(['nevira_outlet_id' => $idNevira]);
                }
                $dipetakan++;

                continue;
            }

            $this->line("  tambah   <info>{$nama}</info> (NEVIRA {$idNevira})");
            if (! $kering) {
                Outlet::create(['name' => $nama, 'nevira_outlet_id' => $idNevira, 'is_active' => true]);
            }
            $baru++;
        }

        $this->newLine();
        $this->info("Sudah terpetakan: {$tetap} · dipetakan sekarang: {$dipetakan} · outlet baru: {$baru}");

        $belum = Outlet::whereNull('nevira_outlet_id')->pluck('name');
        if ($belum->isNotEmpty()) {
            $this->warn('Belum terpetakan ke NEVIRA: '.$belum->join(', '));
            $this->warn('Kasir di outlet tersebut tidak bisa memeriksa nota sampai dipetakan.');
        }

        if ($kering) {
            $this->comment('Mode --dry-run: tidak ada yang disimpan.');
        }

        return self::SUCCESS;
    }
}

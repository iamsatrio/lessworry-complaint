<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pemeriksaan kesehatan sistem. (API-27)
 *
 * Yang membuatnya ada: jam 9 malam ada yang melapor sistem tidak bisa dibuka,
 * dan tidak ada cara tahu apakah yang mati aplikasinya, databasenya, atau
 * koneksi ke NEVIRA — selain membuka aplikasi lalu menebak.
 *
 * Keluarannya sengaja miskin. Endpoint ini terbuka tanpa autentikasi, jadi
 * tidak menyebut versi, nama host, nama database, URL internal, maupun pesan
 * galat mentah. Yang keluar hanya tiga kata: ok, error, disabled. Pemantau
 * membaca kode status HTTP-nya; isi JSON hanya untuk manusia yang menyusul.
 */
class PemeriksaKesehatan
{
    private const CACHE_NEVIRA = 'health:nevira';

    /**
     * Hasil NEVIRA disimpan 60 detik — termasuk hasil gagalnya.
     *
     * Kalau hanya yang berhasil disimpan, satu NEVIRA yang sedang tumbang
     * justru ditanya setiap kali pemantau memanggil: paling sering, tepat
     * saat paling tidak boleh dibebani.
     */
    public const NEVIRA_TTL = 60;

    /** @return array{status:string,checks:array<string,string>} */
    public function periksa(): array
    {
        $checks = [
            'database' => $this->database(),
            'nevira' => $this->nevira(),
            'storage' => $this->storage(),
        ];

        // "disabled" bukan kerusakan: itu pilihan yang ditulis di .env
        // (NEVIRA_ENABLED=false) supaya sistem bisa dipakai tanpa NEVIRA.
        $rusak = collect($checks)->contains(fn ($nilai) => $nilai === 'error');

        return [
            'status' => $rusak ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /** Satu query paling ringan yang tetap membuktikan koneksinya hidup. */
    private function database(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function nevira(): string
    {
        return Cache::remember(self::CACHE_NEVIRA, self::NEVIRA_TTL, function () {
            if (! config('nevira.enabled')) {
                return 'disabled';
            }

            $klien = app(NeviraClient::class);

            // Integrasi dinyalakan tapi kredensialnya kosong bukan pilihan,
            // itu salah pasang. Dilaporkan sebagai error.
            if (! $klien->isConfigured()) {
                return 'error';
            }

            try {
                $klien->me();

                return 'ok';
            } catch (Throwable) {
                // Sengaja tanpa pesan: isinya bisa memuat URL dan status
                // internal NEVIRA. Detailnya sudah masuk log lewat
                // NeviraClient.
                return 'error';
            }
        });
    }

    /**
     * Lampiran complaint ditulis ke disk 'local'. Diperiksa dengan benar-benar
     * menulis lalu menghapus — is_writable() tetap menjawab "boleh" saat
     * kuota disk habis, dan kuota habis persis kasus yang perlu ketahuan.
     */
    private function storage(): string
    {
        $nama = '.health-check';

        try {
            if (Storage::disk('local')->put($nama, (string) time()) === false) {
                return 'error';
            }

            Storage::disk('local')->delete($nama);

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }
}

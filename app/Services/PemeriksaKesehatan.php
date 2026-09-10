<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDOException;
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
            'mail' => $this->mail(),
        ];

        // "disabled" bukan kerusakan: itu pilihan yang ditulis di .env
        // (NEVIRA_ENABLED=false) supaya sistem bisa dipakai tanpa NEVIRA.
        // "unknown" juga tidak: itu berarti pemeriksaannya sendiri tidak bisa
        // dijalankan karena ada yang lain yang mati — dan yang lain itu sudah
        // punya barisnya sendiri di sini.
        $rusak = collect($checks)->contains(fn ($nilai) => $nilai === 'error');

        return [
            'status' => $rusak ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /**
     * Produksi tanpa pengiriman surat = seluruh tim terkunci di login pertama.
     *
     * Gerbang verifikasi email berdiri sebelum gerbang ganti password, jadi
     * `.env` produksi yang terpasang dengan MAIL_MAILER=log tidak menahan satu
     * orang, ia menahan semua — dan satu-satunya jalan keluarnya menuntut akses
     * shell ke server. Ini satu-satunya cara mengetahuinya dari luar sebelum
     * ada yang terkunci lebih dulu. (API-47)
     *
     * Di luar produksi, mailer yang hanya mencatat adalah keadaan wajar saat
     * pengembangan: dilaporkan "disabled", bukan kerusakan, jadi /health tetap
     * 200 — sama seperti NEVIRA yang sengaja dimatikan.
     *
     * config('app.env') dibaca langsung, bukan app()->environment(): nilai yang
     * terakhir disebut dikunci saat bootstrap dan tidak ikut berubah kalau
     * konfigurasinya diganti setelahnya.
     */
    private function mail(): string
    {
        // Diperiksa lebih dulu, dan berlaku di lingkungan mana pun: mailer
        // yang terpasang tapi tidak bisa dihubungi mengunci setiap akun di
        // login pertama, dan itu tidak terbaca dari .env mana pun. Penandanya
        // ditulis PengirimVerifikasiEmail dari HASIL kirim(), lalu dihapus
        // oleh pengiriman berikutnya yang berhasil. (Tinjauan PR #17 nomor 2)
        try {
            $gagalTerakhir = Cache::store(config('health.cache_store'))
                ->get(PengirimVerifikasiEmail::CACHE_GAGAL);
        } catch (Throwable) {
            // Penandanya sendiri tidak terbaca, jadi keadaan mailernya tidak
            // diketahui — bukan "baik-baik saja". "unknown" tidak dihitung
            // sebagai kerusakan karena cache yang rusak sudah punya barisnya
            // sendiri di jawaban ini.
            return 'unknown';
        }

        if ($gagalTerakhir) {
            return 'error';
        }

        if (! PengirimVerifikasiEmail::hanyaMencatat()) {
            return 'ok';
        }

        return config('app.env') === 'production' ? 'error' : 'disabled';
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

    /**
     * Hasilnya disimpan di cache store khusus /health (config/health.php),
     * BUKAN store bawaan.
     *
     * Store bawaan produksi adalah `database`. Kalau dipakai di sini, database
     * yang mati membuat pemeriksaan ini melempar QueryException tepat setelah
     * pemeriksaan `database` mengembalikan "error" — dan seluruh jawaban
     * berubah jadi HTTP 500 tanpa isi. Pemadaman yang paling perlu dibedakan
     * justru yang membuat /health bisu.
     */
    private function nevira(): string
    {
        try {
            return Cache::store(config('health.cache_store'))
                ->remember(self::CACHE_NEVIRA, self::NEVIRA_TTL, fn () => $this->tanyaNevira());
        } catch (Throwable) {
            // Cachenya sendiri tidak terbaca. Dilaporkan rusak, dan SENGAJA
            // tidak jatuh ke "panggil NEVIRA langsung": tanpa cache, setiap
            // panggilan pemantau jadi satu panggilan ke NEVIRA — membanjirinya
            // persis saat sistem sedang kacau.
            return 'error';
        }
    }

    private function tanyaNevira(): string
    {
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
        } catch (PDOException) {
            // Yang gagal BUKAN NEVIRA: token NEVIRA disimpan di cache store
            // bawaan (database), jadi database yang mati membuat pengambilan
            // token melempar sebelum satu permintaan pun dikirim. Melaporkannya
            // "error" akan bilang NEVIRA mati padahal NEVIRA sehat — dan
            // membedakan keduanya justru gunanya endpoint ini.
            //
            // QueryException Laravel turunan PDOException, jadi keduanya
            // tertangkap di sini.
            return 'unknown';
        } catch (Throwable) {
            // Sengaja tanpa pesan: isinya bisa memuat URL dan status internal
            // NEVIRA. Detailnya sudah masuk log lewat NeviraClient.
            return 'error';
        }
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

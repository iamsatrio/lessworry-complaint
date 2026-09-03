<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /health — dibaca pemantau, jadi yang diuji adalah kode statusnya dan
 * apa yang TIDAK ikut keluar. (API-27)
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ditiru dari produksi: store BAWAAN adalah `database`, dan /health
        // memakai store sendiri yang tidak bergantung database. Kalau kode
        // kembali memakai store bawaan, test pemadaman database di bawah
        // langsung jadi HTTP 500.
        config([
            'cache.default' => 'database',
            'health.cache_store' => 'array',
            'health.rate_limit' => 60,
        ]);
    }

    private function neviraHidup(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/admin/login' => Http::response(['access_token' => 'tok'], 200),
            '*/me' => Http::response(['data' => ['id_user' => 1]], 200),
        ]);
    }

    private function neviraSalahKredensial(): void
    {
        config([
            'nevira.enabled' => true,
            'nevira.email' => 'salah@lessworry.id',
            'nevira.password' => 'password-yang-salah',
        ]);

        Http::fake([
            '*/admin/login' => Http::response(['message' => 'Unauthorized'], 401),
            '*' => Http::response(['msg' => 'Please provide access token!'], 401),
        ]);
    }

    public function test_semuanya_hidup_membalas_200_dengan_tiga_pemeriksaan_ok(): void
    {
        $this->neviraHidup();

        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'nevira' => 'ok', 'storage' => 'ok'],
            ]);
    }

    public function test_kredensial_nevira_salah_membalas_503_dan_nevira_tidak_ok(): void
    {
        $this->neviraSalahKredensial();

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('error', $response->json('checks.nevira'));
        $this->assertSame('error', $response->json('status'));

        // Database dan storage tetap hidup — itulah gunanya memisahkan
        // pemeriksaan: pemilik teknis tahu yang mati koneksinya, bukan aplikasi.
        $this->assertSame('ok', $response->json('checks.database'));
        $this->assertSame('ok', $response->json('checks.storage'));
    }

    public function test_health_tidak_membocorkan_kredensial_url_internal_atau_jejak_galat(): void
    {
        $this->neviraSalahKredensial();

        $isi = $this->getJson('/health')->getContent();

        foreach ([
            'password-yang-salah',
            'salah@lessworry.id',
            'api.nevira.id',
            'Unauthorized',
            'vendor',
            'Exception',
            'stack',
            'sqlite',
            base_path(),
        ] as $rahasia) {
            $this->assertStringNotContainsStringIgnoringCase($rahasia, $isi, 'Bocor di /health: '.$rahasia);
        }

        // Bentuknya persis tiga kunci pemeriksaan, tidak ada yang lain —
        // tidak versi, tidak nama host.
        $this->assertSame(
            ['database', 'nevira', 'storage'],
            array_keys($this->getJson('/health')->json('checks'))
        );
    }

    public function test_sepuluh_panggilan_dalam_semenit_hanya_sekali_menghubungi_nevira(): void
    {
        $this->neviraHidup();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/health')->assertOk();
        }

        $keNevira = collect(Http::recorded())
            ->filter(fn ($pasangan) => str_contains($pasangan[0]->url(), '/me'))
            ->count();

        $this->assertSame(1, $keNevira, 'NEVIRA dipanggil '.$keNevira.'x; hasilnya harus di-cache 60 detik.');
    }

    public function test_nevira_yang_sedang_mati_juga_hanya_ditanya_sekali(): void
    {
        // Yang paling berbahaya: NEVIRA tumbang, pemantau memanggil tiap
        // menit, dan setiap panggilan menambah beban ke NEVIRA yang sedang
        // sekarat. Hasil gagal harus ikut di-cache.
        $this->neviraSalahKredensial();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/health')->assertStatus(503);
        }

        $this->assertLessThanOrEqual(2, count(Http::recorded()));
    }

    public function test_nevira_dimatikan_di_env_bukan_kerusakan(): void
    {
        config(['nevira.enabled' => false]);
        Http::fake();

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('checks.nevira', 'disabled');

        Http::assertNothingSent();
    }

    public function test_nevira_dinyalakan_tanpa_kredensial_dihitung_rusak(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => null, 'nevira.password' => null]);
        Http::fake();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.nevira', 'error');
    }

    public function test_health_terbuka_tanpa_login_dan_tidak_meninggalkan_sesi(): void
    {
        $this->neviraHidup();

        $response = $this->get('/health');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');

        // Rutenya di luar middleware web. Kalau tidak, pemantau yang memanggil
        // tiap menit meninggalkan satu baris sesi baru setiap kali.
        $this->assertNull($response->getCookie(config('session.cookie')));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    /* ---------- Pemadaman database (temuan T1 di PR #2) ---------- */

    public function test_database_mati_membalas_503_bukan_500(): void
    {
        config(['nevira.enabled' => false]);
        Http::fake();

        // Koneksi yang tidak bisa dihubungi = database mati.
        config(['database.default' => 'pgsql']);

        try {
            $response = $this->getJson('/health');
        } finally {
            config(['database.default' => 'sqlite']);
        }

        // Yang sebelumnya terjadi: pemeriksaan database benar mengembalikan
        // "error", lalu cache store `database` melempar QueryException dan
        // seluruh jawaban jadi HTTP 500 tanpa isi — bisu justru pada
        // pemadaman yang paling perlu dibedakan.
        $response->assertStatus(503);
        $this->assertSame('error', $response->json('checks.database'));
        $this->assertSame('ok', $response->json('checks.storage'));

        // Dan pemeriksaan NEVIRA tetap memberi jawaban sebenarnya. Kalau
        // cachenya ikut memakai store `database`, nilai ini berubah jadi
        // "error" — pemadaman database menutupi keadaan NEVIRA yang
        // sesungguhnya, padahal memisahkan keduanya justru gunanya endpoint
        // ini.
        $this->assertSame('disabled', $response->json('checks.nevira'));
    }

    public function test_database_mati_tidak_membocorkan_jejak_galat(): void
    {
        config(['app.debug' => true, 'nevira.enabled' => false]);
        Http::fake();

        config(['database.default' => 'pgsql']);

        try {
            $isi = (string) $this->getJson('/health')->getContent();
        } finally {
            config(['database.default' => 'sqlite']);
        }

        // APP_DEBUG=true yang tak sengaja terbawa tidak boleh mengubah
        // endpoint tanpa autentikasi ini jadi jejak galat berisi path server.
        foreach (['pgsql', '5432', 'QueryException', 'vendor', base_path()] as $rahasia) {
            $this->assertStringNotContainsStringIgnoringCase($rahasia, $isi, 'Bocor di /health: '.$rahasia);
        }

        $this->assertLessThan(1000, strlen($isi));
    }

    public function test_pembatas_laju_tidak_menyentuh_database(): void
    {
        config(['nevira.enabled' => false]);
        Http::fake();
        config(['database.default' => 'pgsql']);

        try {
            // Dua kali: kalau pembatas lajunya memakai cache store `database`,
            // yang pertama saja sudah meledak sebelum sampai ke controller.
            $this->getJson('/health')->assertStatus(503);
            $this->getJson('/health')->assertStatus(503);
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_cache_yang_rusak_dilaporkan_rusak_bukan_dilewati(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
        Http::fake();

        Cache::shouldReceive('store')->andThrow(new \RuntimeException('cache mati'));

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('error', $response->json('checks.nevira'));

        // Dan TIDAK jatuh ke "panggil NEVIRA langsung": tanpa cache, tiap
        // panggilan pemantau jadi satu panggilan ke NEVIRA.
        Http::assertNothingSent();
    }

    public function test_panggilan_berlebihan_ditolak_tanpa_keterangan(): void
    {
        config(['health.rate_limit' => 2, 'nevira.enabled' => false]);
        Http::fake();

        $this->getJson('/health')->assertOk();
        $this->getJson('/health')->assertOk();

        $this->getJson('/health')
            ->assertStatus(429)
            ->assertExactJson(['status' => 'error']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kotak Cari berada di dalam `<details class="filters">` yang tertutup kecuali
 * sudah ada saringan aktif. Bagi supervisor, mencari adalah tindakan utama di
 * papan kerja — bukan tindakan lanjutan seperti menyaring kategori atau bobot.
 * (API-38 #13)
 */
class KotakCariTest extends TestCase
{
    use RefreshDatabase;

    private function papanKerja(string $query = ''): string
    {
        $user = User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        return $this->actingAs($user)->get('/complaints'.$query)->assertOk()->getContent();
    }

    public function test_kotak_cari_berdiri_di_luar_panel_saringan(): void
    {
        $html = $this->papanKerja();

        $posisiCari = strpos($html, 'id="q"');
        $posisiPanel = strpos($html, '<details class="filters"');

        $this->assertNotFalse($posisiCari, 'Kotak cari hilang dari papan kerja.');
        $this->assertNotFalse($posisiPanel, 'Panel saringan hilang dari papan kerja.');
        $this->assertLessThan(
            $posisiPanel,
            $posisiCari,
            'Kotak cari kembali masuk ke dalam panel saringan yang tertutup.'
        );
    }

    /** Saringan sisanya memang lanjutan; ia boleh tetap terlipat. */
    public function test_panel_saringan_tetap_tertutup_saat_hanya_mencari(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<details class="filters"[^>]*\bopen\b/',
            $this->papanKerja('?q=LW'),
            'Mencari tidak boleh ikut membentangkan seluruh panel saringan.'
        );
    }

    public function test_panel_terbuka_saat_ada_saringan_aktif(): void
    {
        $this->assertMatchesRegularExpression(
            '/<details class="filters"[^>]*\bopen\b/',
            $this->papanKerja('?bobot=berat')
        );
    }

    /**
     * Saringan yang datang sebagai array tidak boleh menjatuhkan halamannya.
     *
     * `?category[]=x` mengirim array. Array yang lolos ke `{{ }}` di Blade
     * membuat htmlspecialchars() melempar, dan halaman tersibuk di sistem
     * berbalas HTTP 500 tanpa jalan kembali — cukup tautan yang disunting
     * tangan, bookmark yang rusak, atau crawler. Yang benar: parameter itu
     * dianggap tidak ada, halamannya tetap 200. (Tinjauan PR #14 nomor 1)
     *
     * `status[]` dan `q[]` ikut di sini meski rusaknya sudah ada sebelum
     * PR ini — sekali disaring di satu tempat, ketujuhnya tertutup.
     */
    public function test_saringan_berbentuk_array_tidak_menjatuhkan_halaman(): void
    {
        foreach ([
            '?category[]=x',
            '?bobot[]=x',
            '?layanan[]=x',
            '?outlet_id[]=1',
            '?channel[]=x',
            '?status[]=close',
            '?q[]=LW',
            '?q=LW&category[]=x',
            '?category[]=a&category[]=b',
        ] as $url) {
            $this->papanKerja($url);
        }
    }

    /** Array diabaikan, bukan ditebak artinya — papan kerjanya apa adanya. */
    public function test_saringan_array_diabaikan_bukan_ditebak(): void
    {
        $html = $this->papanKerja('?category[]=kurang_bersih');

        $this->assertStringContainsString('complaint terbuka', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<details class="filters"[^>]*\bopen\b/',
            $html,
            'Parameter array tidak boleh terbaca sebagai saringan aktif.'
        );
    }

    /** Kanal disaring controller, jadi ia harus ikut terbawa. (Tinjauan PR #14 nomor 4) */
    public function test_saringan_kanal_ikut_terbawa(): void
    {
        $html = $this->papanKerja('?channel=wa_cc');

        $this->assertStringContainsString('<input type="hidden" name="channel" value="wa_cc">', $html);
    }

    public function test_saringan_aktif_ikut_terbawa_saat_menekan_cari(): void
    {
        $this->assertStringContainsString(
            '<input type="hidden" name="bobot" value="berat">',
            $this->papanKerja('?bobot=berat'),
            'Menekan Cari membuang saringan yang sudah dipasang.'
        );
    }

    public function test_kata_kunci_ikut_terbawa_saat_menerapkan_saringan(): void
    {
        $this->assertStringContainsString(
            '<input type="hidden" name="q" value="LW">',
            $this->papanKerja('?q=LW'),
            'Menerapkan saringan membuang kata kunci yang sedang dicari.'
        );
    }
}

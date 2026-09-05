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

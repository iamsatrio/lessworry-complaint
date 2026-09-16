<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman nol hasil dan cakupan pencarian.
 *
 * Baymard mengukur 69% pengguna meninggalkan situs setelah bertemu halaman nol
 * hasil, dan NN/g menuntut tiga hal dari halaman itu: pernyataan yang jelas,
 * kueri aslinya dibawa kembali dalam keadaan bisa disunting, dan jalan keluar
 * yang konkret. Yang TIDAK boleh ditawarkan sebagai jalan keluar utama:
 * mencatat complaint baru — orang yang mencari sedang mencari yang sudah ada.
 *
 * Cakupan pencarian sendiri ditulis apa adanya di dekat kotak Cari: membatasi
 * pencarian tidak salah, membatasinya diam-diam yang salah. (API-38 #1)
 */
class NolHasilTest extends TestCase
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

    public function test_cakupan_pencarian_ditulis_di_dekat_kotak_cari(): void
    {
        $this->assertStringContainsString(
            'Pencarian mencakup seluruh complaint, termasuk yang sudah ditutup.',
            $this->papanKerja(),
            'Cakupan pencarian kembali jadi aturan tersembunyi.'
        );
    }

    /** Saringan status membatasi pencarian — batasannya disebut, berikut jalan keluarnya. */
    public function test_batasan_status_disebut_dan_bisa_dilepas_satu_ketukan(): void
    {
        $html = $this->papanKerja('?status=close&q=LW');

        $this->assertStringContainsString('Pencarian dibatasi ke complaint berstatus "Close".', $html);
        $this->assertStringContainsString('Cari di seluruh complaint', $html);
        $this->assertMatchesRegularExpression(
            '/href="[^"]*complaints\?q=LW"[^>]*>Cari di seluruh complaint/',
            $html,
            'Tautan "cari di seluruh complaint" harus membawa kuerinya, bukan membuangnya.'
        );
    }

    public function test_kueri_dibawa_kembali_dan_tetap_bisa_disunting(): void
    {
        $html = $this->papanKerja('?q=LW-20260803-012');

        $this->assertStringContainsString('<b>"LW-20260803-012"</b>', $html);
        $this->assertStringContainsString('value="LW-20260803-012"', $html);
        $this->assertStringContainsString('Ubah kata pencarian', $html);
    }

    /** Nol hasil bukan undangan mencatat ulang complaint yang sudah ada. */
    public function test_nol_hasil_pencarian_tidak_menawarkan_catat_complaint(): void
    {
        $html = $this->papanKerja('?q=tidak-ada-yang-cocok');

        $posisi = strpos($html, 'Tidak ada complaint dengan');
        $this->assertNotFalse($posisi, 'Keadaan nol hasil pencarian hilang.');

        $sisa = substr($html, $posisi);
        $this->assertStringNotContainsString(
            'Catat Complaint</a>',
            substr($sisa, 0, strpos($sisa, '</div>') ?: strlen($sisa)),
            'Pencarian yang gagal tidak boleh menyodorkan "catat complaint baru".'
        );
    }

    /** Papan kerja kosong tanpa pencarian tetap boleh menawarkannya. */
    public function test_papan_kosong_tanpa_pencarian_tetap_menawarkan_catat_complaint(): void
    {
        $this->assertStringContainsString(
            'catat complaint baru',
            $this->papanKerja(),
            'Papan kerja kosong tanpa pencarian memang tempat yang benar untuk menawarkannya.'
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman /password dalam keadaan wajib-ganti tetap menampilkan Dashboard,
 * Papan Kerja, dan Laporan di navigasi. Ketiganya memantul balik ke /password.
 *
 * Menawarkan tiga pintu yang semuanya terkunci membuat orang mengira sistemnya
 * rusak — dan ini terjadi pada login pertama pegawai baru. (API-38 #12)
 */
class NavigasiTerkunciTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(bool $wajibGanti): User
    {
        return User::create([
            'name' => 'Customer Care', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
            'must_change_password' => $wajibGanti,
        ]);
    }

    public function test_navigasi_disembunyikan_selama_password_wajib_diganti(): void
    {
        $html = $this->actingAs($this->userAs(true))->get('/password')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Papan Kerja</a>', $html);
        $this->assertStringNotContainsString('>Laporan</a>', $html);
        $this->assertStringNotContainsString('>Dashboard</a>', $html);
        $this->assertStringContainsString('Ganti password dulu sebelum memakai sistem', $html);
    }

    /** Satu-satunya pintu yang memang terbuka. */
    public function test_tombol_keluar_tetap_ada(): void
    {
        $this->actingAs($this->userAs(true))->get('/password')->assertOk()->assertSee('Keluar');
    }

    public function test_tombol_melayang_ikut_disembunyikan(): void
    {
        $this->assertStringNotContainsString(
            'class="btn fab"',
            $this->actingAs($this->userAs(true))->get('/password')->assertOk()->getContent(),
            'Tombol melayang Catat Complaint juga memantul balik ke /password.'
        );
    }

    public function test_navigasi_kembali_setelah_password_diganti(): void
    {
        $this->assertStringContainsString(
            '>Papan Kerja</a>',
            $this->actingAs($this->userAs(false))->get('/password')->assertOk()->getContent()
        );
    }
}

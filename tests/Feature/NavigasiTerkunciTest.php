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

    /** Kelas ini menyetel sendiri keadaan verifikasinya. */
    protected bool $verifikasiOtomatis = false;

    private function userAs(bool $wajibGanti, bool $terverifikasi = true): User
    {
        $user = User::create([
            'name' => 'Customer Care', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
            'must_change_password' => $wajibGanti,
        ]);

        // email_verified_at bukan kolom fillable — disetel terpisah.
        $user->forceFill(['email_verified_at' => $terverifikasi ? now() : null])->save();

        return $user;
    }

    public function test_navigasi_disembunyikan_selama_password_wajib_diganti(): void
    {
        $html = $this->actingAs($this->userAs(true))->get('/password')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Papan Kerja</a>', $html);
        $this->assertStringNotContainsString('>Laporan</a>', $html);
        $this->assertStringNotContainsString('>Dashboard</a>', $html);
        $this->assertStringContainsString('Ganti password dulu sebelum memakai sistem', $html);
    }

    /**
     * Verifikasi email berdiri di depan ganti password.
     *
     * Akun yang belum terverifikasi DAN passwordnya wajib diganti tidak boleh
     * dibaca "ganti password dulu": `/password` sendiri memantulkannya balik
     * ke `/verifikasi-email` (API-35), jadi kalimat itu menyuruh mengerjakan
     * hal yang belum bisa dikerjakan. Penyelesaian konflik yang menumpuk dua
     *
     * @if dan memeriksa password lebih dulu gagal di sini. (Tinjauan PR #14)
     */
    public function test_verifikasi_email_disebut_lebih_dulu_daripada_ganti_password(): void
    {
        $html = $this->actingAs($this->userAs(true, terverifikasi: false))
            ->get('/verifikasi-email')->assertOk()->getContent();

        $this->assertStringContainsString('Verifikasi email dulu sebelum memakai sistem', $html);
        $this->assertStringNotContainsString('Ganti password dulu sebelum memakai sistem', $html);
    }

    public function test_gerbang_yang_menahan_disebut_satu_per_satu(): void
    {
        $this->assertSame('verifikasi', $this->userAs(true, terverifikasi: false)->gerbangTertunda());

        $this->assertSame('password', $this->userAs(true)->gerbangTertunda());
        $this->assertNull($this->userAs(false)->gerbangTertunda());
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

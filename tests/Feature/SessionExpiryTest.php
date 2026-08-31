<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Token CSRF basi pernah berujung halaman "Page Expired" kosong: isian
 * petugas hilang, tanpa keterangan apa yang salah.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    /**
     * Permintaan yang sudah terpasang rutenya, seperti yang sampai ke
     * penangan galat di produksi. Tanpa rute terpasang, penangan tidak bisa
     * membedakan simpan complaint dari permintaan lain.
     */
    private function simpanYangKedaluwarsa(array $isian): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/complaints', 'POST', $isian);
        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('complaints.store'));

        return $request;
    }

    private function renderKedaluwarsa(\Illuminate\Http\Request $request)
    {
        return app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, new TokenMismatchException('CSRF token mismatch.'));
    }

    private function isian(): array
    {
        return [
            '_token' => 'basi',
            'channel' => 'kasir',
            'reporter_name' => 'Ibu Sari',
            'description' => 'Baju masih kotor di bagian kerah',
            'password' => 'rahasia',
        ];
    }

    /* ---------- Simpan yang gagal harus terlihat gagal ---------- */

    public function test_simpan_gagal_tidak_dialihkan_lewat_flash_sesi(): void
    {
        // Pesannya dulu dititipkan ke flash session lalu dialihkan. Yang rusak
        // justru sesinya: rantai pengalihan menghabiskan flash itu sebelum
        // sampai ke layar, dan petugas melihat form tanpa keterangan apa pun.
        $user = $this->cc();
        $this->actingAs($user);

        $response = $this->renderKedaluwarsa($this->simpanYangKedaluwarsa($this->isian()));

        $this->assertSame(419, $response->getStatusCode(),
            'Kegagalan simpan masih dijawab dengan pengalihan — pesannya bisa hilang di jalan.');
        $this->assertFalse($response->isRedirect());
    }

    public function test_simpan_gagal_dirender_dengan_peringatan_dan_isian_utuh(): void
    {
        $user = $this->cc();
        $this->actingAs($user);

        $html = $this->renderKedaluwarsa($this->simpanYangKedaluwarsa($this->isian()))->getContent();

        $this->assertStringContainsString('BELUM tersimpan', $html,
            'Tidak ada keterangan bahwa complaint gagal disimpan.');

        // Form yang gagal disimpan tidak boleh terlihat seperti form kosong
        // siap pakai: tombolnya menyebutkan bahwa ini percobaan ulang.
        $this->assertStringContainsString('Coba Simpan Lagi', $html);

        // Isian dikembalikan langsung ke markup, bukan lewat sesi.
        $this->assertStringContainsString('Baju masih kotor di bagian kerah', $html);
        $this->assertStringContainsString('Ibu Sari', $html);

        $this->assertStringNotContainsString('rahasia', $html);
        $this->assertStringNotContainsString('value="basi"', $html);
    }

    public function test_sesi_yang_sudah_mati_tetap_memberi_tahu_complaint_tidak_masuk(): void
    {
        // Sesi habis: petugasnya tidak dikenali lagi, jadi formnya tidak bisa
        // dirender ulang. Yang tidak boleh hilang adalah kepastian bahwa
        // complaint itu tidak masuk — dulu di sini halamannya lompat ke
        // /login tanpa pesan apa pun.
        $response = $this->renderKedaluwarsa($this->simpanYangKedaluwarsa($this->isian()));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertFalse($response->isRedirect());

        $html = $response->getContent();

        $this->assertStringContainsString('BELUM tersimpan', $html);
        $this->assertStringContainsString(route('login'), $html);
    }

    public function test_halaman_sesi_mati_tidak_menampilkan_data_pelanggan(): void
    {
        // Petugasnya sudah tidak dikenali; halaman ini terbuka di perangkat
        // outlet yang dipakai bergantian. Isian pelanggan tetap aman di draft
        // perangkat — yang terkunci per pengguna — bukan tercetak di layar.
        $html = $this->renderKedaluwarsa($this->simpanYangKedaluwarsa($this->isian()))->getContent();

        $this->assertStringNotContainsString('Ibu Sari', $html);
        $this->assertStringNotContainsString('Baju masih kotor di bagian kerah', $html);
    }

    public function test_halaman_sesi_mati_tidak_boleh_disimpan_browser(): void
    {
        $response = $this->renderKedaluwarsa($this->simpanYangKedaluwarsa($this->isian()));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_permintaan_json_dapat_pesan_bukan_halaman_html(): void
    {
        $request = \Illuminate\Http\Request::create('/nevira/lookup', 'GET');
        $request->headers->set('Accept', 'application/json');

        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $response = $handler->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('Sesi kedaluwarsa', $response->getContent());
    }

    /* ---------- Halaman pengguna yang sudah masuk tidak boleh tersimpan browser ---------- */

    public function test_halaman_pengguna_masuk_dilarang_disimpan_browser(): void
    {
        $user = $this->cc();

        $response = $this->actingAs($user)->get('/complaints/create');

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_halaman_complaint_juga_dilarang_disimpan(): void
    {
        $user = $this->cc();

        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        $response = $this->actingAs($user)->get('/complaints/'.$complaint->id);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_halaman_login_tidak_ikut_dilarang_cache(): void
    {
        // Tamu tidak memegang data siapa pun; header ketat tidak perlu.
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}

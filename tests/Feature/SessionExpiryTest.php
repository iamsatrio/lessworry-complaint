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

    public function test_penangan_token_basi_menjaga_isian_dan_memberi_pesan(): void
    {
        $user = $this->cc();

        $this->actingAs($user)->from('/complaints/create');

        $request = \Illuminate\Http\Request::create('/complaints', 'POST', [
            '_token' => 'basi',
            'reporter_name' => 'Ibu Sari',
            'description' => 'Baju masih kotor di bagian kerah',
            'password' => 'rahasia',
        ]);
        $request->setLaravelSession(app('session.store'));

        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $response = $handler->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(302, $response->getStatusCode());

        $input = session()->getOldInput();

        $this->assertSame('Ibu Sari', $input['reporter_name'] ?? null);
        $this->assertSame('Baju masih kotor di bagian kerah', $input['description'] ?? null);

        // Rahasia tidak boleh ikut dikembalikan ke form.
        $this->assertArrayNotHasKey('password', $input);
        $this->assertArrayNotHasKey('_token', $input);

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertStringContainsString('kedaluwarsa', $errors->first('session'));
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
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
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

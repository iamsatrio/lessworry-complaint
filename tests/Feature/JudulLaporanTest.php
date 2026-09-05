<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Judul laporan menghitung "sudah selesai" dari resolved_at, sementara seluruh
 * 541 baris impor data lama ditutup tanpa pernah mengisi kolom itu. Hasilnya
 * satu halaman yang membantah dirinya sendiri: "9 complaint masuk, 0 sudah
 * selesai" di atas, dan "3 Ditutup Tanpa Alasan · 6 Masih Terbuka" di bawah.
 * Pembacanya harus menjumlahkan sendiri untuk tahu judulnya tidak bisa
 * dipercaya. (API-38 #11)
 */
class JudulLaporanTest extends TestCase
{
    use RefreshDatabase;

    private function complaint(string $status, ?string $closeReason, bool $resolvedAt): void
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = $status;
        $complaint->close_reason = $closeReason;
        $complaint->resolved_at = $resolvedAt ? now() : null;
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();
    }

    private function laporan(): string
    {
        $user = User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        return preg_replace('/\s+/', ' ', $this->actingAs($user)->get('/reports')->assertOk()->getContent());
    }

    public function test_tiket_close_data_lama_ikut_terhitung_sudah_ditutup(): void
    {
        // Bentuk data setelah backfill: ditutup, tanpa alasan, tanpa resolved_at.
        $this->complaint('close', null, resolvedAt: false);
        $this->complaint('close', null, resolvedAt: false);
        $this->complaint('close', null, resolvedAt: false);
        $this->complaint('open', null, resolvedAt: false);
        $this->complaint('handling', null, resolvedAt: false);

        $this->assertStringContainsString('5 complaint masuk, 3 sudah ditutup.', $this->laporan());
    }

    public function test_judul_dan_rincian_di_bawahnya_menjumlah(): void
    {
        $this->complaint('close', 'selesai', resolvedAt: true);
        $this->complaint('close', 'ditolak', resolvedAt: true);
        $this->complaint('close', null, resolvedAt: false);
        $this->complaint('open', null, resolvedAt: false);

        $html = $this->laporan();

        // 4 masuk, 3 ditutup, 1 masih terbuka — dan 1 + 1 + 1 = 3.
        $this->assertStringContainsString('4 complaint masuk, 3 sudah ditutup.', $html);
        $this->assertMatchesRegularExpression('/<div class="n">1<\/div><div class="l">Masih Terbuka/', $html);
    }

    public function test_tidak_lagi_melaporkan_nol_selesai_padahal_ada_yang_ditutup(): void
    {
        $this->complaint('close', null, resolvedAt: false);

        $html = $this->laporan();

        $this->assertStringNotContainsString('1 complaint masuk, 0 sudah selesai.', $html);
        $this->assertStringContainsString('1 complaint masuk, 1 sudah ditutup.', $html);
    }

    public function test_periode_kosong_tetap_dikatakan_apa_adanya(): void
    {
        $this->assertStringContainsString('Tidak ada complaint pada periode ini.', $this->laporan());
    }
}

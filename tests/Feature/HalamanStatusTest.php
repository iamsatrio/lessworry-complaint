<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menutup complaint adalah tindakan paling sering di halaman detail, tapi
 * kartunya berada di y=4128 pada halaman setinggi 5076px di layar 390px —
 * hampir lima layar gulir, karena blok penetapan pelaku terbuka bawaan dan
 * memakan sekitar 1500px di antaranya. (API-38 #6)
 *
 * Dan saat penyimpanan ditolak validasi, select status kembali ke status LAMA
 * karena tidak memakai old(): petugas yang memilih Close tanpa alasan harus
 * memilih Close lagi sebelum bisa mengisi alasannya. (API-38 #7)
 */
class HalamanStatusTest extends TestCase
{
    use RefreshDatabase;

    private function cc(): User
    {
        return User::create([
            'name' => 'Customer Care', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    private function complaint(): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'handling';
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_perbarui_status_berada_di_atas_riwayat_penanganan(): void
    {
        $complaint = $this->complaint();

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $status = strpos($html, 'Perbarui status');
        $riwayat = strpos($html, 'Riwayat penanganan');

        $this->assertNotFalse($status, 'Kartu Perbarui status hilang dari halaman detail.');
        $this->assertLessThan(
            $riwayat,
            $status,
            'Yang ditindak diletakkan di atas yang dibaca — status di atas riwayat.'
        );
    }

    public function test_daftar_kandidat_pelaku_tidak_terbuka_sendiri(): void
    {
        $complaint = $this->complaint();

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertStringContainsString('Tetapkan pelaku complaint ini', $html);
        $this->assertStringNotContainsString(
            '<details class="link-editor" open style="margin-top:10px">',
            $html,
            'Blok pelaku kembali terbuka bawaan dan mendorong kontrol status turun lima layar.'
        );
    }

    public function test_pilihan_status_bertahan_saat_penyimpanan_ditolak(): void
    {
        $complaint = $this->complaint();
        $user = $this->cc();

        // Close tanpa alasan penutupan: ditolak server, seperti seharusnya.
        $this->actingAs($user)
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->lock_version,
                'status' => 'close',
            ])
            ->assertSessionHasErrors('close_reason');

        $html = $this->actingAs($user)->withSession(['_old_input' => ['status' => 'close']])
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        preg_match('/<select id="st".*?<\/select>/s', $html, $m);

        $this->assertMatchesRegularExpression(
            '/<option value="close"[^>]*\bselected\b/',
            $m[0],
            'Pilihan status hilang setelah validasi menolak; petugas harus memilih Close lagi.'
        );
    }

    public function test_alasan_penutupan_ditandai_wajib_saat_status_close(): void
    {
        $complaint = $this->complaint();

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        // Penanda dan label yang diubah skrip saat status dipilih Close.
        $this->assertStringContainsString('id="cr-req"', $html);
        $this->assertStringContainsString('id="cr-kosong"', $html);
        $this->assertStringContainsString('cr.required = tutup', $html);
        $this->assertStringContainsString('— pilih alasan —', $html);
    }

    /** Penandanya di layar; yang menegakkan tetap server. */
    public function test_server_tetap_menolak_close_tanpa_alasan(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->lock_version,
                'status' => 'close',
            ])
            ->assertSessionHasErrors('close_reason');

        $this->assertSame('handling', $complaint->fresh()->status);
    }
}

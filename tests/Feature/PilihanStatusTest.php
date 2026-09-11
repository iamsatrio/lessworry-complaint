<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Select status memakai `$complaint->status`, bukan `old('status', ...)` —
 * satu-satunya kolom di form itu yang tidak memakai old().
 *
 * Akibatnya: petugas memilih Close, lupa alasan penutupan, server menolak
 * dengan benar — dan select kembali ke Handling. Ia harus memilih Close lagi,
 * memilih alasannya, lalu menggulir kembali ke tombol Simpan. Labelnya sendiri
 * berbunyi "hanya diisi kalau statusnya Close", jadi terbaca opsional padahal
 * server menuntutnya. (API-38 #7)
 */
class PilihanStatusTest extends TestCase
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

    public function test_pilihan_status_bertahan_saat_penyimpanan_ditolak(): void
    {
        $complaint = $this->complaint();
        $user = $this->cc();

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

    public function test_tanpa_isian_lama_select_menunjukkan_status_sebenarnya(): void
    {
        $complaint = $this->complaint();

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        preg_match('/<select id="st".*?<\/select>/s', $html, $m);

        $this->assertMatchesRegularExpression('/<option value="handling"[^>]*\bselected\b/', $m[0]);
    }

    public function test_alasan_penutupan_ditandai_wajib_saat_status_close(): void
    {
        $complaint = $this->complaint();

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

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

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menutup complaint adalah tindakan paling sering di halaman detail, tapi
 * kontrolnya berada di y=4128 pada halaman setinggi 5076px di layar 390px —
 * hampir lima layar gulir.
 *
 * Yang memakan ruang di antaranya adalah daftar kandidat pelaku: satu baris
 * centang dan satu select peran per pengguna sistem, selalu terbuka, sekitar
 * 1500px — padahal penetapan pelaku adalah tindakan jarang. (API-38 #6)
 */
class BlokPelakuTest extends TestCase
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

    private function halaman(): string
    {
        return $this->actingAs($this->cc())
            ->get('/complaints/'.$this->complaint()->id)->assertOk()->getContent();
    }

    public function test_perbarui_status_berada_di_atas_riwayat_penanganan(): void
    {
        $html = $this->halaman();

        $status = strpos($html, 'Perbarui status');

        $this->assertNotFalse($status, 'Kartu Perbarui status hilang dari halaman detail.');
        $this->assertLessThan(
            strpos($html, 'Riwayat penanganan'),
            $status,
            'Yang ditindak diletakkan di atas yang dibaca — status di atas riwayat.'
        );
    }

    public function test_daftar_kandidat_pelaku_tidak_terbuka_sendiri(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('Tetapkan pelaku complaint ini', $html);
        $this->assertStringNotContainsString(
            '<details class="link-editor" open style="margin-top:10px">',
            $html,
            'Blok pelaku kembali terbuka bawaan dan mendorong kontrol status turun lima layar.'
        );
    }

    /** Dilipat, bukan dihapus: satu ketukan tetap membukanya. */
    public function test_form_penetapan_pelaku_tetap_ada_di_dalamnya(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('name="pelaku[]"', false)
            ->assertSee('Tetapkan Pelaku');
    }
}

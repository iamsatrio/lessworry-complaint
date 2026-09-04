<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom teks bebas punya batas panjang. (API-8 T8)
 *
 * Deskripsi tanpa max menerima 2.000.000 karakter dan menyimpannya utuh.
 * Papan kerja dan halaman detail lalu memuat kolom itu apa adanya.
 */
class TextLengthLimitTest extends TestCase
{
    use RefreshDatabase;

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
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
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_deskripsi_sangat_panjang_ditolak(): void
    {
        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'nota_exemption' => 'belum_terbit',
            'description' => str_repeat('a', 2_000_000),
        ])->assertSessionHasErrors('description');

        $this->assertSame(0, Complaint::count());
    }

    public function test_deskripsi_sepanjang_wajar_tetap_diterima(): void
    {
        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'nota_exemption' => 'belum_terbit',
            'description' => str_repeat('a', 4_000),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Complaint::count());
    }

    public function test_resolusi_dan_penyebab_akar_dibatasi(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())->post('/complaints/'.$complaint->id.'/status', [
            'status' => 'handling', 'lock_version' => $complaint->lock_version,
            'resolution' => str_repeat('a', 2_000_000),
            'root_cause' => str_repeat('b', 2_000_000),
            'note' => str_repeat('c', 2_000_000),
        ])->assertSessionHasErrors(['resolution', 'root_cause', 'note']);
    }

    public function test_catatan_penanganan_dibatasi(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())->post('/complaints/'.$complaint->id.'/note', [
            'note' => str_repeat('a', 2_000_000),
        ])->assertSessionHasErrors('note');
    }

    public function test_alasan_penetapan_pelaku_dibatasi(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())->post('/complaints/'.$complaint->id.'/pelaku', [
            'manual_nama' => 'Budi',
            'alasan' => str_repeat('a', 2_000_000),
        ])->assertSessionHasErrors('alasan');
    }

    public function test_nama_pelaku_manual_dibatasi(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->cc())->post('/complaints/'.$complaint->id.'/pelaku', [
            'manual_nama' => str_repeat('a', 5_000),
            'alasan' => 'Alasan wajar.',
        ])->assertSessionHasErrors('manual_nama');
    }
}

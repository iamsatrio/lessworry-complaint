<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mencari nomor tiket yang sudah ditutup harus menemukannya.
 *
 * Papan kerja memakai scope open() saat status tidak dipilih, dan kotak Cari
 * ditempelkan DI ATAS scope itu. Akibatnya pencarian tidak pernah menyentuh
 * tiket Close: supervisor yang mencari kasus lama menerima "Tidak ada
 * complaint yang cocok" — halaman menyatakan tiketnya tidak ada padahal ada,
 * lalu menawarkan mencatat complaint baru untuk complaint yang sudah ada.
 *
 * Setelah backfill 545 baris historis yang mayoritasnya Close, itu berarti
 * nyaris setiap pencarian kasus lama berbalas nol. (API-38 #1)
 */
class PencarianTiketTertutupTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): User
    {
        return User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);
    }

    private function complaint(string $status, string $nama): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => $nama, 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = $status;
        $complaint->close_reason = $status === 'close' ? 'selesai' : null;
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_nomor_tiket_yang_sudah_close_ketemu_tanpa_mengubah_saringan(): void
    {
        $tutup = $this->complaint('close', 'Pelapor Lama');
        $this->complaint('open', 'Pelapor Baru');

        $this->actingAs($this->supervisor())
            ->get('/complaints?q='.$tutup->ticket_number)
            ->assertOk()
            ->assertSee($tutup->ticket_number)
            ->assertDontSee('Tidak ada complaint yang cocok');
    }

    public function test_nama_pelapor_pada_tiket_close_juga_ketemu(): void
    {
        $tutup = $this->complaint('close', 'Pelapor Lama');

        $this->actingAs($this->supervisor())
            ->get('/complaints?q=Pelapor+Lama')
            ->assertOk()
            ->assertSee($tutup->ticket_number);
    }

    public function test_papan_kerja_tanpa_pencarian_tetap_hanya_yang_terbuka(): void
    {
        $tutup = $this->complaint('close', 'Pelapor Lama');
        $buka = $this->complaint('open', 'Pelapor Baru');

        $html = $this->actingAs($this->supervisor())->get('/complaints')->assertOk()->getContent();

        $this->assertStringContainsString($buka->ticket_number, $html);
        $this->assertStringNotContainsString(
            $tutup->ticket_number,
            $html,
            'Papan kerja polos harus tetap papan kerja: hanya complaint terbuka.'
        );
    }

    public function test_saringan_status_yang_dipilih_tetap_menang(): void
    {
        $tutup = $this->complaint('close', 'Pelapor Lama');
        $buka = $this->complaint('open', 'Pelapor Lama');

        $html = $this->actingAs($this->supervisor())
            ->get('/complaints?q=Pelapor+Lama&status=close')->assertOk()->getContent();

        $this->assertStringContainsString($tutup->ticket_number, $html);
        $this->assertStringNotContainsString($buka->ticket_number, $html);
    }

    public function test_judul_tidak_lagi_menyebut_terbuka_saat_mencari(): void
    {
        $this->complaint('close', 'Pelapor Lama');

        $html = $this->actingAs($this->supervisor())
            ->get('/complaints?q=Pelapor')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1>1 complaint<\/h1>/',
            $html,
            'Judul menyebut "complaint terbuka" untuk daftar yang memuat tiket tertutup.'
        );
    }

    /** Mencari adalah tindakan utama supervisor, bukan tindakan lanjutan. (API-38 #13) */
    public function test_kotak_cari_tidak_bersembunyi_di_balik_panel_saringan(): void
    {
        $html = $this->actingAs($this->supervisor())->get('/complaints')->assertOk()->getContent();

        $posisiCari = strpos($html, 'id="q"');
        $posisiPanel = strpos($html, '<details class="filters"');

        $this->assertNotFalse($posisiCari, 'Kotak cari hilang dari papan kerja.');
        $this->assertNotFalse($posisiPanel);
        $this->assertLessThan(
            $posisiPanel,
            $posisiCari,
            'Kotak cari kembali masuk ke dalam panel saringan yang tertutup.'
        );
    }

    public function test_saringan_lain_ikut_terbawa_saat_mencari(): void
    {
        $html = $this->actingAs($this->supervisor())
            ->get('/complaints?bobot=berat')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<input type="hidden" name="bobot" value="berat">',
            $html,
            'Menekan Cari membuang saringan yang sudah dipasang.'
        );
    }
}

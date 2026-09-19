<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use App\Services\NilaiBiaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kartu "Kompensasi Dibayar" di halaman Laporan menjumlahkan
 * `compensation_amount` tanpa memandang status. Kompensasi pada tiket yang
 * masih `open`/`handling` belum disetujui siapa pun, dan tiket `close`
 * beralasan `ditolak` tidak membayar apa pun — keduanya ikut terhitung
 * sebagai uang yang sudah keluar. Labelnya menjanjikan lebih dari yang
 * dijamin datanya. (API-106)
 *
 * Keputusannya: DIPECAH, bukan disaring. Membuang tiket berjalan dari total
 * menyembunyikan paparan yang sedang berjalan; menyebutnya "dibayar" adalah
 * pernyataan yang salah. Dua angka menjawab keduanya.
 *
 * Halaman Kerugian totalnya TIDAK berubah — ia memang melaporkan seluruh
 * biaya tercatat. Yang ditambahkan hanya kalimat yang menyebut bagian mana
 * dari total itu yang belum pasti.
 */
class KompensasiBelumPastiTest extends TestCase
{
    use RefreshDatabase;

    private function complaint(string $status, ?string $closeReason, int $kompensasi): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = $status;
        $complaint->close_reason = $closeReason;
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->compensation_amount = $kompensasi;
        $complaint->tindak_lanjut = 'compensate';
        $complaint->save();

        return $complaint;
    }

    private function halaman(string $url): string
    {
        $user = User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        return preg_replace('/\s+/', ' ', $this->actingAs($user)->get($url)->assertOk()->getContent());
    }

    /**
     * Tiga tiket dalam satu rentang — satu untuk tiap cara sebuah nilai bisa
     * BELUM pasti, plus yang benar-benar dibayar.
     */
    private function tigaTiket(): void
    {
        $this->complaint('close', 'selesai', 50_000);   // A — benar-benar dibayar
        $this->complaint('handling', null, 100_000);    // B — masih berjalan
        $this->complaint('close', 'ditolak', 70_000);   // C — ditutup ditolak
    }

    /* ---------- Aturannya satu tempat ---------- */

    public function test_sudah_pasti_hanya_close_yang_tidak_ditolak(): void
    {
        $this->assertTrue(NilaiBiaya::sudahPasti($this->complaint('close', 'selesai', 50_000)));
        // Data lama: 541 baris impor ditutup tanpa pernah mencatat alasannya.
        // Dianggap dibayar — itu asumsi yang ditulis di API-106, bukan
        // kelalaian. Kalau ternyata salah, angka "Dibayar" yang turun, bukan
        // bentuk pemisahannya.
        $this->assertTrue(NilaiBiaya::sudahPasti($this->complaint('close', null, 50_000)));
        $this->assertFalse(NilaiBiaya::sudahPasti($this->complaint('close', 'ditolak', 70_000)));
        $this->assertFalse(NilaiBiaya::sudahPasti($this->complaint('handling', null, 100_000)));
        $this->assertFalse(NilaiBiaya::sudahPasti($this->complaint('open', null, 100_000)));
    }

    public function test_nilai_nol_tidak_masuk_sisi_mana_pun(): void
    {
        // Kolomnya tidak nullable, jadi "tidak pernah diisi" tersimpan sebagai
        // 0. Ia bukan Rp 0 yang dibayar, dan bukan pula paparan yang berjalan.
        $this->complaint('close', 'selesai', 0);
        $this->complaint('handling', null, 0);

        $pisah = NilaiBiaya::pisah(Complaint::query()->get());

        $this->assertSame(0, $pisah['tiketPasti']);
        $this->assertSame(0, $pisah['tiketBelumPasti']);
    }

    /* ---------- Halaman Laporan: dua angka ---------- */

    public function test_laporan_memisah_dibayar_dari_belum_pasti(): void
    {
        $this->tigaTiket();

        $html = $this->halaman('/reports');

        // Dicocokkan pada KARTUNYA, bukan sekadar "angka ini ada di halaman":
        // Rp 220.000 memang masih muncul di catatan grafik biaya, dan memang
        // seharusnya — grafik melaporkan seluruh biaya tercatat dan tidak
        // diubah oleh API-106. Yang dijaga di sini label mana memikul angka
        // mana.
        $this->assertMatchesRegularExpression(
            '/Rp 50\.000<\/div> <div class="l">Kompensasi Dibayar/',
            $html,
            'kartu Dibayar hanya memuat tiket yang sudah pasti'
        );
        $this->assertMatchesRegularExpression(
            '/Rp 170\.000<\/div> <div class="l">Belum Pasti/',
            $html,
            'yang belum pasti: 100.000 berjalan + 70.000 ditolak'
        );

        // Sebelum API-106 kartu "Dibayar" berbunyi Rp 220.000 — seluruh nilai
        // tercatat, termasuk yang belum disetujui siapa pun.
        $this->assertDoesNotMatchRegularExpression(
            '/Rp 220\.000<\/div> <div class="l">Kompensasi Dibayar/',
            $html
        );
    }

    public function test_dibayar_tidak_pernah_memuat_tiket_berjalan_atau_ditolak(): void
    {
        $this->complaint('handling', null, 100_000);
        $this->complaint('close', 'ditolak', 70_000);

        $html = $this->halaman('/reports');

        // Tidak ada satu tiket pun yang sudah pasti: kartu Dibayar harus Rp 0,
        // bukan Rp 170.000.
        $this->assertMatchesRegularExpression(
            '/Rp 0<\/div> <div class="l">Kompensasi Dibayar/',
            $html,
            'kartu Dibayar kosong ketika tidak ada tiket yang sudah pasti'
        );
        $this->assertStringContainsString('Rp 170.000', $html);
    }

    /* ---------- Halaman Kerugian: total tetap, syaratnya disebut ---------- */

    public function test_kerugian_totalnya_tidak_berubah_tapi_menyebut_yang_belum_pasti(): void
    {
        $this->tigaTiket();

        $html = $this->halaman('/reports/kerugian');

        // Totalnya tetap seluruh biaya tercatat — halaman ini memang
        // melaporkan paparan, bukan hanya kas yang sudah keluar.
        $this->assertStringContainsString('Rp 220.000 tercatat', $html);
        $this->assertStringContainsString(
            'termasuk Rp 170.000 dari 2 tiket yang nilainya belum pasti',
            $html
        );
    }

    public function test_kerugian_tidak_menyebut_belum_pasti_kalau_tidak_ada(): void
    {
        $this->complaint('close', 'selesai', 50_000);

        $html = $this->halaman('/reports/kerugian');

        $this->assertStringContainsString('Rp 50.000 tercatat', $html);
        $this->assertStringNotContainsString('belum pasti', $html);
    }
}

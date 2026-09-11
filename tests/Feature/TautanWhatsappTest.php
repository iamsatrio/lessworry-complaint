<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dua dari tiga kanal masuk adalah WhatsApp, dan mengabari pelanggan adalah
 * langkah terakhir yang wajib pada setiap penutupan. Nomor pelapor yang
 * dicetak sebagai teks biasa berarti: blok dengan jari, salin, pindah
 * aplikasi, tempel, ketik pesan. (API-38 #10)
 */
class TautanWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function complaint(?string $telepon): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'reporter_phone' => $telepon,
            'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan',
            'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'handling';
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function cc(): User
    {
        return User::create([
            'name' => 'Customer Care', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function nomorProvider(): array
    {
        return [
            'nol depan' => ['081234567890', 'https://wa.me/6281234567890'],
            'tanpa nol depan' => ['81234567890', 'https://wa.me/6281234567890'],
            'sudah 62' => ['6281234567890', 'https://wa.me/6281234567890'],
            'pakai plus dan spasi' => ['+62 812-3456-7890', 'https://wa.me/6281234567890'],
            // Bentuk +62 yang ditulis dengan awalan panggilan internasional.
            // Sebelumnya 00 dibiarkan, lalu aturan "diawali 0 → 62" mengubahnya
            // jadi 62062812345678 — lolos, dan pesannya sampai ke orang lain.
            'awalan internasional 00' => ['0062812345678', 'https://wa.me/62812345678'],
            'awalan internasional 00 dengan plus' => ['+0062 812-3456-7890', 'https://wa.me/6281234567890'],
            // Telepon rumah Jakarta. Nomornya sah, tapi bukan nomor WhatsApp.
            'telepon rumah' => ['0211234567', null],
            'telepon rumah Surabaya' => ['0311234567', null],
            // Diawali 62 tapi bukan seluler: yang dituntut 628, bukan 62.
            'bukan seluler tapi diawali 62' => ['620000000000', null],
            'bukan seluler, panjang maksimum' => ['6200000000000', null],
            'terlalu pendek' => ['0812', null],
            'bukan angka' => ['tidak tahu', null],
            'kosong' => ['', null],
        ];
    }

    #[DataProvider('nomorProvider')]
    public function test_nomor_dinormalkan_atau_ditolak(string $telepon, ?string $awalan): void
    {
        $tautan = $this->complaint($telepon)->waLink();

        if ($awalan === null) {
            $this->assertNull($tautan, 'Nomor yang tidak bisa dinormalkan harus dibalas null, bukan ditebak.');

            return;
        }

        $this->assertSame($awalan, $tautan);
    }

    public function test_pesan_pembuka_memuat_nomor_tiket(): void
    {
        $complaint = $this->complaint('081234567890');

        $this->assertStringContainsString(
            rawurlencode('complaint '.$complaint->ticket_number),
            (string) $complaint->waLink('Halo, complaint '.$complaint->ticket_number.' sudah kami tindak lanjuti.')
        );
    }

    public function test_halaman_detail_memasang_tautan_whatsapp(): void
    {
        $complaint = $this->complaint('081234567890');

        $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('https://wa.me/6281234567890', false);
    }

    public function test_nomor_yang_tidak_bisa_dinormalkan_jatuh_ke_tautan_telepon(): void
    {
        $complaint = $this->complaint('0812');

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('https://wa.me/', $html);
        $this->assertStringContainsString('href="tel:0812"', $html);
    }

    public function test_complaint_tanpa_telepon_tidak_memasang_tautan(): void
    {
        $complaint = $this->complaint(null);

        $html = $this->actingAs($this->cc())
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('https://wa.me/', $html);
        $this->assertStringNotContainsString('href="tel:"', $html);
    }
}

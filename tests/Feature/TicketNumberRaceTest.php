<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua kasir menekan Simpan pada detik yang sama. (API-8 T5)
 *
 * Nomor tiket dihitung dari jumlah baris hari ini lalu +1, sedangkan
 * kolomnya punya indeks unik. Yang kedua kena UNIQUE constraint di dalam
 * DB::transaction — HTTP 500, complaint hilang, dan pelanggannya sudah
 * telanjur ditutup teleponnya. Ini kehilangan data, bukan sekadar galat.
 */
class TicketNumberRaceTest extends TestCase
{
    use RefreshDatabase;

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    private function complaint(?string $ticket = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = $ticket ?? Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function catatComplaint(User $user)
    {
        return $this->actingAs($user)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor Baru', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Keluhan', 'nota_exemption' => 'belum_terbit',
        ]);
    }

    public function test_nomor_tiket_tidak_mengulang_nomor_yang_sudah_dipakai(): void
    {
        $prefix = 'LW-'.now()->format('Ymd');

        // Ada lubang di urutan — mis. satu complaint dihapus, atau dibuat
        // dengan nomor eksplisit. Menghitung baris menghasilkan nomor yang
        // sudah dipakai.
        $this->complaint($prefix.'-001');
        $this->complaint($prefix.'-003');

        $this->assertSame($prefix.'-004', Complaint::nextTicketNumber());
    }

    public function test_nomor_tiket_yang_keburu_diambil_orang_lain_tidak_menghilangkan_complaint(): void
    {
        $sudahDipakai = $this->complaint()->ticket_number;

        // Meniru permintaan lain yang menyambar nomornya persis di antara
        // pembacaan dan penyimpanan kita.
        $sekali = true;
        Complaint::creating(function (Complaint $c) use (&$sekali, $sudahDipakai) {
            if ($sekali) {
                $sekali = false;
                $c->ticket_number = $sudahDipakai;
            }
        });

        $this->catatComplaint($this->cc())->assertRedirect();

        $this->assertSame(2, Complaint::count(), 'complaint kedua hilang saat nomornya bentrok');

        $baru = Complaint::latest('id')->first();

        $this->assertNotSame($sudahDipakai, $baru->ticket_number);
        $this->assertNotNull($baru->ticket_number);
    }

    public function test_bentrok_beruntun_tetap_menyerah_dengan_jujur_bukan_diam_diam(): void
    {
        $sudahDipakai = $this->complaint()->ticket_number;

        // Bentrok terus-menerus: nomor selalu disambar. Sistem boleh gagal —
        // yang tidak boleh adalah menyimpan complaint separuh jalan.
        Complaint::creating(fn (Complaint $c) => $c->ticket_number = $sudahDipakai);

        try {
            $this->catatComplaint($this->cc());
        } catch (\Throwable) {
            // Kegagalan yang terlihat memang yang diharapkan di sini.
        }

        $this->assertSame(1, Complaint::count(),
            'complaint separuh jadi tertinggal di basis data setelah bentrok beruntun');
    }

    public function test_complaint_berurutan_tetap_dapat_nomor_berbeda(): void
    {
        $cc = $this->cc();

        $this->catatComplaint($cc);
        $this->catatComplaint($cc);
        $this->catatComplaint($cc);

        $this->assertSame(3, Complaint::distinct()->count('ticket_number'));
    }
}

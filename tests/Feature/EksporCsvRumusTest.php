<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PerisaiRumus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ekspor CSV: nilai berawalan rumus. (API-77)
 *
 * `.xlsx` menutup lubang ini lewat tipe selnya. CSV tidak punya tipe sel, dan
 * CSV justru yang paling sering dibuka orang karena ia yang sudah ada sejak
 * lama. Nama pelapor `=1+1` yang ditulis telanjang ke CSV dievaluasi Excel
 * saat berkasnya dibuka — dan rekap ini diteruskan lewat WhatsApp dan email.
 *
 * Daftar awalannya dibaca dari PerisaiRumus, bukan ditulis ulang di sini:
 * awalan yang ditambahkan ke daftar itu nanti langsung teruji di jalur CSV.
 */
class EksporCsvRumusTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): User
    {
        return User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);
    }

    private function complaint(array $attr = []): Complaint
    {
        $outlet = Outlet::firstOrCreate(['name' => 'Cipete']);

        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Siti', 'reporter_phone' => '081234567890',
            'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan',
            'description' => 'x', 'outlet_id' => $outlet->id,
            'nevira_transaction_number' => 'NV-2026-000123',
        ], $attr));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'close';
        $complaint->close_reason = 'selesai';
        $complaint->created_at = Carbon::parse('2026-08-11 09:30');
        $complaint->applySla();
        $complaint->resolved_at = Carbon::parse('2026-08-12 10:00');
        $complaint->compensation_amount = 30_881_208;
        $complaint->save();

        return $complaint;
    }

    /** @return list<list<string>> */
    private function unduh(User $user): array
    {
        $csv = $this->actingAs($user)
            ->get('/reports/export?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->streamedContent();

        return array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    }

    /* ---------- Yang diuji: nilai berawalan rumus tidak lolos telanjang ---------- */

    /**
     * Gagal sebelum API-77: sel Pelapor berisi `=1+1` telanjang, dan Excel
     * mengevaluasinya.
     */
    public function test_nama_pelapor_berawalan_sama_dengan_tidak_lolos_telanjang(): void
    {
        $this->complaint(['reporter_name' => '=1+1']);

        $baris = $this->unduh($this->supervisor());
        $kolom = array_flip($baris[0]);
        $sel = $baris[1][$kolom['Pelapor']];

        $this->assertNotSame('=1+1', $sel, 'Nilai berawalan `=` ditulis telanjang ke CSV — Excel akan membacanya sebagai rumus.');
        $this->assertSame("'=1+1", $sel);
    }

    /** Setiap awalan pemicu, satu per satu, lewat jalur CSV sungguhan. */
    public function test_semua_awalan_pemicu_ditandai_teks(): void
    {
        $nama = [];

        foreach (PerisaiRumus::AWALAN_RUMUS as $awalan) {
            $nama[] = $awalan.'SUM(A1:A9)';
        }

        foreach ($nama as $n) {
            $this->complaint(['reporter_name' => $n]);
        }

        $baris = $this->unduh($this->supervisor());
        $kolom = array_flip($baris[0]);
        $sel = array_map(fn (array $b) => $b[$kolom['Pelapor']], array_slice($baris, 1));

        foreach ($nama as $n) {
            $this->assertContains("'".$n, $sel, 'Awalan '.json_encode($n[0]).' lolos telanjang ke CSV.');
            $this->assertNotContains($n, $sel);
        }
    }

    /**
     * Kriteria selesai nomor 3: tulisannya tidak boleh berubah. Yang
     * ditambahkan hanya penanda teks di depan — huruf-huruf nilainya sendiri
     * utuh, tidak ada yang dibuang atau diganti.
     */
    public function test_tulisan_nilainya_utuh_di_balik_penanda(): void
    {
        $this->complaint(['reporter_name' => '-Andi Pratama']);

        $baris = $this->unduh($this->supervisor());
        $kolom = array_flip($baris[0]);
        $sel = $baris[1][$kolom['Pelapor']];

        $this->assertSame('-Andi Pratama', ltrim($sel, "'"));
        $this->assertSame(1, substr_count($sel, "'"), 'Penanda teks ditambahkan lebih dari sekali.');
    }

    /* ---------- Yang TIDAK boleh ikut berubah ---------- */

    /**
     * Nilai biasa tidak boleh disentuh sama sekali: CSV ini dibaca alat lain,
     * dan perlindungan yang menandai semua sel adalah perubahan bentuk.
     */
    public function test_nilai_biasa_tidak_disentuh(): void
    {
        $this->complaint(['reporter_name' => 'Siti Rahayu']);

        $baris = $this->unduh($this->supervisor());
        $kolom = array_flip($baris[0]);

        $this->assertSame('Siti Rahayu', $baris[1][$kolom['Pelapor']]);
        $this->assertSame('081234567890', $baris[1][$kolom['Telepon']]);
        $this->assertSame('30881208', $baris[1][$kolom['Kompensasi']]);
        $this->assertSame('2026-08-11 09:30', $baris[1][$kolom['Dibuat']]);
        $this->assertSame('2026-08-12 10:00', $baris[1][$kolom['Selesai']]);
    }

    /**
     * Kolom angka tidak lewat perisainya. Kompensasi negatif tetap bilangan —
     * kolom yang berhenti jadi bilangan tidak bisa dijumlah lagi di lembar
     * sebarnya, dan itu rekap yang dipakai mengambil keputusan.
     */
    public function test_angka_negatif_tetap_bilangan_telanjang(): void
    {
        $complaint = $this->complaint();
        $complaint->compensation_amount = -5000;
        $complaint->save();

        $baris = $this->unduh($this->supervisor());
        $kolom = array_flip($baris[0]);

        $this->assertSame('-5000', $baris[1][$kolom['Kompensasi']]);
    }

    /** Judul kolom tidak berubah bentuknya. */
    public function test_judul_kolom_tidak_berubah(): void
    {
        $this->complaint();

        $baris = $this->unduh($this->supervisor());

        $this->assertSame('Nomor Tiket', $baris[0][0]);
        $this->assertNotContains("'Nomor Tiket", $baris[0]);
    }
}

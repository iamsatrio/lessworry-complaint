<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use App\Services\SebaranJarakKomplain;
use App\Services\TanggalPengambilan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sebaran jarak hari pengambilan -> complaint masuk, di halaman Laporan.
 * (API-48)
 *
 * Yang dijaga di sini bukan "kartunya muncul", tapi hal yang membuat kartunya
 * berbohong: complaint yang tanggal pengambilannya tidak diketahui ikut
 * terhitung sebagai "hari yang sama", dan sebaran dari 15% baris terbaca
 * seperti sebaran dari 100%.
 */
class SebaranJarakKomplainTest extends TestCase
{
    use RefreshDatabase;

    private function complaint(string $masuk, ?string $diambil, string $sumber = TanggalPengambilan::ANTAR): Complaint
    {
        $c = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->status = 'open';
        $c->created_at = Carbon::parse($masuk);
        $c->applySla();
        $c->tanggal_pengambilan = $diambil;
        $c->sumber_tanggal_pengambilan = $diambil === null ? TanggalPengambilan::TIDAK_DIKETAHUI : $sumber;
        $c->save();

        return $c;
    }

    private function sebaran(): SebaranJarakKomplain
    {
        /** @var EloquentCollection<int,Complaint> $semua */
        $semua = Complaint::all();

        return new SebaranJarakKomplain($semua);
    }

    /** @return array<string,int> */
    private function perKunci(SebaranJarakKomplain $sebaran): array
    {
        return collect($sebaran->rentang())->pluck('jumlah', 'kunci')->all();
    }

    public function test_tiap_rentang_dihitung_di_kotaknya_sendiri(): void
    {
        $this->complaint('2026-06-12 03:00:00', '2026-06-12');   // 0 hari
        $this->complaint('2026-06-13 03:00:00', '2026-06-12');   // 1 hari
        $this->complaint('2026-06-15 03:00:00', '2026-06-12');   // 3 hari
        $this->complaint('2026-06-19 03:00:00', '2026-06-12');   // 7 hari
        $this->complaint('2026-06-30 03:00:00', '2026-06-12');   // 18 hari
        $this->complaint('2026-07-20 03:00:00', '2026-06-12');   // 38 hari

        $this->assertSame(
            ['0' => 1, '1' => 1, '2_3' => 1, '4_7' => 1, '8_30' => 1, '30_plus' => 1],
            $this->perKunci($this->sebaran()),
        );
    }

    public function test_tanggal_yang_tidak_diketahui_dihitung_terpisah_bukan_jadi_nol_hari(): void
    {
        $this->complaint('2026-06-12 03:00:00', '2026-06-12');
        $this->complaint('2026-06-12 03:00:00', null);
        $this->complaint('2026-06-13 03:00:00', null);

        $sebaran = $this->sebaran();

        $this->assertSame(1, $this->perKunci($sebaran)['0']);
        $this->assertSame(2, $sebaran->tidakDiketahui());
        $this->assertSame(1, $sebaran->terukur());
        $this->assertSame(3, $sebaran->total());
    }

    public function test_cakupan_dihitung_dari_seluruh_complaint_bukan_dari_yang_terukur(): void
    {
        // Angka dari 1 dari 5 baris tidak boleh terlihat seperti angka dari
        // 5 baris. Inilah satu-satunya hal yang menahan pembaca menyimpulkan
        // terlalu banyak dari terlalu sedikit.
        $this->complaint('2026-06-12 03:00:00', '2026-06-12');

        foreach (range(1, 4) as $i) {
            $this->complaint('2026-06-12 03:00:00', null);
        }

        $this->assertSame(20.0, $this->sebaran()->cakupanPersen());
    }

    public function test_complaint_yang_mendahului_pengambilan_tidak_menggemukkan_hari_yang_sama(): void
    {
        $this->complaint('2026-06-10 03:00:00', '2026-06-12');

        $sebaran = $this->sebaran();

        $this->assertSame(0, $this->perKunci($sebaran)['0']);
        $this->assertSame(1, $sebaran->sebelumPengambilan());
        $this->assertSame(0, $sebaran->tidakDiketahui());
    }

    public function test_tanpa_satu_pun_tanggal_kartunya_mengaku_kosong(): void
    {
        $this->complaint('2026-06-12 03:00:00', null);

        $this->assertTrue($this->sebaran()->kosong());
        $this->assertSame(0.0, $this->sebaran()->cakupanPersen());
    }

    public function test_halaman_laporan_menyebut_cakupannya(): void
    {
        $this->complaint('2026-06-12 03:00:00', '2026-06-12');
        $this->complaint('2026-06-12 03:00:00', null);

        $supervisor = User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        $this->actingAs($supervisor)
            ->get('/reports?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertSee('Jarak complaint dari tanggal pengambilan')
            ->assertSee('Tanggal pengambilan tidak diketahui')
            ->assertSee('50%');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\GrafikLaporan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Grafik halaman Laporan. (API-52)
 *
 * Yang dijaga di sini bukan "grafiknya muncul", tapi hal-hal yang membuat
 * sebuah grafik BERBOHONG tanpa terlihat berbohong: pembagi yang salah,
 * biaya tanpa cakupannya, kolom kosong yang dihitung sebagai nol, dan
 * garis yang memperlihatkan outlet yang tidak boleh dilihat pembacanya.
 */
class GrafikLaporanTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $waktu, ?Outlet $outlet = null, array $attr = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ], array_diff_key($attr, array_flip(['compensation_amount', 'resolved_at', 'status']))));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = $attr['status'] ?? 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();

        if (isset($attr['compensation_amount'])) {
            $complaint->compensation_amount = $attr['compensation_amount'];
        }

        if (isset($attr['resolved_at'])) {
            $complaint->resolved_at = Carbon::parse($attr['resolved_at']);
        }

        $complaint->save();

        return $complaint;
    }

    private function grafik(User $user, string $dari, string $sampai): GrafikLaporan
    {
        return new GrafikLaporan($user, Complaint::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [Carbon::parse($dari), Carbon::parse($sampai)])
            ->with('outlet')
            ->get());
    }

    private function laporan(User $user, string $dari, string $sampai): string
    {
        return $this->actingAs($user)
            ->get('/reports?from='.$dari.'&to='.$sampai)
            ->assertOk()
            ->getContent();
    }

    /* ---------- Kriteria 2: pembagi adalah outlet yang aktif BULAN ITU ---------- */

    public function test_outlet_yang_complaint_pertamanya_agustus_tidak_ikut_membagi_juli(): void
    {
        $lama = Outlet::create(['name' => 'Outlet Lama']);
        $baru = Outlet::create(['name' => 'Outlet Baru']);

        foreach (['2026-07-03 09:00', '2026-07-10 09:00', '2026-07-18 09:00', '2026-07-25 09:00'] as $waktu) {
            $this->complaint($waktu, $lama);
        }

        $this->complaint('2026-08-04 09:00', $lama);
        $this->complaint('2026-08-11 09:00', $lama);
        $this->complaint('2026-08-14 09:00', $baru);
        $this->complaint('2026-08-20 09:00', $baru);

        $bulanan = $this->grafik($this->userAs('supervisor'), '2026-07-01', '2026-08-31 23:59')->perBulan();

        $juli = collect($bulanan)->firstWhere('bulan', '2026-07');
        $agustus = collect($bulanan)->firstWhere('bulan', '2026-08');

        $this->assertSame(1, $juli['outlet'],
            'Outlet Baru belum menerima complaint apa pun pada Juli, jadi tidak boleh ikut membagi bulan Juli.');
        $this->assertSame(4.0, $juli['per']);

        $this->assertSame(2, $agustus['outlet']);
        $this->assertSame(2.0, $agustus['per'], '4 complaint dibagi 2 outlet yang sudah aktif.');
    }

    public function test_pembagi_memakai_seluruh_sejarah_bukan_hanya_rentang_yang_dipilih(): void
    {
        $lama = Outlet::create(['name' => 'Outlet Lama']);
        $baru = Outlet::create(['name' => 'Outlet Baru']);

        $this->complaint('2026-07-03 09:00', $lama);
        $this->complaint('2026-08-04 09:00', $lama);
        $this->complaint('2026-08-14 09:00', $baru);

        // Rentang hanya Agustus. Outlet Lama tetap terhitung aktif walau
        // complaint pertamanya di luar rentang — kalau tidak, memilih rentang
        // sempit membuat semua outlet seolah-olah baru buka bulan itu.
        $agustus = collect($this->grafik($this->userAs('supervisor'), '2026-08-01', '2026-08-31 23:59')->perBulan())
            ->firstWhere('bulan', '2026-08');

        $this->assertSame(2, $agustus['outlet']);
        $this->assertSame(1.0, $agustus['per']);
    }

    public function test_bulan_tanpa_outlet_aktif_tidak_digambar_sebagai_nol(): void
    {
        // Complaint tanpa outlet sama sekali: pembilangnya tidak bisa dibagi.
        $this->complaint('2026-07-03 09:00');

        $juli = collect($this->grafik($this->userAs('supervisor'), '2026-07-01', '2026-07-31 23:59')->perBulan())
            ->firstWhere('bulan', '2026-07');

        $this->assertNull($juli['per'], 'Tidak ada pembagi berarti angkanya TIDAK ADA, bukan nol.');
        $this->assertSame(0, $juli['outlet']);
    }

    /* ---------- Kriteria 3 dan 4: biaya, cakupan, dan kolom kosong ---------- */

    public function test_complaint_tanpa_nilai_biaya_tidak_dihitung_sebagai_nol(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);

        $this->complaint('2026-07-03 09:00', $outlet, ['compensation_amount' => 100_000]);
        $this->complaint('2026-07-04 09:00', $outlet);
        $this->complaint('2026-07-05 09:00', $outlet);

        $grafik = $this->grafik($this->userAs('supervisor'), '2026-07-01', '2026-07-31 23:59');
        $kategori = collect($grafik->biayaPerKategori())->firstWhere('kategori', 'kurang_bersih');

        $this->assertSame(3, $kategori['kasus']);
        $this->assertSame(1, $kategori['terisi']);
        $this->assertSame(100_000, $kategori['biaya']);
        $this->assertSame(100_000, $kategori['rata'],
            'Rata-rata dihitung atas complaint yang PUNYA nilai. Dua kolom kosong bukan dua kali Rp 0.');

        $cakupan = $grafik->cakupanBiaya();
        $this->assertSame(1, $cakupan['terisi']);
        $this->assertSame(3, $cakupan['total']);
        $this->assertSame(33, $cakupan['persen']);
        $this->assertTrue($cakupan['rendah']);
    }

    public function test_setiap_total_biaya_disertai_cakupannya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet, ['compensation_amount' => 100_000]);
        $this->complaint('2026-07-04 09:00', $outlet);

        $html = $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-07-31');

        $this->assertStringContainsString('dari 1 dari 2 complaint yang punya nilai biaya', $html);
    }

    public function test_periode_dengan_cakupan_di_bawah_setengah_ditandai(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet, ['compensation_amount' => 100_000]);
        $this->complaint('2026-07-04 09:00', $outlet);
        $this->complaint('2026-07-05 09:00', $outlet);

        $this->assertStringContainsString(
            'Cakupan biaya periode ini hanya 33%',
            $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-07-31'),
        );
    }

    public function test_periode_dengan_cakupan_penuh_tidak_ditandai(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet, ['compensation_amount' => 100_000]);
        $this->complaint('2026-07-04 09:00', $outlet, ['compensation_amount' => 50_000]);

        $this->assertStringNotContainsString(
            'Cakupan biaya periode ini hanya',
            $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-07-31'),
        );
    }

    /* ---------- Kriteria 1 dan 5: keempat grafik, ikut rentang, punya tabel ---------- */

    public function test_keempat_grafik_tampil_dengan_tabel_angkanya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet, [
            'category' => 'barang_rusak', 'compensation_amount' => 250_000,
            'status' => 'close', 'resolved_at' => '2026-07-05 09:00',
        ]);

        $html = $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-07-31');

        foreach ([
            'Complaint per outlet per bulan',
            'Biaya complaint per kategori',
            'Barang Rusak per bulan — jumlah kasus',
            'Median waktu penyelesaian per bulan',
        ] as $judul) {
            $this->assertStringContainsString($judul, $html);
        }

        $this->assertSame(4, substr_count($html, 'Lihat angkanya sebagai tabel'),
            'Tiap grafik wajib punya tabel angka yang bisa dibuka — itu jalannya bagi pembaca layar '
            .'dan bagi yang ingin menyalin angkanya.');
    }

    public function test_grafik_ikut_berubah_saat_rentang_tanggalnya_diubah(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);
        $this->complaint('2026-08-04 09:00', $outlet);

        $user = $this->userAs('supervisor');

        $juliSaja = $this->laporan($user, '2026-07-01', '2026-07-31');
        $duaBulan = $this->laporan($user, '2026-07-01', '2026-08-31');

        $this->assertStringNotContainsString('Agu 26', $juliSaja);
        $this->assertStringContainsString('Agu 26', $duaBulan);
    }

    public function test_waktu_penyelesaian_memakai_median_bukan_rata_rata(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);

        // Dua kasus 1 hari dan satu kasus 41 hari. Rata-ratanya 14,3 hari —
        // angka yang tidak menggambarkan satu pun kasus yang benar-benar
        // terjadi. Mediannya 1 hari.
        $this->complaint('2026-07-03 09:00', $outlet, ['status' => 'close', 'resolved_at' => '2026-07-04 09:00']);
        $this->complaint('2026-07-05 09:00', $outlet, ['status' => 'close', 'resolved_at' => '2026-07-06 09:00']);
        $this->complaint('2026-07-06 09:00', $outlet, ['status' => 'close', 'resolved_at' => '2026-08-16 09:00']);

        $juli = collect($this->grafik($this->userAs('supervisor'), '2026-07-01', '2026-07-31 23:59')->medianPenyelesaian())
            ->firstWhere('bulan', '2026-07');

        $this->assertSame(1.0, $juli['median']);
        $this->assertSame(3, $juli['n']);
    }

    /* ---------- Grafik 3: kerugian dihitung mentah, bukan per outlet ---------- */

    public function test_barang_rusak_digambar_sebagai_jumlah_kasus_bukan_per_outlet(): void
    {
        $outlets = [];

        for ($i = 1; $i <= 11; $i++) {
            $outlets[] = Outlet::create(['name' => 'Outlet '.$i]);
        }

        // Maret: 9 kasus di 5 outlet. Agustus: 12 kasus di 11 outlet.
        for ($i = 0; $i < 9; $i++) {
            $this->complaint('2026-03-0'.(($i % 9) + 1).' 09:00', $outlets[$i % 5], ['category' => 'barang_rusak']);
        }

        for ($i = 0; $i < 12; $i++) {
            $this->complaint('2026-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 09:00',
                $outlets[$i % 11], ['category' => 'barang_rusak']);
        }

        $grafik = $this->grafik($this->userAs('supervisor'), '2026-03-01', '2026-08-31 23:59');
        $titik = collect($grafik->titikBarangRusak())->keyBy('label');

        $this->assertSame(9.0, $titik['Mar 26']['nilai']);
        $this->assertSame(12.0, $titik['Agu 26']['nilai']);
        $this->assertGreaterThan($titik['Mar 26']['nilai'], $titik['Agu 26']['nilai'],
            'Bulan dengan kasus lebih banyak harus tergambar lebih tinggi. Pembagi per outlet benar untuk '
            .'mutu dan salah untuk kerugian: dibagi jumlah outlet, Agustus (12 kasus, 11 outlet) justru '
            .'jatuh di bawah Maret (9 kasus, 5 outlet).');

        // Pembalikan itu nyata, bukan kekhawatiran teoretis — grafik 1 memang
        // membalik urutan kedua bulan ini, dan di sana pembagi itu benar.
        $bulanan = collect($grafik->perBulan())->keyBy('bulan');
        $this->assertGreaterThan($bulanan['2026-08']['per'], $bulanan['2026-03']['per']);
    }

    /* ---------- Kriteria 6: wewenang yang sama dengan tabelnya ---------- */

    public function test_kasir_tidak_melihat_outlet_lain_di_grafik(): void
    {
        $milikKasir = Outlet::create(['name' => 'Outlet Kasir']);
        $lain = Outlet::create(['name' => 'Outlet Rahasia']);

        $this->complaint('2026-07-03 09:00', $milikKasir);
        $this->complaint('2026-07-04 09:00', $milikKasir);

        foreach (['2026-07-05 09:00', '2026-07-06 09:00', '2026-07-07 09:00'] as $waktu) {
            $this->complaint($waktu, $lain, ['compensation_amount' => 900_000]);
        }

        $html = $this->laporan($this->userAs('kasir', $milikKasir), '2026-07-01', '2026-07-31');

        $this->assertStringNotContainsString('Outlet Rahasia', $html);
        $this->assertStringNotContainsString('900.000', $html,
            'Biaya outlet lain tidak boleh bocor lewat grafik biaya.');

        // Pembilang DAN pembagi ikut disaring: kasir melihat satu outlet,
        // jadi angkanya 2 complaint dibagi 1 outlet — bukan dibagi 2.
        $this->assertStringContainsString('2 per outlet (2 complaint, 1 outlet)', $html);
    }

    public function test_pembagi_kasir_tidak_membocorkan_jumlah_outlet_jaringan(): void
    {
        $milikKasir = Outlet::create(['name' => 'Outlet Kasir']);
        $lain = Outlet::create(['name' => 'Outlet Rahasia']);

        $this->complaint('2026-07-03 09:00', $milikKasir);
        $this->complaint('2026-07-04 09:00', $lain);

        $kasir = $this->userAs('kasir', $milikKasir);
        $juli = collect($this->grafik($kasir, '2026-07-01', '2026-07-31 23:59')->perBulan())
            ->firstWhere('bulan', '2026-07');

        $this->assertSame(1, $juli['outlet']);
        $this->assertSame(1.0, $juli['per']);
    }

    /* ---------- Kriteria 7: periode kosong tidak memecahkan halaman ---------- */

    public function test_periode_tanpa_data_tampil_sebagai_keadaan_kosong(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertSee('Tidak ada data pada periode ini');
    }

    public function test_periode_tanpa_complaint_yang_selesai_tidak_memecahkan_grafik_median(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);

        $html = $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-07-31');

        $this->assertStringContainsString('Median waktu penyelesaian per bulan', $html);
        $this->assertStringContainsString('Belum ada angka yang bisa digambar untuk periode ini.', $html);
    }

    /* ---------- Kriteria 8: tidak ada pustaka grafik ---------- */

    public function test_grafik_digambar_di_server_tanpa_pustaka_javascript(): void
    {
        $paket = json_decode((string) file_get_contents(base_path('package.json')), true);
        $terpasang = array_keys(array_merge(
            $paket['dependencies'] ?? [],
            $paket['devDependencies'] ?? [],
            $paket['optionalDependencies'] ?? [],
        ));

        foreach (['chart.js', 'apexcharts', 'echarts', 'd3', 'plotly.js', 'highcharts', 'recharts', 'chartist'] as $pustaka) {
            $this->assertNotContains($pustaka, $terpasang, 'Grafik halaman Laporan digambar sebagai SVG di server.');
        }

        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet, ['compensation_amount' => 100_000]);
        $this->complaint('2026-08-03 09:00', $outlet, ['compensation_amount' => 100_000]);

        $html = $this->laporan($this->userAs('supervisor'), '2026-07-01', '2026-08-31');

        // Angka, garis, dan batangnya sudah ada di dalam HTML yang dikirim
        // server: tidak ada skrip yang perlu jalan supaya grafiknya terlihat.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<path', $html);
        $this->assertStringContainsString('<rect', $html);
        $this->assertStringContainsString('Rp 200.000', $html);
    }
}

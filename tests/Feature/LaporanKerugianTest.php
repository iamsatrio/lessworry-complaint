<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\RekapKerugian;
use App\Services\SatuanWaktu;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Halaman Kerugian. (API-43)
 *
 * Yang dijaga di sini bukan "halamannya muncul", tapi empat cara halaman ini
 * bisa BERBOHONG tanpa terlihat berbohong:
 *
 * 1. Total tanpa cakupannya. Kolom biaya terisi 96% pada 2025 dan 38% pada
 *    2026; halaman yang menjumlah apa adanya akan menyimpulkan kerugian 2026
 *    turun setengah, padahal yang turun pengisian kolomnya.
 * 2. Kolom kosong dihitung sebagai Rp 0 — menarik turun setiap rata-rata
 *    dengan angka yang tidak pernah ada orang yang mencatatnya.
 * 3. Uang keluar dijumlahkan dengan kerja ulang tanpa dibedakan, dan
 *    selisihnya hilang tanpa ada yang menyebutnya.
 * 4. Biaya outlet lain terlihat oleh kasir.
 */
class LaporanKerugianTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    /** @param  array<string,mixed>  $attr */
    private function complaint(string $waktu, ?Outlet $outlet = null, array $attr = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ], array_diff_key($attr, array_flip(['compensation_amount', 'tindak_lanjut']))));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();
        $complaint->compensation_amount = (int) ($attr['compensation_amount'] ?? 0);
        // Keduanya di luar `$fillable` — diisi langsung, seperti yang
        // dilakukan ComplaintStatusController saat penanganan ditutup.
        $complaint->tindak_lanjut = $attr['tindak_lanjut'] ?? null;
        $complaint->save();

        return $complaint;
    }

    /** @return EloquentCollection<int,Complaint> */
    private function semua(): EloquentCollection
    {
        return Complaint::query()->with('outlet')->get();
    }

    private function rekap(SatuanWaktu $satuan = SatuanWaktu::Bulanan): RekapKerugian
    {
        return new RekapKerugian($this->semua(), $satuan);
    }

    /* ---------- Kosong bukan nol ---------- */

    public function test_complaint_tanpa_nilai_tidak_dihitung_sebagai_nol(): void
    {
        $this->complaint('2026-03-02', null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-03-03', null, ['compensation_amount' => 200_000, 'tindak_lanjut' => 'compensate']);
        // Tiga complaint tanpa nilai tercatat. Kalau ikut dihitung sebagai
        // Rp 0, rata-ratanya jatuh dari 150.000 ke 60.000 — turun dua setengah
        // kali tanpa satu pun biaya yang berubah.
        $this->complaint('2026-03-04');
        $this->complaint('2026-03-05');
        $this->complaint('2026-03-06');

        $ringkasan = $this->rekap()->ringkasan();

        $this->assertSame(300_000, $ringkasan['biaya']);
        $this->assertSame(2, $ringkasan['terisi'], 'yang punya nilai hanya dua');
        $this->assertSame(5, $ringkasan['total']);
        $this->assertSame(150_000, $ringkasan['rata'], 'pembaginya yang punya nilai, bukan seluruh complaint');
        $this->assertSame(150_000, $ringkasan['median']);
        $this->assertSame(40, $ringkasan['persen']);
        $this->assertTrue($ringkasan['rendah'], 'cakupan 40% ada di bawah ambang');
    }

    public function test_median_tidak_ikut_tertarik_satu_kasus_termahal(): void
    {
        foreach ([50_000, 60_000, 70_000, 80_000] as $nilai) {
            $this->complaint('2026-04-02', null, ['compensation_amount' => $nilai, 'tindak_lanjut' => 'repair']);
        }
        $this->complaint('2026-04-03', null, ['compensation_amount' => 3_330_000, 'tindak_lanjut' => 'compensate']);

        $ringkasan = $this->rekap()->ringkasan();

        // Rata-rata 718.000 karena satu kasus; median 70.000 tetap menjawab
        // "berapa biasanya".
        $this->assertSame(718_000, $ringkasan['rata']);
        $this->assertSame(70_000, $ringkasan['median']);
        $this->assertSame(3_330_000, $ringkasan['tertinggi']);
    }

    /* ---------- Uang keluar vs kerja ulang ---------- */

    public function test_uang_keluar_dan_kerja_ulang_dipisah_dan_jumlahnya_utuh(): void
    {
        $this->complaint('2026-05-02', null, ['compensation_amount' => 1_000_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-05-03', null, ['compensation_amount' => 200_000, 'tindak_lanjut' => 'voucher']);
        $this->complaint('2026-05-04', null, ['compensation_amount' => 300_000, 'tindak_lanjut' => 'proses_ulang']);
        $this->complaint('2026-05-05', null, ['compensation_amount' => 150_000, 'tindak_lanjut' => 'repair']);
        $this->complaint('2026-05-06', null, ['compensation_amount' => 50_000, 'tindak_lanjut' => 'pickup_ulang']);
        // Dua baris yang bukan keduanya — tracking yang berbiaya, dan satu
        // yang tindak lanjutnya belum diisi. Pada data nyata dua jenis ini
        // membawa sekitar Rp 1,1 juta; tanpa golongan ketiga, selisihnya
        // hilang tanpa ada yang menyebutnya.
        $this->complaint('2026-05-07', null, ['compensation_amount' => 25_000, 'tindak_lanjut' => 'tracking']);
        $this->complaint('2026-05-08', null, ['compensation_amount' => 75_000]);

        $golongan = collect($this->rekap()->golongan())->keyBy('kunci');

        $this->assertSame(1_200_000, $golongan['uang_keluar']['biaya']);
        $this->assertSame(500_000, $golongan['kerja_ulang']['biaya']);
        $this->assertSame(100_000, $golongan['lainnya']['biaya']);

        // Invariannya: ketiganya berjumlah sama dengan totalnya. Selalu.
        $this->assertSame(
            $this->rekap()->ringkasan()['biaya'],
            $golongan->sum('biaya'),
            'jumlah ketiga golongan harus sama dengan total biaya',
        );
    }

    public function test_setiap_golongan_membawa_cakupannya(): void
    {
        $this->complaint('2026-05-02', null, ['compensation_amount' => 1_000_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-05-03', null, ['tindak_lanjut' => 'compensate']);
        $this->complaint('2026-05-04', null, ['tindak_lanjut' => 'compensate']);

        $golongan = collect($this->rekap()->golongan())->keyBy('kunci');

        $this->assertSame(3, $golongan['uang_keluar']['kasus']);
        $this->assertSame(1, $golongan['uang_keluar']['terisi']);
        $this->assertSame(33, $golongan['uang_keluar']['persen']);
        $this->assertTrue($golongan['uang_keluar']['rendah']);
    }

    /* ---------- Cakupan per periode ---------- */

    public function test_periode_bercakupan_rendah_ditandai(): void
    {
        // Dua bulan dengan biaya tercatat yang mirip, tapi cakupan yang jauh
        // berbeda — persis bentuk 2025 vs 2026 pada data nyata.
        foreach (range(1, 9) as $i) {
            $this->complaint('2026-01-0'.$i, null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        }
        $this->complaint('2026-01-10');

        foreach (range(1, 3) as $i) {
            $this->complaint('2026-02-0'.$i, null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        }
        foreach (range(4, 9) as $i) {
            $this->complaint('2026-02-0'.$i);
        }

        $periode = collect($this->rekap()->perPeriode())->keyBy('periode');

        $this->assertSame(90, $periode['2026-01']['persen']);
        $this->assertFalse($periode['2026-01']['rendah']);

        $this->assertSame(33, $periode['2026-02']['persen']);
        $this->assertTrue($periode['2026-02']['rendah'], 'cakupan 33% wajib ditandai');

        $this->assertSame(1, $this->rekap()->periodeRendah());
    }

    public function test_periode_tanpa_complaint_bukan_cakupan_nol_persen(): void
    {
        $this->complaint('2026-01-05', null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-03-05', null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);

        $periode = collect($this->rekap()->perPeriode())->keyBy('periode');

        // Februari ada di sumbunya — bulan kosong di tengah tetap digambar —
        // tapi cakupannya TIDAK ADA, bukan 0%. Nol berarti "diukur, hasilnya
        // kosong"; null berarti "tidak terukur".
        $this->assertArrayHasKey('2026-02', $periode->all());
        $this->assertNull($periode['2026-02']['persen']);
        $this->assertFalse($periode['2026-02']['rendah']);

        $titik = collect($this->rekap()->titikCakupan())->firstWhere('judul', $periode['2026-02']['judul']);
        $this->assertNotNull($titik);
        $this->assertNull($titik['nilai'], 'titik tanpa complaint tidak digambar sebagai 0%');
    }

    /* ---------- Pengelompokan ---------- */

    public function test_pengelompokan_membawa_kasus_terisi_biaya_dan_cakupan(): void
    {
        $jakarta = Outlet::create(['name' => 'Cipete', 'code' => 'CPT']);

        $this->complaint('2026-06-02', $jakarta, ['category' => 'barang_rusak', 'compensation_amount' => 2_000_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-06-03', $jakarta, ['category' => 'barang_rusak']);
        $this->complaint('2026-06-04', $jakarta, ['category' => 'kurang_bersih', 'compensation_amount' => 50_000, 'tindak_lanjut' => 'proses_ulang']);

        $kategori = collect($this->rekap()->perKategori())->keyBy('label');

        // Terurut biaya: kategori yang paling sering muncul belum tentu yang
        // paling mahal — itu isi pesan halaman ini.
        $this->assertSame('Barang Rusak', $this->rekap()->perKategori()[0]['label']);
        $this->assertSame(2, $kategori['Barang Rusak']['kasus']);
        $this->assertSame(1, $kategori['Barang Rusak']['terisi']);
        $this->assertSame(2_000_000, $kategori['Barang Rusak']['biaya']);
        $this->assertSame(50, $kategori['Barang Rusak']['persen']);

        $outlet = collect($this->rekap()->perOutlet())->keyBy('label');
        $this->assertSame(2_050_000, $outlet['Cipete']['biaya']);
        $this->assertSame(3, $outlet['Cipete']['kasus']);
    }

    public function test_complaint_tanpa_outlet_tidak_hilang_dari_pengelompokan(): void
    {
        $this->complaint('2026-06-02', null, ['compensation_amount' => 90_000, 'tindak_lanjut' => 'compensate']);

        $outlet = collect($this->rekap()->perOutlet())->keyBy('label');

        $this->assertArrayHasKey('Tanpa outlet', $outlet->all());
        $this->assertSame(90_000, $outlet['Tanpa outlet']['biaya']);
    }

    /* ---------- Wewenang ---------- */

    public function test_kasir_hanya_melihat_biaya_outletnya(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete', 'code' => 'CPT']);
        $lebak = Outlet::create(['name' => 'Lebak Bulus', 'code' => 'LBB']);

        $this->complaint('2026-07-02', $cipete, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-07-03', $lebak, ['compensation_amount' => 9_000_000, 'tindak_lanjut' => 'compensate']);

        $kasir = $this->userAs('kasir', $cipete);

        $halaman = $this->actingAs($kasir)->get(route('reports.kerugian', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $halaman->assertOk();
        $halaman->assertSee('Rp 100.000');
        $halaman->assertDontSee('Rp 9.000.000');
        $halaman->assertDontSee('Lebak Bulus');
    }

    public function test_kasir_tidak_bisa_meminta_outlet_lain_lewat_url(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete', 'code' => 'CPT']);
        $lebak = Outlet::create(['name' => 'Lebak Bulus', 'code' => 'LBB']);

        $kasir = $this->userAs('kasir', $cipete);

        // Ditolak di sisi server, bukan dengan menyembunyikan pilihannya di
        // halaman. Berlaku untuk halamannya DAN unduhannya.
        $this->actingAs($kasir)->get(route('reports.kerugian', ['outlet' => $lebak->id]))->assertForbidden();
        $this->actingAs($kasir)->get(route('reports.kerugian.export', ['outlet' => $lebak->id]))->assertForbidden();
    }

    public function test_unduhan_kasir_tidak_memuat_baris_outlet_lain(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete', 'code' => 'CPT']);
        $lebak = Outlet::create(['name' => 'Lebak Bulus', 'code' => 'LBB']);

        $milikKasir = $this->complaint('2026-07-02', $cipete, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $milikOrangLain = $this->complaint('2026-07-03', $lebak, ['compensation_amount' => 9_000_000, 'tindak_lanjut' => 'compensate']);

        $isi = $this->actingAs($this->userAs('kasir', $cipete))
            ->get(route('reports.kerugian.export', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->streamedContent();

        $this->assertStringContainsString($milikKasir->ticket_number, $isi);
        $this->assertStringNotContainsString($milikOrangLain->ticket_number, $isi);
    }

    /* ---------- Halaman ini membaca ---------- */

    public function test_tidak_ada_rute_yang_mengubah_apa_pun_di_halaman_ini(): void
    {
        $rute = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'reports.kerugian'));

        $this->assertTrue($rute->isNotEmpty(), 'rutenya harus ada');

        foreach ($rute as $r) {
            $this->assertSame(
                ['GET', 'HEAD'],
                array_values($r->methods()),
                'halaman Kerugian membaca; nilai kompensasi tidak boleh bisa diubah dari sini',
            );
        }
    }

    /* ---------- Unduhan ---------- */

    public function test_unduhan_hanya_berisi_complaint_yang_punya_nilai(): void
    {
        $berbiaya = $this->complaint('2026-08-02', null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $tanpaNilai = $this->complaint('2026-08-03');

        $isi = $this->actingAs($this->userAs('supervisor'))
            ->get(route('reports.kerugian.export', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->streamedContent();

        $this->assertStringContainsString($berbiaya->ticket_number, $isi);
        $this->assertStringNotContainsString($tanpaNilai->ticket_number, $isi);
        $this->assertStringContainsString('Golongan Biaya', $isi);
        $this->assertStringContainsString('Uang keluar', $isi);
        // Data pribadi pelapor tidak ikut keluar dari sistem lewat berkas ini.
        $this->assertStringNotContainsString('Pelapor', $isi);
    }

    /* ---------- Halamannya sendiri ---------- */

    public function test_halaman_menyertakan_cakupan_di_sebelah_totalnya(): void
    {
        $this->complaint('2026-08-02', null, ['compensation_amount' => 100_000, 'tindak_lanjut' => 'compensate']);
        $this->complaint('2026-08-03');
        $this->complaint('2026-08-04');

        $halaman = $this->actingAs($this->userAs('supervisor'))
            ->get(route('reports.kerugian', ['from' => '2026-08-01', 'to' => '2026-08-31']));

        $halaman->assertOk();
        $halaman->assertSee('Rp 100.000');
        $halaman->assertSee('dari 1 dari 3 complaint yang punya nilai biaya');
        // Cakupan 33% ada di bawah ambang, jadi peringatannya wajib muncul.
        $halaman->assertSee('Cakupan biaya periode ini hanya 33%.');
    }

    public function test_halaman_tetap_berdiri_saat_tidak_ada_complaint(): void
    {
        $halaman = $this->actingAs($this->userAs('supervisor'))->get(route('reports.kerugian'));

        $halaman->assertOk();
        $halaman->assertSee('Tidak ada complaint pada periode ini');
    }

    public function test_rentang_bawaannya_dua_belas_bulan(): void
    {
        Carbon::setTestNow('2026-09-14 10:00:00');

        $lama = $this->complaint('2026-01-05', null, ['compensation_amount' => 111_000, 'tindak_lanjut' => 'compensate']);
        // Di luar 12 bulan: tidak ikut, tapi tetap bisa dipanggil dengan
        // mengubah rentangnya sendiri.
        $jauh = $this->complaint('2025-01-05', null, ['compensation_amount' => 999_000, 'tindak_lanjut' => 'compensate']);

        $halaman = $this->actingAs($this->userAs('supervisor'))->get(route('reports.kerugian'));

        $halaman->assertOk();
        $halaman->assertSee('Rp 111.000');
        $halaman->assertDontSee('Rp 999.000');

        $this->assertNotNull($lama->id);
        $this->assertNotNull($jauh->id);

        Carbon::setTestNow();
    }
    /* ---------- Dari impor sampai layar ---------- */

    /**
     * Jalur penuh: berkas CSV lama diimpor, lalu halaman Kerugian membacanya.
     *
     * Yang diperiksa bukan satu angka hafalan, tapi bahwa angka di layar
     * BERASAL dari basis data dan tidak kehilangan satu baris pun di tengah
     * jalan — totalnya, cakupannya, dan jumlah ketiga golongannya semuanya
     * dihitung ulang dari basis data dan harus cocok.
     *
     * Angka 545 baris sungguhan tidak ada di repositori ini (data pelanggan),
     * jadi yang diuji di sini bentuk berkasnya, bukan isinya. Kecocokan
     * dengan total Rp 30.760.329 hanya bisa diperiksa pada basis data yang
     * sudah berisi impor itu.
     */
    public function test_total_di_layar_sama_dengan_yang_tersimpan_setelah_impor(): void
    {
        foreach ([
            '1' => 'Less Worry 1 - Kemang', '2' => 'Less Worry 2 - Cipete',
            '3' => 'Less Worry 3 - Hampton GS', '3.1' => 'Less Worry 3.1 - Duren Tiga',
            '4' => 'Less Worry 4 - Tebet', '8' => 'Less Worry 8 - Jatipadang',
            '9' => 'Less Worry 9 - Park Sepong',
        ] as $idNevira => $nama) {
            Outlet::create(['name' => $nama, 'nevira_outlet_id' => $idNevira]);
        }

        $laporan = storage_path('app/impor-uji-kerugian.json');

        $this->artisan('complaint:import', [
            'berkas' => base_path('tests/Fixtures/impor-complaint.csv'),
            '--sumber' => 'uji-kerugian',
            '--laporan' => $laporan,
            '--sejak' => '',
            '--tulis' => true,
        ])->run();

        if (is_file($laporan)) {
            unlink($laporan);
        }

        $tersimpan = Complaint::query()->get();
        $this->assertGreaterThan(0, $tersimpan->count(), 'impornya harus menghasilkan baris');

        $berbiaya = $tersimpan->filter(fn (Complaint $c) => (int) $c->compensation_amount > 0);
        $total = (int) $berbiaya->sum('compensation_amount');

        $halaman = $this->actingAs($this->userAs('supervisor'))
            ->get(route('reports.kerugian', ['from' => '2025-01-01', 'to' => '2026-12-31']));

        $halaman->assertOk();
        $halaman->assertSee('Rp '.number_format($total, 0, ',', '.'));
        $halaman->assertSee('dari '.$berbiaya->count().' dari '.$tersimpan->count().' complaint yang punya nilai biaya');

        $rekap = new RekapKerugian(
            Complaint::query()->with('outlet')->get(),
            SatuanWaktu::Bulanan,
        );

        $this->assertSame($total, $rekap->ringkasan()['biaya']);
        $this->assertSame($total, collect($rekap->golongan())->sum('biaya'));
        $this->assertSame($total, collect($rekap->perKategori())->sum('biaya'));
        $this->assertSame($total, collect($rekap->perOutlet())->sum('biaya'));
        $this->assertSame($total, collect($rekap->perTindakLanjut())->sum('biaya'));
        $this->assertSame($total, collect($rekap->perPeriode())->sum('biaya'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PemetaBarisImpor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impor complaint historis dari spreadsheet. (API-28)
 *
 * Berkas contoh di tests/Fixtures DIBUAT SENDIRI, bukan potongan data
 * sungguhan: berkas aslinya berisi nama dan keluhan pelanggan nyata, dan
 * data itu tidak masuk repositori. Yang ditiru adalah BENTUK datanya —
 * setiap keanehan yang benar-benar ada di 545 baris punya wakilnya di sini.
 */
class ImporComplaintTest extends TestCase
{
    use RefreshDatabase;

    private string $berkas;

    private string $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkas = base_path('tests/Fixtures/impor-complaint.csv');
        $this->laporan = storage_path('framework/testing/laporan-impor.md');

        foreach ([
            '115' => 'Kemang', '116' => 'Cipete', '117' => 'Hampton Gading Serpong',
            '118' => 'Tebet', '122' => 'Jati Padang', '123' => 'Park Serpong',
        ] as $idNevira => $nama) {
            Outlet::create(['name' => $nama, 'nevira_outlet_id' => $idNevira]);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->laporan)) {
            unlink($this->laporan);
        }

        parent::tearDown();
    }

    private function impor(array $opsi = []): int
    {
        return $this->artisan('complaint:import', [
            'berkas' => $this->berkas,
            '--sumber' => 'uji',
            '--laporan' => $this->laporan,
            ...$opsi,
        ])->run();
    }

    /* ---------- menghitung dulu, menulis kemudian ---------- */

    public function test_tanpa_tulis_tidak_menyentuh_basis_data(): void
    {
        $this->impor();

        $this->assertSame(0, Complaint::count(), 'Impor tanpa --tulis menulis ke basis data');
    }

    public function test_dry_run_menang_walau_tulis_diberikan(): void
    {
        // Dua bendera bertabrakan adalah salah ketik, dan salah ketik pada
        // impor pertama ke sistem tidak boleh berakhir dengan 545 baris masuk.
        $this->impor(['--tulis' => true, '--dry-run' => true]);

        $this->assertSame(0, Complaint::count());
    }

    public function test_dry_run_tetap_melaporkan_hitungannya(): void
    {
        $this->impor();

        $isi = file_get_contents($this->laporan);

        $this->assertStringContainsString('**dry-run**', $isi);
        $this->assertStringContainsString('| Dibaca dari berkas | 14 |', $isi);
        $this->assertStringContainsString('| Akan masuk | 12 |', $isi);
        $this->assertStringContainsString('| Gagal | 2 |', $isi);
    }

    /* ---------- menulis ---------- */

    public function test_impor_memasukkan_baris_yang_sah_dan_melewati_yang_gagal(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(12, Complaint::count());
        $this->assertSame(12, Complaint::where('import_source', 'uji')->count());
    }

    public function test_satu_baris_gagal_tidak_menghentikan_sisanya(): void
    {
        $this->impor(['--tulis' => true]);

        // Baris 11 (Date tidak terbaca) dan 12 (Name kosong) gagal; baris
        // setelahnya tetap masuk.
        $this->assertDatabaseHas('complaints', ['import_row' => 13]);
        $this->assertDatabaseMissing('complaints', ['import_row' => 11]);

        $isi = file_get_contents($this->laporan);
        $this->assertStringContainsString('- baris 11: kolom Date tidak terbaca', $isi);
        $this->assertStringContainsString('- baris 12: kolom Name kosong', $isi);
    }

    public function test_laporan_kegagalan_tidak_mengutip_isi_baris(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        // Nomor baris cukup untuk menelusuri; nama pelapor dan uraian keluhan
        // adalah data pribadi dan laporan ini ditempel ke issue.
        $this->assertStringNotContainsString('Pelapor Sepuluh', $isi);
        $this->assertStringNotContainsString('Baris rusak', $isi);
    }

    public function test_dijalankan_dua_kali_tidak_menggandakan(): void
    {
        $this->impor(['--tulis' => true]);
        $this->impor(['--tulis' => true]);

        $this->assertSame(12, Complaint::count());

        $isi = file_get_contents($this->laporan);
        $this->assertStringContainsString('| Dilewati (sudah ada dari impor sebelumnya) | 12 |', $isi);
    }

    public function test_nomor_tiket_unik_dan_berawalan_imp(): void
    {
        $this->impor(['--tulis' => true]);

        $nomor = Complaint::pluck('ticket_number');

        $this->assertCount(12, $nomor->unique());
        $this->assertTrue($nomor->every(fn ($n) => str_starts_with($n, 'IMP-')));
    }

    /* ---------- outlet ---------- */

    public function test_nama_outlet_dicocokkan_lewat_padanan_eksplisit(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame('Hampton Gading Serpong', $this->baris(2)->outlet->name);
        $this->assertSame('Park Serpong', $this->baris(3)->outlet->name);
        $this->assertSame('Jati Padang', $this->baris(5)->outlet->name);
        $this->assertSame('Tebet', $this->baris(1)->outlet->name);
    }

    public function test_outlet_tanpa_padanan_dibiarkan_kosong_dengan_nama_aslinya(): void
    {
        $this->impor(['--tulis' => true]);

        $durenTiga = $this->baris(4);

        // Complaint tanpa outlet bisa dibetulkan nanti. Complaint di outlet
        // yang salah tidak akan pernah ketahuan.
        $this->assertNull($durenTiga->outlet_id);
        $this->assertSame('Less Worry 3.1 - Duren Tiga', $durenTiga->legacy_outlet_name);

        $this->assertStringContainsString(
            'Outlet tanpa padanan: Less Worry 3.1 - Duren Tiga',
            file_get_contents($this->laporan),
        );
    }

    /* ---------- nilai kompensasi ---------- */

    public function test_dua_format_kompensasi_sama_sama_diterima(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(142500, $this->baris(1)->compensation_amount);
        $this->assertSame(2525000, $this->baris(2)->compensation_amount);
    }

    public function test_rp207_diimpor_apa_adanya(): void
    {
        $this->impor(['--tulis' => true]);

        // Bukan Rp207.000. Menebaknya berarti mengarang angka kompensasi yang
        // orang lain pakai untuk menghitung biaya.
        $this->assertSame(207, $this->baris(3)->compensation_amount);
    }

    public function test_titik_dibaca_sebagai_pemisah_ribuan_dan_dilaporkan(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(91000, $this->baris(5)->compensation_amount);
        $this->assertSame(30000, $this->baris(9)->compensation_amount);

        $this->assertStringContainsString('titik dibaca sebagai pemisah ribuan', file_get_contents($this->laporan));
    }

    public function test_teks_di_kolom_biaya_jadi_nol_dan_dilaporkan(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(0, $this->baris(7)->compensation_amount);
        $this->assertStringContainsString('bukan angka, kompensasi dianggap 0', file_get_contents($this->laporan));
    }

    /* ---------- status dan waktu ---------- */

    public function test_close_tanpa_tanggal_tutup_tidak_diisi_tanggal_masuk(): void
    {
        $this->impor(['--tulis' => true]);

        $baris = $this->baris(6);

        $this->assertSame('close', $baris->status);
        // Waktu penyelesaiannya TIDAK DIKETAHUI. Mengisinya dengan tanggal
        // masuk membuat laporan mengumumkan penyelesaian instan.
        $this->assertNull($baris->resolved_at);
        $this->assertNull($baris->resolutionMinutes());

        $this->assertStringContainsString('Close tanpa tanggal tutup', file_get_contents($this->laporan));
    }

    public function test_waktu_penyelesaian_cocok_dengan_selisih_tanggal_di_sumber(): void
    {
        $this->impor(['--tulis' => true]);

        $baris = $this->baris(1);

        $this->assertSame('2025-03-05', $baris->created_at->toDateString());
        $this->assertSame('2025-03-26', $baris->resolved_at->toDateString());
        $this->assertSame(21 * 24 * 60, $baris->resolutionMinutes());
    }

    public function test_status_handling_ikut_terbawa(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame('handling', $this->baris(7)->status);
    }

    /* ---------- enum ---------- */

    public function test_ejaan_ganda_dirapikan_jadi_satu_kategori(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame('kurang_rapih', $this->baris(5)->category);
        $this->assertSame('barang_tertukar', $this->baris(6)->category);
    }

    public function test_layanan_dicocokkan_termasuk_bentuk_bertanda_hubung(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame('kiloan_cuset', $this->baris(2)->layanan);
        $this->assertSame('satuan_non_cloth', $this->baris(3)->layanan);
        $this->assertSame('satuan_cloth', $this->baris(5)->layanan);
        $this->assertSame('kiloan_culip', $this->baris(4)->layanan);
        // `-` artinya tidak dicatat, bukan sebuah layanan bernama `-`.
        $this->assertNull($this->baris(7)->layanan);
    }

    public function test_kategori_kosong_jatuh_ke_lainnya_dan_dicatat(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame('lainnya', $this->baris(8)->category);

        $isi = file_get_contents($this->laporan);
        $this->assertStringContainsString('| Issue Category | kosong, jatuh ke lainnya | 1 |', $isi);
    }

    public function test_uraian_kosong_ditandai_bukan_dikarang(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(PemetaBarisImpor::URAIAN_KOSONG, $this->baris(9)->description);
    }

    /* ---------- NEVIRA tidak disentuh ---------- */

    public function test_nomor_nota_lama_tidak_pernah_masuk_kolom_nevira(): void
    {
        $this->impor(['--tulis' => true]);

        $this->assertSame(0, Complaint::whereNotNull('nevira_transaction_id')->count());
        $this->assertSame(0, Complaint::whereNotNull('nevira_transaction_number')->count());

        // Angkanya tetap tersimpan, hanya di kolom teks yang tidak dipakai
        // untuk menautkan apa pun.
        $this->assertSame('2138 (Juli)', $this->baris(2)->legacy_nota_number);
        $this->assertSame('1234', $this->baris(1)->legacy_nota_number);
    }

    public function test_kanal_impor_punya_label_tapi_tidak_ada_di_pilihan_intake(): void
    {
        $this->impor(['--tulis' => true]);

        $baris = $this->baris(1);

        $this->assertSame(PemetaBarisImpor::KANAL, $baris->channel);
        $this->assertSame('Tidak tercatat (impor data lama)', $baris->channelLabel());
        // Kasir tidak boleh bisa memilih "impor" untuk keluhan yang baru saja
        // diceritakan pelanggan di depannya.
        $this->assertArrayNotHasKey('impor', config('complaint.channels'));
    }

    /* ---------- riwayat dan catatan ---------- */

    public function test_riwayat_impor_tidak_menempel_ke_akun_siapa_pun(): void
    {
        $this->impor(['--tulis' => true]);

        $jejak = ComplaintActivity::where('complaint_id', $this->baris(1)->id)->get();

        $this->assertTrue($jejak->every(fn (ComplaintActivity $a) => $a->user_id === null));
        $this->assertTrue($jejak->contains(fn (ComplaintActivity $a) => $a->type === 'created'));
        $this->assertTrue($jejak->contains(fn (ComplaintActivity $a) => $a->note === 'catatan uji'));
    }

    /* ---------- laporan ---------- */

    public function test_laporan_memuat_kelima_butir_yang_diminta(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        foreach ([
            '## 1. Baris',
            '## 2. Nilai yang tidak punya padanan di enum',
            '## 3. Nomor nota',
            '## 4. Pengisian kolom `Pelaku`',
            '## 5. Sebaran per bulan',
            '## 6. Keanehan yang perlu keputusan orang',
        ] as $judul) {
            $this->assertStringContainsString($judul, $isi);
        }
    }

    public function test_laporan_menghitung_pengisian_pelaku_2026_terpisah(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        // Tiga baris 2026, satu terisi. `-` tidak dihitung terisi, dan baris
        // tanpa pelaku juga tidak — jadi 1 dari 3, bukan 3 dari 3.
        $this->assertStringContainsString('| 2026 saja | 1 | 3 | 33,3% |', $isi);
        $this->assertStringContainsString('Ambang KB Landasan Produk (API-24): **25,0%**', $isi);
    }

    public function test_laporan_menghitung_baris_tanpa_nomor_nota_terpakai(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        // 8 kosong + 2 tidak terbaca (`-` dan `1559 Des`) dari 14 baris.
        // Dihitung atas SELURUH baris berkas, termasuk yang gagal: angka ini
        // menggambarkan sumbernya, bukan hasil impornya.
        $this->assertStringContainsString('| Kosong | 8 |', $isi);
        $this->assertStringContainsString('| Tidak terbaca | 2 |', $isi);
        $this->assertStringContainsString('**71,4%**', $isi);
    }

    public function test_sebaran_per_bulan_dicocokkan_dengan_sumbernya(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        $this->assertStringContainsString('| 2025-03 | 1 | 1 | 0 |', $isi);
        $this->assertStringContainsString('| 2025-05 | 3 | 3 | 0 |', $isi);
        $this->assertStringContainsString('Sebarannya cocok bulan per bulan.', $isi);
    }

    public function test_quality_incident_dihitung_walau_tidak_diimpor(): void
    {
        $this->impor(['--tulis' => true]);

        $isi = file_get_contents($this->laporan);

        $this->assertStringContainsString('Baris `Quality Incident` (tidak diimpor sebagai jenis tersendiri) | 1', $isi);
    }

    /* ---------- halaman ikut terisi ---------- */

    public function test_papan_dan_laporan_menampilkan_complaint_hasil_impor(): void
    {
        $this->impor(['--tulis' => true]);

        $supervisor = User::create([
            'name' => 'Supervisor', 'email' => 'sv@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        // Baris impor punya bentuk yang belum pernah ada di sistem: outlet
        // kosong, resolved_at kosong pada tiket Close, kanal di luar daftar
        // intake. Halaman yang dibaca supervisor harus tetap berdiri.
        $this->actingAs($supervisor)->get('/complaints')->assertOk();

        $laporan = $this->actingAs($supervisor)
            ->get('/reports?from=2025-01-01&to=2026-12-31')
            ->assertOk();

        $laporan->assertSee('Kurang Bersih');
        $laporan->assertSee('Tidak tercatat (impor data lama)');
        $laporan->assertSee('Tanpa outlet');
    }

    /* ---------- jalan mundur ---------- */

    public function test_perintah_hapus_membuang_seluruh_baris_satu_impor(): void
    {
        $this->impor(['--tulis' => true]);

        $this->artisan('complaint:import-hapus', ['sumber' => 'uji', '--paksa' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Complaint::count());
    }

    public function test_perintah_hapus_tidak_menyentuh_complaint_yang_dicatat_orang(): void
    {
        $this->impor(['--tulis' => true]);

        $manual = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelanggan Uji',
            'category' => 'kurang_bersih', 'bobot' => 'ringan',
            'description' => 'Dicatat kasir, bukan diimpor.',
        ]);
        $manual->ticket_number = Complaint::nextTicketNumber();
        $manual->save();

        $this->artisan('complaint:import-hapus', ['sumber' => 'uji', '--paksa' => true]);

        $this->assertSame(1, Complaint::count());
        $this->assertSame($manual->id, Complaint::first()->id);
    }

    public function test_hapus_sumber_yang_tidak_ada_tidak_menghapus_apa_pun(): void
    {
        $this->impor(['--tulis' => true]);

        $this->artisan('complaint:import-hapus', ['sumber' => 'tidak-ada', '--paksa' => true])
            ->assertExitCode(0);

        $this->assertSame(12, Complaint::count());
    }

    /* ---------- jalur gagal perintahnya sendiri ---------- */

    public function test_berkas_yang_tidak_ada_dilaporkan_bukan_dilempar(): void
    {
        $kode = $this->artisan('complaint:import', ['berkas' => base_path('tests/Fixtures/tidak-ada.csv')])->run();

        $this->assertSame(1, $kode);
        $this->assertSame(0, Complaint::count());
    }

    private function baris(int $nomorBaris): Complaint
    {
        // Nomor baris berkas: baris 1 judul, jadi baris data pertama = 2.
        return Complaint::where('import_source', 'uji')
            ->where('import_row', $nomorBaris + 1)
            ->firstOrFail();
    }
}

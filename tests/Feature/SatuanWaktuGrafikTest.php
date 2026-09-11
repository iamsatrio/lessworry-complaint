<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\GrafikLaporan;
use App\Services\SatuanWaktu;
use App\View\Components\Grafik\Garis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Satuan waktu sumbu mendatar grafik. (API-62 nomor 2)
 *
 * Keempat satuan tersedia, tapi yang dipakai ditentukan rentang tanggalnya
 * sendiri kecuali orangnya memilih lain. Alasannya kepadatan datanya: dari
 * 545 baris pertama, 46% hari tidak punya satu pun complaint dan 152 hari
 * punya tepat satu — harian pada rentang panjang menggambar gigi gergaji
 * nol-dan-satu, dan tahunan pada dua tahun data menggambar dua titik.
 *
 * Yang dijaga di sini bahwa bawaannya benar, bahwa pilihan sendiri tetap
 * dihormati, dan bahwa satuan yang padat nol menghasilkan KETERANGAN —
 * bukan larangan.
 */
class SatuanWaktuGrafikTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $waktu, ?Outlet $outlet = null, string $kategori = 'kurang_bersih'): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => $kategori,
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ]);

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function grafik(User $user, string $dari, string $sampai, SatuanWaktu $satuan): GrafikLaporan
    {
        return new GrafikLaporan($user, Complaint::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [Carbon::parse($dari), Carbon::parse($sampai)])
            ->with('outlet')
            ->get(), null, $satuan);
    }

    /* ---------- Kriteria 2: bawaannya ditentukan lebar rentangnya ---------- */

    public function test_rentang_empat_belas_hari_digambar_harian_tanpa_dipilih(): void
    {
        $this->assertSame(
            SatuanWaktu::Harian,
            SatuanWaktu::bawaanUntuk(Carbon::parse('2026-08-28'), Carbon::parse('2026-09-10'))
        );
    }

    public function test_rentang_delapan_belas_bulan_digambar_bulanan_tanpa_dipilih(): void
    {
        $this->assertSame(
            SatuanWaktu::Bulanan,
            SatuanWaktu::bawaanUntuk(Carbon::parse('2025-04-01'), Carbon::parse('2026-09-30'))
        );
    }

    public function test_ambang_bawaannya_persis_seperti_yang_diputuskan(): void
    {
        $dari = Carbon::parse('2026-01-01');

        // ≤ 31 hari → harian.
        $this->assertSame(SatuanWaktu::Harian, SatuanWaktu::bawaanUntuk($dari, Carbon::parse('2026-01-31')));
        // Sehari lebih panjang sudah mingguan.
        $this->assertSame(SatuanWaktu::Mingguan, SatuanWaktu::bawaanUntuk($dari, Carbon::parse('2026-02-01')));
        // ≤ 6 bulan → mingguan.
        $this->assertSame(SatuanWaktu::Mingguan, SatuanWaktu::bawaanUntuk($dari, Carbon::parse('2026-07-01')));
        // Lebih dari 6 bulan → bulanan.
        $this->assertSame(SatuanWaktu::Bulanan, SatuanWaktu::bawaanUntuk($dari, Carbon::parse('2026-07-02')));
    }

    /**
     * Dua tahun data menggambar dua titik, dan dua titik bukan grafik. Jadi
     * Tahunan hanya bisa dipilih sendiri — tidak pernah muncul dengan
     * sendirinya, serentang apa pun tanggalnya.
     */
    public function test_tahunan_tidak_pernah_jadi_bawaan(): void
    {
        foreach (['2026-01-02', '2026-06-01', '2027-01-01', '2036-01-01'] as $sampai) {
            $this->assertNotSame(
                SatuanWaktu::Tahunan,
                SatuanWaktu::bawaanUntuk(Carbon::parse('2026-01-01'), Carbon::parse($sampai))
            );
        }
    }

    /* ---------- Keempatnya bisa dipilih, dan pilihannya dihormati ---------- */

    public function test_keempat_satuan_bisa_dipilih_dan_mengubah_sumbunya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);

        foreach (['2025-08-04 09:00', '2025-08-05 09:00', '2026-03-10 09:00'] as $waktu) {
            $this->complaint($waktu, $outlet);
        }

        $user = $this->userAs('supervisor');

        $harapan = [
            'harian' => '4 Agustus 2025',
            'mingguan' => '4–10 Agustus 2025',
            'bulanan' => 'Agustus 2025',
            'tahunan' => '2025',
        ];

        foreach ($harapan as $satuan => $labelPertama) {
            $html = $this->actingAs($user)
                ->get('/reports?from=2025-08-01&to=2026-03-31&satuan='.$satuan)
                ->assertOk()->getContent();

            $this->assertStringContainsString($labelPertama, $html,
                "Satuan $satuan tidak menghasilkan periode pertama yang benar.");
        }
    }

    public function test_pilihan_sendiri_menang_atas_bawaannya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-09-01 09:00', $outlet);
        $this->complaint('2026-09-08 09:00', $outlet);

        // Rentang 14 hari: bawaannya harian. Bulanan yang dipilih harus
        // menang, bukan diabaikan diam-diam.
        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-09-01&to=2026-09-14&satuan=bulanan')
            ->assertOk()->getContent();

        // Dicari di judul kolom tabel angkanya, bukan di halaman utuh:
        // kalimat "Rentang terpilih" di atas memang menyebut tanggal harian
        // apa pun satuan grafiknya.
        $this->assertStringContainsString('<th>Bulan</th>', $html);
        $this->assertStringNotContainsString('<th>Hari</th>', $html);
    }

    /**
     * Satuan yang tidak dikenali diperlakukan sebagai "tidak memilih", bukan
     * sebagai galat: ini saringan tampilan, bukan data yang disimpan.
     */
    public function test_satuan_yang_tidak_dikenali_jatuh_ke_bawaannya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-09-01 09:00', $outlet);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-09-01&to=2026-09-14&satuan=dasawarsa')
            ->assertOk();
    }

    /* ---------- Kriteria 3: keterangan, bukan larangan ---------- */

    public function test_satuan_yang_sebagian_besar_titiknya_nol_memunculkan_keterangan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);

        // 20 hari rentang, complaint hanya di 3 hari — 85% titik harian nol.
        foreach (['2026-09-01 09:00', '2026-09-10 09:00', '2026-09-20 09:00'] as $waktu) {
            $this->complaint($waktu, $outlet);
        }

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-09-01&to=2026-09-20&satuan=harian')
            ->assertOk();

        // KETERANGAN: grafiknya tetap digambar apa adanya.
        $response->assertSee('titik di grafik ini bernilai nol', false);
        $response->assertSee('<svg', false);
        // Bukan larangan: tidak ada satu pun kalimat yang menolak
        // menggambarnya, dan pilihannya tetap terpilih di daftar.
        $response->assertSee('<option value="harian" selected>Harian</option>', false);
    }

    public function test_satuan_yang_datanya_padat_tidak_memunculkan_keterangan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);

        // Enam bulan berturut-turut terisi, dan kategorinya Barang Rusak
        // supaya grafik ketiga ikut padat: keterangan ini berdiri per grafik,
        // bukan per halaman, dan grafik yang seluruh titiknya nol memang
        // seharusnya mengatakannya.
        foreach (range(0, 5) as $bulan) {
            $this->complaint(
                Carbon::parse('2026-01-05')->addMonths($bulan)->format('Y-m-d').' 09:00',
                $outlet,
                'barang_rusak',
            );
        }

        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-01-01&to=2026-06-30&satuan=bulanan')
            ->assertOk()
            ->assertDontSee('titik di grafik ini bernilai nol', false);
    }

    /**
     * Titik yang nilainya TIDAK ADA bukan titik bernilai nol. Bulan sebelum
     * outlet pertama buka tidak punya pembagi, jadi angkanya tidak ada — dan
     * menghitungnya sebagai nol akan memunculkan keterangan yang salah.
     */
    public function test_titik_tanpa_nilai_tidak_dihitung_sebagai_nol(): void
    {
        $grafik = $this->grafik($this->userAs('supervisor'), '2026-01-01', '2026-12-31', SatuanWaktu::Bulanan);

        $titik = [
            ['label' => 'A', 'judul' => 'A', 'nilai' => null, 'teks' => ''],
            ['label' => 'B', 'judul' => 'B', 'nilai' => null, 'teks' => ''],
            ['label' => 'C', 'judul' => 'C', 'nilai' => null, 'teks' => ''],
            ['label' => 'D', 'judul' => 'D', 'nilai' => 3.0, 'teks' => ''],
        ];

        $this->assertNull($grafik->keteranganPadatData($titik));
    }

    /* ---------- Label periodenya ---------- */

    public function test_minggu_diwakili_tanggal_seninnya_bukan_nomor_minggu_iso(): void
    {
        // 29 Desember 2025 adalah Senin, dan pada penomoran ISO ia jatuh di
        // minggu ke-1 tahun 2026 — kunci yang akan mengurutkan dirinya di
        // depan seluruh 2025.
        $kunci = SatuanWaktu::Mingguan->kunci(Carbon::parse('2025-12-31 10:00'));

        $this->assertSame('2025-12-29', $kunci);
        $this->assertLessThan(SatuanWaktu::Mingguan->kunci(Carbon::parse('2026-01-05')), $kunci);
    }

    public function test_label_minggu_yang_melompati_bulan_menulis_kedua_bulannya(): void
    {
        $this->assertSame('3–9 Agustus 2026', SatuanWaktu::Mingguan->labelPenuh('2026-08-03'));
        $this->assertSame('31 Agustus–6 September 2026', SatuanWaktu::Mingguan->labelPenuh('2026-08-31'));
        $this->assertSame('29 Desember 2025–4 Januari 2026', SatuanWaktu::Mingguan->labelPenuh('2025-12-29'));
    }

    public function test_sumbu_periode_tidak_bolong(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-09-01 09:00', $outlet);
        $this->complaint('2026-09-05 09:00', $outlet);

        $baris = $this->grafik($this->userAs('supervisor'), '2026-09-01', '2026-09-30', SatuanWaktu::Harian)
            ->perPeriode();

        // Hari kosong di tengah tetap digambar — nol complaint sehari itu
        // informasi. Lima hari, bukan dua.
        $this->assertCount(5, $baris);
        $this->assertSame(1, $baris[0]['complaint']);
        $this->assertSame(0, $baris[1]['complaint']);
        $this->assertSame(1, $baris[4]['complaint']);
    }

    /**
     * Outlet yang complaint pertamanya 20 Agustus tetap dihitung aktif pada
     * hari itu juga. Perbandingan terhadap AWAL periode akan mengeluarkannya
     * dari harinya sendiri, dan angka per outlet hari itu jadi tidak ada.
     */
    public function test_outlet_dihitung_aktif_pada_hari_complaint_pertamanya(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-09-20 14:30', $outlet);

        $baris = $this->grafik($this->userAs('supervisor'), '2026-09-01', '2026-09-30', SatuanWaktu::Harian)
            ->perPeriode();

        $this->assertSame(1, $baris[0]['outlet']);
        $this->assertSame(1.0, $baris[0]['per']);
    }

    /* ---------- Titik yang terlalu rapat diumumkan, bukan disembunyikan ---------- */

    public function test_titik_yang_terlalu_rapat_diumumkan_di_bawah_grafik(): void
    {
        $rapat = new Garis(judul: 'Uji', catatan: 'Uji', titik: $this->titikSebanyak(400));
        $renggang = new Garis(judul: 'Uji', catatan: 'Uji', titik: $this->titikSebanyak(20));

        $this->assertFalse($rapat->sasaranCukup(),
            '400 titik tidak muat dengan sasaran tunjuk 28px, dan itu harus diakui.');
        $this->assertTrue($renggang->sasaranCukup());

        // Batas lebar kanvasnya menggigit: tanpa itu 400 titik menuntut kanvas
        // belasan ribu piksel.
        $this->assertLessThanOrEqual(4000, $rapat->lebarMin());
    }

    /** @return list<array{label:string,judul:string,nilai:float|null,teks:string}> */
    private function titikSebanyak(int $n): array
    {
        $titik = [];

        for ($i = 0; $i < $n; $i++) {
            $titik[] = ['label' => 'T'.$i, 'judul' => 'T'.$i, 'nilai' => (float) $i, 'teks' => $i.' kasus'];
        }

        return $titik;
    }
}

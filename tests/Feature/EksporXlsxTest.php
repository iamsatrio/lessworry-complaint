<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PerisaiRumus;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Ekspor `.xlsx`. (API-62 nomor 4)
 *
 * Alasannya bukan kenyamanan. CSV yang ada rusak saat dibuka Excel dengan
 * wilayah Indonesia: Excel id-ID membaca titik sebagai pemisah desimal dan
 * menebak sendiri bentuk tanggalnya, jadi `30.881.208` bisa terbaca
 * `30,881208` dan tanggal bisa tertukar hari-bulannya. Itu angka yang berubah
 * diam-diam di berkas yang dipakai mengambil keputusan.
 *
 * Yang dijaga di sini karena itu bukan "berkasnya terunduh", melainkan bahwa
 * TIPE tiap selnya benar-benar tersimpan — angka sebagai angka, tanggal
 * sebagai tanggal, nomor telepon sebagai teks — dan bahwa aturan kolom yang
 * berlaku di CSV berlaku sama persis di sini.
 */
class EksporXlsxTest extends TestCase
{
    use RefreshDatabase;

    private const ID_INTERNAL = 'nevira-trx-98765';

    private const NOTA = 'NV-2026-000123';

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(Outlet $outlet, array $attr = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Siti', 'reporter_phone' => '081234567890',
            'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan',
            'description' => 'x', 'outlet_id' => $outlet->id,
            'nevira_transaction_number' => self::NOTA,
        ], $attr));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->nevira_transaction_id = self::ID_INTERNAL;
        $complaint->status = 'close';
        $complaint->close_reason = 'selesai';
        $complaint->created_at = Carbon::parse('2026-08-11 09:30');
        $complaint->applySla();
        $complaint->resolved_at = Carbon::parse('2026-08-12 10:00');
        $complaint->compensation_amount = 30_881_208;
        $complaint->save();

        return $complaint;
    }

    /**
     * Berkasnya ditulis ke berkas sementara lalu dibaca kembali: satu-satunya
     * cara membuktikan tipe selnya benar-benar tersimpan, bukan cuma terlihat
     * benar di layar.
     *
     * @return list<list<mixed>>
     */
    private function bacaXlsx(string $isi): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'lw-xlsx-');
        file_put_contents($berkas, $isi);

        $reader = new Reader;
        $reader->open($berkas);

        $baris = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $baris[] = $row->toArray();
            }

            break;
        }

        $reader->close();
        unlink($berkas);

        return $baris;
    }

    private function unduh(User $user): array
    {
        $response = $this->actingAs($user)->get('/reports/export.xlsx?from=2026-08-01&to=2026-08-31')->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        return $this->bacaXlsx($response->streamedContent());
    }

    /* ---------- Kriteria 5: angka tetap angka, tanggal tetap tanggal ---------- */

    public function test_kompensasi_tersimpan_sebagai_angka_utuh(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $baris = $this->unduh($this->userAs('supervisor'));
        $kolom = array_flip($baris[0]);
        $nilai = $baris[1][$kolom['Kompensasi']];

        // Angka, bukan teks: `30.881.208` yang dikirim sebagai teks bisa
        // dibaca ulang Excel id-ID jadi 30,881208.
        $this->assertIsNumeric($nilai);
        $this->assertEquals(30_881_208, $nilai);
    }

    public function test_tanggal_tersimpan_sebagai_tanggal(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $baris = $this->unduh($this->userAs('supervisor'));
        $kolom = array_flip($baris[0]);

        foreach (['Dibuat' => '2026-08-11 09:30', 'Selesai' => '2026-08-12 10:00'] as $judul => $harapan) {
            $nilai = $baris[1][$kolom[$judul]];

            $this->assertInstanceOf(DateTimeInterface::class, $nilai,
                "Kolom $judul tersimpan sebagai teks, jadi Excel tetap harus menebak bentuknya.");
            $this->assertSame($harapan, $nilai->format('Y-m-d H:i'));
        }
    }

    /**
     * Nomor telepon yang tersimpan sebagai bilangan kehilangan nol di
     * depannya dan berubah jadi nomor orang lain.
     */
    public function test_nomor_telepon_tetap_teks_lengkap_dengan_nol_di_depannya(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $baris = $this->unduh($this->userAs('supervisor'));
        $kolom = array_flip($baris[0]);
        $nilai = $baris[1][$kolom['Telepon']];

        $this->assertIsString($nilai);
        $this->assertSame('081234567890', $nilai);
    }

    public function test_kompensasi_diberi_format_rupiah(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export.xlsx?from=2026-08-01&to=2026-08-31')->assertOk();

        $berkas = tempnam(sys_get_temp_dir(), 'lw-xlsx-');
        file_put_contents($berkas, $response->streamedContent());

        $zip = new \ZipArchive;
        $zip->open($berkas);
        $gaya = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($berkas);

        // Pemisah di DALAM berkas xlsx selalu koma dan titik; Excel yang
        // menukarnya sesuai wilayah pembacanya. Menulis `#.##0` di sini justru
        // menghasilkan angka yang salah di mesin Indonesia.
        $this->assertStringContainsString('#,##0', $gaya);
        $this->assertStringContainsString('dd/mm/yyyy', $gaya);
    }

    /* ---------- Kriteria 6: aturan kolom yang sama dengan CSV ---------- */

    public function test_xlsx_memuat_nomor_nota_bukan_id_internal_nevira(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $baris = $this->unduh($this->userAs('supervisor'));
        $rata = json_encode($baris);

        $this->assertStringContainsString(self::NOTA, (string) $rata);
        // Rekap ini diteruskan lewat WhatsApp dan email; pengenal internal
        // sistem lain tidak boleh ikut keluar. (API-8 T2)
        $this->assertStringNotContainsString(self::ID_INTERNAL, (string) $rata);
        $this->assertNotContains('ID Transaksi NEVIRA', $baris[0]);
    }

    public function test_kolom_karyawan_hanya_untuk_yang_berhak(): void
    {
        $outlet = Outlet::create(['name' => 'Cipete']);
        $complaint = $this->complaint($outlet);

        ComplaintResponsible::create([
            'complaint_id' => $complaint->id, 'staff_name' => 'Rahasia Karyawan',
            'staff_nip' => '99123', 'role' => 'kasir', 'reason' => 'salah hitung',
        ]);

        $supervisor = $this->unduh($this->userAs('supervisor'));
        $this->assertContains('Karyawan Penanggung Jawab', $supervisor[0]);
        $this->assertStringContainsString('Rahasia Karyawan', (string) json_encode($supervisor));

        $kasir = $this->unduh($this->userAs('kasir', $outlet));
        $this->assertNotContains('Karyawan Penanggung Jawab', $kasir[0]);
        $this->assertNotContains('NIP', $kasir[0]);
        $this->assertStringNotContainsString('Rahasia Karyawan', (string) json_encode($kasir));
    }

    public function test_kolom_xlsx_sama_persis_dengan_kolom_csv(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));
        $user = $this->userAs('supervisor');

        $csv = $this->actingAs($user)
            ->get('/reports/export?from=2026-08-01&to=2026-08-31')->streamedContent();
        $judulCsv = str_getcsv((string) strtok($csv, "\n"));

        $this->assertSame($judulCsv, $this->unduh($user)[0]);
    }

    public function test_saringan_outlet_ikut_ke_xlsx(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->complaint($cipete, ['reporter_name' => 'Pelapor Cipete']);
        $this->complaint($lebakBulus, ['reporter_name' => 'Pelapor Lebak']);

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export.xlsx?from=2026-08-01&to=2026-08-31&outlet='.$cipete->id)->assertOk();

        $isi = (string) json_encode($this->bacaXlsx($response->streamedContent()));

        $this->assertStringContainsString('Pelapor Cipete', $isi);
        $this->assertStringNotContainsString('Pelapor Lebak', $isi);
    }

    public function test_kasir_tidak_bisa_mengunduh_xlsx_outlet_lain(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->actingAs($this->userAs('kasir', $cipete))
            ->get('/reports/export.xlsx?outlet='.$lebakBulus->id)
            ->assertForbidden();
    }

    /* ---------- Teks yang diawali "=" tetap teks ---------- */

    /**
     * Nama pelapor yang diawali `=` dan tersimpan sebagai RUMUS adalah lubang
     * penyuntikan rumus yang sudah dikenal — dan di berkas yang dikirim ke
     * ponsel orang lain itu bukan risiko teoretis.
     */
    public function test_teks_yang_diawali_sama_dengan_tidak_jadi_rumus(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']), [
            'reporter_name' => '=1+1',
        ]);

        $baris = $this->unduh($this->userAs('supervisor'));
        $kolom = array_flip($baris[0]);
        $nilai = $baris[1][$kolom['Pelapor']];

        $this->assertSame('=1+1', $nilai, 'Nama pelapor terbaca sebagai rumus, bukan sebagai teks.');
    }

    /**
     * Daftar awalan pemicunya dibaca dari PerisaiRumus, sumber yang sama
     * dipakai jalur CSV (API-77). Awalan yang ditambahkan ke daftar itu nanti
     * langsung teruji di kedua jalur, jadi keduanya tidak bisa menyimpang
     * diam-diam.
     */
    public function test_semua_awalan_pemicu_tetap_teks(): void
    {
        $outlet = Outlet::create(['name' => 'Cipete']);
        $nama = [];

        foreach (PerisaiRumus::AWALAN_RUMUS as $awalan) {
            $n = $awalan.'SUM(A1:A9)';
            $nama[] = $n;
            $this->complaint($outlet, ['reporter_name' => $n]);
        }

        $baris = $this->unduh($this->userAs('supervisor'));
        $kolom = array_flip($baris[0]);
        $sel = array_map(fn (array $b) => $b[$kolom['Pelapor']], array_slice($baris, 1));

        // Di `.xlsx` nilainya tersimpan sebagai teks tanpa penanda apa pun —
        // tipe selnya yang menjawab, jadi tidak ada yang perlu ditambahkan.
        //
        // Dibandingkan per sifat, bukan per byte: XML menormalkan carriage
        // return jadi line feed, sehingga nilai berawalan "\r" kembali
        // berawalan "\n". Itu aturan penyimpanan XML, bukan rumus — yang
        // dijaga di sini adalah selnya tetap tulisan dan isinya tidak hilang.
        foreach (PerisaiRumus::AWALAN_RUMUS as $awalan) {
            $harap = $awalan === "\r" ? "\n" : $awalan;

            $cocok = array_filter($sel, fn ($s) => is_string($s)
                && $s !== ''
                && $s[0] === $harap
                && str_ends_with($s, 'SUM(A1:A9)'));

            $this->assertNotEmpty($cocok, 'Awalan '.json_encode($awalan).' tidak tersimpan utuh sebagai teks.');
        }

        // Dan tidak satu pun sel yang berisi hasil hitungan.
        $this->assertNotContains('SUM(A1:A9)', $sel);
    }

    /* ---------- CSV tetap ada dan tidak berubah ---------- */

    public function test_csv_tetap_ada_dan_bentuknya_tidak_berubah(): void
    {
        $this->complaint(Outlet::create(['name' => 'Cipete']));

        $csv = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export?from=2026-08-01&to=2026-08-31')->assertOk()->streamedContent();

        $baris = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $kolom = array_flip($baris[0]);

        // Kompensasi tetap ditulis sebagai bilangan telanjang, tanggal tetap
        // 'Y-m-d H:i': alat lain yang membaca CSV ini tidak boleh menemukan
        // bentuk baru gara-gara ada format kedua di sebelahnya.
        $this->assertSame('30881208', $baris[1][$kolom['Kompensasi']]);
        $this->assertSame('2026-08-11 09:30', $baris[1][$kolom['Dibuat']]);
        $this->assertSame('2026-08-12 10:00', $baris[1][$kolom['Selesai']]);
    }
}

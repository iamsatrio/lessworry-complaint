<?php

namespace Tests\Feature;

use App\Models\Complaint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesan galat berkas: apa yang terjadi, bukan pengulangan yang diketik. (API-60)
 *
 * satrio menjalankan `complaint:import "DATA COMPLAINT.csv"` dengan berkas di
 * `~/Downloads`, dan dijawab "Berkas tidak terbaca: DATA COMPLAINT.csv" —
 * kalimat yang mengulang persis apa yang baru saja ia ketik. Perintah itu
 * dijalankan sekali, pada malam deploy, saat 545 baris riwayat satu-satunya
 * dipindahkan.
 *
 * Yang dijaga di sini: tiap keadaan punya kalimatnya sendiri, path yang dicari
 * selalu disebut utuh, dan perintahnya TIDAK pernah menyisir direktori mencari
 * berkas yang mirip.
 */
class PesanGalatBerkasTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing-galat-'.uniqid());
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $berkas) {
            if (is_string($berkas)) {
                @chmod($berkas, 0644);
                is_dir($berkas) ? @rmdir($berkas) : @unlink($berkas);
            }
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    /* ---------- complaint:import ---------- */

    public function test_berkas_tidak_ada_menyebut_path_absolut_yang_dicari(): void
    {
        $kerja = getcwd() ?: base_path();

        $this->artisan('complaint:import', ['berkas' => 'DATA COMPLAINT.csv'])
            ->expectsOutputToContain('Berkas tidak ditemukan.')
            ->expectsOutputToContain('Dicari di: '.$kerja.'/DATA COMPLAINT.csv')
            ->expectsOutputToContain('dibaca sebagai path relatif terhadap direktori kerja: '.$kerja)
            ->expectsOutputToContain('php artisan complaint:import ~/Downloads/nama-berkas.csv')
            ->assertFailed();
    }

    public function test_berkas_tidak_ada_tidak_menyebut_izin_atau_bentuk_berkas(): void
    {
        // Keadaan yang berbeda tidak boleh saling meminjam kalimat: berkas
        // yang tidak ada tidak punya izin dan tidak punya format.
        $this->artisan('complaint:import', ['berkas' => $this->dir.'/tidak-ada.csv'])
            ->doesntExpectOutputToContain('izin')
            ->doesntExpectOutputToContain('bukan CSV')
            ->assertFailed();
    }

    public function test_berkas_yang_izinnya_tertutup_disebut_izinnya_bukan_keberadaannya(): void
    {
        $this->lewatiKalauRoot();

        $berkas = $this->dir.'/tertutup.csv';
        file_put_contents($berkas, "Date,Name\n");
        chmod($berkas, 0000);

        $this->artisan('complaint:import', ['berkas' => $berkas])
            ->expectsOutputToContain('Berkas ada, tapi izinnya tidak mengizinkan perintah ini membacanya.')
            ->expectsOutputToContain('izin 0000')
            ->expectsOutputToContain('chmod u+r')
            ->doesntExpectOutputToContain('tidak ditemukan')
            ->assertFailed();
    }

    public function test_xlsx_disuruh_diekspor_jadi_csv_lebih_dulu(): void
    {
        $berkas = $this->dir.'/DATA COMPLAINT.xlsx';
        file_put_contents($berkas, 'PK bukan teks berkoma');

        $this->artisan('complaint:import', ['berkas' => $berkas])
            ->expectsOutputToContain('Berkas ini .xlsx, bukan CSV.')
            ->expectsOutputToContain('ekspor jadi CSV')
            ->doesntExpectOutputToContain('tidak ditemukan')
            ->assertFailed();
    }

    public function test_xls_juga_disuruh_diekspor(): void
    {
        $berkas = $this->dir.'/lama.XLS';
        file_put_contents($berkas, 'bukan teks berkoma');

        $this->artisan('complaint:import', ['berkas' => $berkas])
            ->expectsOutputToContain('Berkas ini .xls, bukan CSV.')
            ->assertFailed();
    }

    public function test_direktori_dibedakan_dari_berkas(): void
    {
        $this->artisan('complaint:import', ['berkas' => $this->dir])
            ->expectsOutputToContain('Yang ditunjuk direktori, bukan berkas: '.$this->dir)
            ->assertFailed();
    }

    public function test_perintah_tidak_menyisir_direktori_mencari_berkas_yang_mirip(): void
    {
        // Berkas dengan nama yang sangat mirip ada di direktori yang sama.
        // Perintah yang diberi satu path tidak berhak mengintip isi folder di
        // sekitarnya, jadi nama ini tidak boleh muncul di pesannya.
        file_put_contents($this->dir.'/DATA COMPLAINT - 3. Data Input New.csv', "Date,Name\n");

        $this->artisan('complaint:import', ['berkas' => $this->dir.'/DATA COMPLAINT.csv'])
            ->doesntExpectOutputToContain('Data Input New')
            ->assertFailed();
    }

    public function test_laporan_yang_gagal_ditulis_tidak_diumumkan_tersimpan(): void
    {
        // Induk tujuannya berkas, bukan direktori — mkdir pasti gagal.
        $penghalang = $this->dir.'/bukan-folder';
        file_put_contents($penghalang, 'x');

        $this->artisan('complaint:import', [
            'berkas' => base_path('tests/Fixtures/impor-complaint.csv'),
            '--sumber' => 'uji',
            '--laporan' => $penghalang.'/laporan.md',
        ])
            ->expectsOutputToContain('Laporan TIDAK tersimpan')
            ->doesntExpectOutputToContain('Laporan disimpan')
            ->run();
    }

    /* ---------- complaint:import-hapus ---------- */

    public function test_penanda_impor_yang_salah_dijawab_dengan_penanda_yang_ada(): void
    {
        $this->complaintImpor('spreadsheet-2026-08');

        $this->artisan('complaint:import-hapus', ['sumber' => 'spreadsheet-2026-09', '--paksa' => true])
            ->expectsOutputToContain('Tidak ada complaint dengan import_source "spreadsheet-2026-09"')
            ->expectsOutputToContain('Penanda yang ada: spreadsheet-2026-08 (1 baris)')
            ->assertSuccessful();
    }

    public function test_basis_data_tanpa_impor_dikatakan_apa_adanya(): void
    {
        $this->artisan('complaint:import-hapus', ['sumber' => 'apa-saja', '--paksa' => true])
            ->expectsOutputToContain('Belum ada satu pun complaint hasil impor di basis data.')
            ->assertSuccessful();
    }

    /* ---------- backup:verify ---------- */

    public function test_verify_berkas_tidak_ada_menyebut_di_mana_dicari(): void
    {
        config(['backup.path' => $this->dir]);

        $this->artisan('backup:verify', ['file' => 'db-2026-01-01-000000.sql.gz'])
            ->expectsOutputToContain('berkas backup tidak ditemukan')
            ->expectsOutputToContain('Dicari di: '.$this->dir.'/db-2026-01-01-000000.sql.gz')
            ->expectsOutputToContain('Direktori backup: '.$this->dir)
            ->assertFailed();
    }

    public function test_verify_membedakan_nama_tak_berpola_dari_berkas_di_luar_direktori(): void
    {
        config(['backup.path' => $this->dir]);

        $salahNama = $this->dir.'/dump-manual.sql.gz';
        file_put_contents($salahNama, gzencode('apa saja'));

        $this->artisan('backup:verify', ['file' => $salahNama])
            ->expectsOutputToContain('namanya tidak berpola dump sistem ini')
            ->expectsOutputToContain('db-YYYY-MM-DD-HHMMSS.sql.gz')
            ->doesntExpectOutputToContain('di luar direktori backup')
            ->assertFailed();
    }

    private function complaintImpor(string $sumber): void
    {
        $complaint = new Complaint([
            'channel' => 'impor', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->import_source = $sumber;
        $complaint->applySla();
        $complaint->save();
    }

    private function lewatiKalauRoot(): void
    {
        // root menembus izin berkas: chmod 0000 tetap terbaca, jadi keadaan
        // yang diuji di sini tidak bisa dibuat. Dilewati, bukan dilonggarkan.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Dijalankan sebagai root — izin berkas tidak menahan apa pun.');
        }
    }
}

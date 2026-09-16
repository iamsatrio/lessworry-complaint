<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Services\TanggalPengambilan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom `Cucian Diterima Cust` dari spreadsheet lama. (API-48)
 *
 * Kolomnya mati dua kali karena harus diketik manual — terisi 84 dari 545
 * baris. Yang 84 itu tetap yang paling tahu: orang yang mengetiknya melihat
 * pelanggannya pulang membawa cucian. Sumbernya ditandai `manual` supaya
 * tidak pernah tertukar dengan tanggal yang datang dari jejak NEVIRA.
 *
 * Berkas contohnya dibuat sendiri, bukan potongan data sungguhan.
 */
class ImporPengambilanTest extends TestCase
{
    use RefreshDatabase;

    private string $berkas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkas = base_path('tests/Fixtures/impor-pengambilan.csv');
        Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);
    }

    private function impor(): void
    {
        $this->artisan('complaint:import', [
            'berkas' => $this->berkas, '--tulis' => true, '--sumber' => 'uji', '--sejak' => '',
        ])->run();
    }

    private function complaintOleh(string $nama): Complaint
    {
        return Complaint::where('reporter_name', $nama)->firstOrFail();
    }

    public function test_tanggal_dari_spreadsheet_masuk_dengan_sumber_manual(): void
    {
        $this->impor();

        $c = $this->complaintOleh('Pelapor Satu');
        $this->assertSame('2026-04-08', $c->tanggal_pengambilan->toDateString());
        $this->assertSame(TanggalPengambilan::MANUAL, $c->sumber_tanggal_pengambilan);
        $this->assertSame(2, $c->jarakKomplainHari());
    }

    public function test_kolom_kosong_jadi_tidak_diketahui_bukan_tanggal_masuk(): void
    {
        $this->impor();

        $c = $this->complaintOleh('Pelapor Dua');
        $this->assertNull($c->tanggal_pengambilan);
        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $c->sumber_tanggal_pengambilan);
        $this->assertNull($c->jarakKomplainHari());
    }

    public function test_tanggal_yang_tidak_terbaca_dicatat_sebagai_anomali_bukan_ditebak(): void
    {
        $this->impor();

        $c = $this->complaintOleh('Pelapor Tiga');
        $this->assertNull($c->tanggal_pengambilan);
        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $c->sumber_tanggal_pengambilan);
    }

    /* ---------- backfill ---------- */

    private function kosongkanTanggal(): void
    {
        // Meniru keadaan sungguhan: barisnya sudah diimpor sebelum kolomnya
        // ada, jadi tersimpan tanpa tanggal pengambilan.
        Complaint::query()->update([
            'tanggal_pengambilan' => null,
            'sumber_tanggal_pengambilan' => TanggalPengambilan::TIDAK_DIKETAHUI,
        ]);
    }

    public function test_backfill_mengisi_baris_yang_sudah_telanjur_masuk(): void
    {
        $this->impor();
        $this->kosongkanTanggal();

        $this->artisan('complaint:backfill-pengambilan', [
            'berkas' => $this->berkas, '--tulis' => true,
        ])->assertExitCode(0);

        $c = $this->complaintOleh('Pelapor Satu');
        $this->assertSame('2026-04-08', $c->tanggal_pengambilan->toDateString());
        $this->assertSame(TanggalPengambilan::MANUAL, $c->sumber_tanggal_pengambilan);
    }

    public function test_backfill_tanpa_tulis_tidak_menyentuh_basis_data(): void
    {
        $this->impor();
        $this->kosongkanTanggal();

        $this->artisan('complaint:backfill-pengambilan', ['berkas' => $this->berkas])
            ->assertExitCode(0);

        $this->assertNull($this->complaintOleh('Pelapor Satu')->tanggal_pengambilan);
    }

    public function test_backfill_tidak_menimpa_tanggal_dari_nevira(): void
    {
        // Jejak serah terima NEVIRA lebih tahu daripada spreadsheet. Kalau
        // keduanya berbeda, yang menang bukan yang dijalankan belakangan.
        $this->impor();

        $c = $this->complaintOleh('Pelapor Satu');
        $c->forceFill([
            'tanggal_pengambilan' => '2026-04-09',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::AMBIL_SENDIRI,
        ])->save();

        $this->artisan('complaint:backfill-pengambilan', [
            'berkas' => $this->berkas, '--tulis' => true,
        ])->assertExitCode(0);

        $c->refresh();
        $this->assertSame('2026-04-09', $c->tanggal_pengambilan->toDateString());
        $this->assertSame(TanggalPengambilan::AMBIL_SENDIRI, $c->sumber_tanggal_pengambilan);
    }

    public function test_backfill_atas_berkas_yang_belum_pernah_diimpor_tidak_membuat_apa_pun(): void
    {
        $this->artisan('complaint:backfill-pengambilan', [
            'berkas' => $this->berkas, '--tulis' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, Complaint::count());
    }

    public function test_backfill_menolak_berkas_yang_tidak_ada(): void
    {
        $this->artisan('complaint:backfill-pengambilan', [
            'berkas' => base_path('tests/Fixtures/tidak-ada.csv'),
        ])->assertExitCode(1);
    }
}

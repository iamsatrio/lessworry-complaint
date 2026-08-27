<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lampiran lama tidak boleh tertinggal di disk publik. (API-8 T9)
 *
 * Unggahan baru sudah pindah ke disk privat, tapi berkas yang telanjur ada
 * tetap bisa diambil lewat /storage/... tanpa login sama sekali.
 */
class AttachmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function complaint(): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function jalankanMigrasi(): void
    {
        $migrasi = require database_path('migrations/2026_08_27_110000_move_attachments_off_public_disk.php');
        $migrasi->up();
    }

    public function test_berkas_lama_dipindahkan_dari_disk_publik_ke_privat(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $complaint = $this->complaint();
        $path = 'complaints/'.$complaint->id.'/bukti-lama.jpg';

        Storage::disk('public')->put($path, 'isi-foto-bukti');

        ComplaintAttachment::create([
            'complaint_id' => $complaint->id, 'path' => $path, 'original_name' => 'bukti.jpg',
        ]);

        $this->jalankanMigrasi();

        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('isi-foto-bukti', Storage::disk('local')->get($path));
    }

    public function test_berkas_yang_dipindahkan_bisa_dibuka_lewat_rute_berwenang(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $complaint = $this->complaint();
        $path = 'complaints/'.$complaint->id.'/bukti-lama.jpg';

        Storage::disk('public')->put($path, 'isi-foto-bukti');

        $attachment = ComplaintAttachment::create([
            'complaint_id' => $complaint->id, 'path' => $path, 'original_name' => 'bukti.jpg',
        ]);

        $this->jalankanMigrasi();

        $cc = User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);

        $this->actingAs($cc)
            ->get('/complaints/'.$complaint->id.'/lampiran/'.$attachment->id)
            ->assertOk();
    }

    public function test_berkas_yatim_di_disk_publik_ikut_dibersihkan(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // Tidak ada barisnya di basis data, tapi berkasnya tetap bisa diambil
        // lewat /storage/... — justru yang paling mudah terlupakan.
        Storage::disk('public')->put('complaints/99/yatim.jpg', 'isi');

        $this->jalankanMigrasi();

        Storage::disk('public')->assertMissing('complaints/99/yatim.jpg');
        Storage::disk('local')->assertExists('complaints/99/yatim.jpg');
    }

    public function test_migrasi_aman_dijalankan_saat_tidak_ada_berkas_lama(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->jalankanMigrasi();

        $this->assertTrue(true);
    }

    public function test_berkas_yang_sudah_ada_di_privat_tidak_ditimpa(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('local')->put('complaints/1/foto.jpg', 'versi-privat');
        Storage::disk('public')->put('complaints/1/foto.jpg', 'versi-publik-basi');

        $this->jalankanMigrasi();

        $this->assertSame('versi-privat', Storage::disk('local')->get('complaints/1/foto.jpg'));
        Storage::disk('public')->assertMissing('complaints/1/foto.jpg');
    }
}

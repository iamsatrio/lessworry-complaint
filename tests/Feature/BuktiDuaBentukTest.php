<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * API-54 — bukti datang dalam dua bentuk, dan sistem hanya menyebut satu.
 *
 * Keluhan Barang Rusak dibuktikan dengan foto barangnya. Keluhan Berbau
 * dibuktikan dengan tangkapan layar chat pelanggan — yang sudah ada di
 * galeri, tidak pernah dari kamera. Kolomnya secara teknis menerima
 * keduanya sejak awal; yang menghalangi cuma kalimatnya.
 *
 * Dua hal yang dijaga di sini:
 *
 *   1. Kalimatnya menyebut kedua bentuk.
 *   2. Tidak ada `capture` pada kolom unggah mana pun. Menambahkannya
 *      memaksa kamera dan mematikan seluruh jalur Berbau — perbaikan yang
 *      terlihat masuk akal dan akan merusak.
 */
class BuktiDuaBentukTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123',
            'role' => $role,
            'outlet_id' => $outlet?->id,
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);
    }

    private function complaintBerlampiran(User $cc): Complaint
    {
        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nota_exemption' => 'lebih_sebulan',
        ])->assertRedirect();

        return Complaint::latest('id')->firstOrFail();
    }

    /* ---------- 1. Kalimatnya menyebut kedua bentuk ---------- */

    public function test_form_intake_menyebut_foto_dan_tangkapan_layar(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        $this->assertStringContainsString('Lampirkan bukti', $html,
            'Label kolom unggah masih menyebut satu bentuk bukti saja.');
        $this->assertStringContainsString('tangkapan layar', $html,
            'Keterangan kolom unggah tidak memberitahu bahwa tangkapan layar chat boleh dilampirkan.');
        $this->assertStringContainsString('Foto barangnya', $html,
            'Keterangan kolom unggah berhenti menyebut foto barang.');
    }

    public function test_form_catatan_penanganan_menyebut_foto_dan_tangkapan_layar(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaintBerlampiran($cc);

        $html = $this->actingAs($cc)->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertStringContainsString('Lampirkan bukti', $html);
        $this->assertStringContainsString('tangkapan layar chat', $html,
            'Form catatan penanganan masih menyebut foto saja.');
    }

    /* ---------- 2. Tidak ada `capture` pada kolom unggah mana pun ---------- */

    public function test_tidak_ada_capture_pada_kolom_unggah_mana_pun(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaintBerlampiran($cc);

        $halaman = [
            '/complaints/create' => $this->actingAs($cc)->get('/complaints/create')->assertOk()->getContent(),
            '/complaints/{id}' => $this->actingAs($cc)->get('/complaints/'.$complaint->id)->assertOk()->getContent(),
        ];

        foreach ($halaman as $rute => $html) {
            preg_match_all('/<input\b[^>]*type=["\']file["\'][^>]*>/i', $html, $cocok);

            $this->assertNotEmpty($cocok[0], "Kolom unggah hilang dari {$rute}.");

            foreach ($cocok[0] as $input) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bcapture\b/i',
                    $input,
                    "Atribut `capture` muncul di {$rute}. Itu memaksa kamera dan mematikan ".
                    'jalur Berbau, yang buktinya tangkapan layar dari galeri: '.$input
                );
            }
        }
    }

    /* ---------- Tangkapan layar umumnya PNG, dan harus masuk ---------- */

    public function test_png_dari_galeri_tersimpan_sama_seperti_jpeg_dari_kamera(): void
    {
        Storage::fake('local');

        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'berbau',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Baju berbau apek.',
            'nota_exemption' => 'lebih_sebulan',
            'attachments' => [UploadedFile::fake()->image('chat-pelanggan.png', 1080, 2400)],
        ])->assertRedirect();

        $lampiran = Complaint::latest('id')->firstOrFail()->attachments()->first();

        $this->assertNotNull($lampiran, 'Tangkapan layar PNG ditolak di jalur intake.');
        $this->assertSame('image/jpeg', $lampiran->mime,
            'PNG harus disimpan ulang sebagai JPEG, sama seperti foto kamera.');
        Storage::disk('local')->assertExists($lampiran->path);
    }
}

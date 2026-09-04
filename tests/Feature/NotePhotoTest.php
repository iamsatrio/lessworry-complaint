<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PenyimpanFoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * API-20 — foto bukti pada catatan penanganan, dengan kompresi.
 *
 * Dua hal yang dijaga di sini sekaligus:
 *
 *   1. berkasnya benar-benar mengecil, dan metadata kamera ikut hilang —
 *      EXIF bisa memuat koordinat GPS tempat foto diambil;
 *   2. berkasnya tetap di disk privat dan hanya keluar lewat rute yang
 *      memeriksa wewenang, sama seperti lampiran complaint.
 */
class NotePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const PENANDA_EXIF = 'KOORDINATRAHASIA';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(array $attrs = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'Noda belum hilang.',
        ], $attrs));
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /** Foto besar seperti keluaran kamera HP: 3000x2000, penuh derau supaya tidak terkompres habis. */
    private function fotoBesar(string $nama = 'bukti.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor(3000, 2000);

        for ($x = 0; $x < 3000; $x += 5) {
            for ($y = 0; $y < 2000; $y += 5) {
                $warna = imagecolorallocate($img, ($x * 7) % 255, ($y * 13) % 255, ($x + $y) % 255);
                imagefilledrectangle($img, $x, $y, $x + 4, $y + 4, $warna);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'foto').'.jpg';
        imagejpeg($img, $path, 95);
        imagedestroy($img);

        return new UploadedFile($path, $nama, 'image/jpeg', null, true);
    }

    /**
     * JPEG dengan segmen APP1 berlabel Exif yang membawa penanda lokasi.
     *
     * Isinya sengaja dibuat sendiri, bukan diambil dari foto asli: yang
     * diuji adalah apakah segmennya ikut tersimpan, bukan apakah kita bisa
     * membaca EXIF.
     */
    private function fotoBerExif(): UploadedFile
    {
        $img = imagecreatetruecolor(2000, 1500);
        imagefilledrectangle($img, 0, 0, 2000, 1500, imagecolorallocate($img, 120, 40, 40));
        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        $jpeg = file_get_contents($path);
        $payload = "Exif\0\0".self::PENANDA_EXIF.str_repeat("\0", 32);
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Segmen disisipkan tepat setelah SOI (FFD8), tempat EXIF memang duduk.
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'ber-exif.jpg', 'image/jpeg', null, true);
    }

    private function catat(User $user, Complaint $complaint, array $payload)
    {
        return $this->actingAs($user)->post('/complaints/'.$complaint->id.'/note', $payload);
    }

    /* ---------- Kompresi ---------- */

    public function test_foto_besar_benar_benar_mengecil(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $foto = $this->fotoBesar();
        $ukuranAsli = $foto->getSize();

        $this->catat($cc, $complaint, ['note' => 'Sudah dicuci ulang.', 'photos' => [$foto]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        Storage::disk('local')->assertExists($lampiran->path);

        $isi = Storage::disk('local')->get($lampiran->path);
        [$lebar, $tinggi] = getimagesizefromstring($isi);

        $this->assertSame(1600, max($lebar, $tinggi), 'sisi terpanjang tidak dijadikan 1600 px');
        $this->assertLessThan($ukuranAsli, strlen($isi), 'berkas tersimpan tidak lebih kecil dari aslinya');
        $this->assertSame($ukuranAsli, $lampiran->original_bytes);
        $this->assertSame(strlen($isi), $lampiran->stored_bytes);
        $this->assertNull($lampiran->compression_error);
    }

    public function test_thumbnail_dibuat_untuk_lini_masa(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Foto kondisi barang.', 'photos' => [$this->fotoBesar()]]);

        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->assertNotNull($lampiran->thumb_path);
        Storage::disk('local')->assertExists($lampiran->thumb_path);

        [$lebar, $tinggi] = getimagesizefromstring(Storage::disk('local')->get($lampiran->thumb_path));
        $this->assertLessThanOrEqual(320, max($lebar, $tinggi));
    }

    public function test_metadata_exif_dibuang(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Bukti serah terima.', 'photos' => [$this->fotoBerExif()]])
            ->assertSessionHasNoErrors();

        $isi = Storage::disk('local')->get(
            ComplaintActivity::latest('id')->first()->attachments()->sole()->path
        );

        $this->assertStringNotContainsString(self::PENANDA_EXIF, $isi, 'penanda lokasi masih ada di berkas tersimpan');
        $this->assertStringNotContainsString("Exif\0\0", $isi, 'segmen EXIF masih ikut tersimpan');
    }

    public function test_kegagalan_kompresi_tidak_membatalkan_catatan(): void
    {
        $this->app->bind(PenyimpanFoto::class, fn () => new class extends PenyimpanFoto
        {
            public function simpan(UploadedFile $file, string $dir): array
            {
                throw new RuntimeException('gd tiba-tiba mati');
            }
        });

        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Catatan penting.', 'photos' => [$this->fotoBesar()]])
            ->assertRedirect();

        $activity = ComplaintActivity::latest('id')->first();

        $this->assertSame('Catatan penting.', $activity->note, 'catatannya ikut hilang saat foto gagal disimpan');
    }

    /* ---------- Apa yang boleh diunggah ---------- */

    public function test_berkas_bukan_gambar_ditolak_walau_berekstensi_jpg(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        // Ekstensi dan Content-Type mengaku gambar; isinya bukan.
        $palsu = UploadedFile::fake()->createWithContent('bukti.jpg', '<?php echo "bukan gambar"; ?>');

        $this->catat($cc, $complaint, ['note' => 'Coba unggah.', 'photos' => [$palsu]])
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, ComplaintActivity::query()->whereNotNull('note')->count());
    }

    public function test_jumlah_foto_per_catatan_dibatasi(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $foto = array_map(fn ($i) => UploadedFile::fake()->image('f'.$i.'.jpg', 400, 300), range(1, 7));

        $this->catat($cc, $complaint, ['note' => 'Banyak foto.', 'photos' => $foto])
            ->assertSessionHasErrors('photos');
    }

    public function test_catatan_tanpa_foto_tetap_bisa_disimpan(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Tanpa foto.'])->assertSessionHasNoErrors();

        $this->assertSame('Tanpa foto.', ComplaintActivity::latest('id')->first()->note);
    }

    /* ---------- Siapa yang boleh mengambil berkasnya ---------- */

    public function test_foto_catatan_tidak_bisa_diambil_lewat_complaint_lain(): void
    {
        $cc = $this->userAs('customer_care');
        $satu = $this->complaint();
        $dua = $this->complaint();

        $this->catat($cc, $satu, ['note' => 'Foto.', 'photos' => [$this->fotoBesar()]]);
        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->actingAs($cc)->get('/complaints/'.$dua->id.'/lampiran/'.$lampiran->id)->assertNotFound();
        $this->actingAs($cc)->get('/complaints/'.$dua->id.'/lampiran/'.$lampiran->id.'/kecil')->assertNotFound();
    }

    public function test_tamu_tidak_bisa_mengambil_foto_catatan(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Foto.', 'photos' => [$this->fotoBesar()]]);
        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->post('/logout');

        $this->get('/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id)->assertRedirect('/login');
        $this->get('/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id.'/kecil')->assertRedirect('/login');
    }

    public function test_kasir_outlet_lain_tidak_bisa_mengambil_foto_catatan(): void
    {
        $pusat = Outlet::create(['name' => 'Pusat']);
        $cabang = Outlet::create(['name' => 'Cabang']);

        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint(['outlet_id' => $pusat->id]);

        $this->catat($cc, $complaint, ['note' => 'Foto.', 'photos' => [$this->fotoBesar()]]);
        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->actingAs($this->userAs('kasir', $cabang))
            ->get('/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id)
            ->assertForbidden();
    }

    public function test_foto_disajikan_dari_disk_privat_bukan_disk_publik(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Foto.', 'photos' => [$this->fotoBesar()]]);
        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->assertStringNotContainsString('public', $lampiran->path);

        $this->actingAs($cc)->get('/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id)->assertOk();
    }

    /* ---------- Tampil di lini masa ---------- */

    public function test_foto_muncul_di_lini_masa_riwayat(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Sudah dicuci ulang.', 'photos' => [$this->fotoBesar()]]);
        $lampiran = ComplaintActivity::latest('id')->first()->attachments()->sole();

        $this->actingAs($cc)->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id.'/kecil', false);
    }

    public function test_foto_catatan_tidak_ikut_ke_daftar_lampiran_keluhan(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->catat($cc, $complaint, ['note' => 'Foto.', 'photos' => [$this->fotoBesar()]]);

        $this->assertSame(0, $complaint->intakeAttachments()->count(),
            'foto catatan ikut terhitung sebagai lampiran keluhan saat complaint dibuat');
        $this->assertSame(1, $complaint->attachments()->count());
    }
}

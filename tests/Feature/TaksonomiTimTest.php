<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-25 — kategori, bobot, SLA, dan laporan memakai taksonomi tim.
 *
 * Yang dijaga di sini bukan selera penamaan. Dua kategori yang dibuang tidak
 * pernah muncul sekali pun pada 1.032 baris data nyata, sementara `Kurang
 * Rapih` (4% kasus) tidak punya pilihan sama sekali — kasir yang mengalaminya
 * terpaksa memilih sesuatu yang salah, dan yang salah itu masuk laporan
 * terlihat seperti data yang benar.
 */
class TaksonomiTimTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function intake(array $ganti = []): array
    {
        return array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Keluhan uji',
            'nota_exemption' => 'lebih_sebulan',
        ], $ganti);
    }

    /* ---------- 1. Form intake ---------- */

    public function test_form_intake_memakai_kedelapan_kategori_tim(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        foreach (['barang_rusak', 'kurang_bersih', 'barang_hilang', 'berbau',
                  'kurang_rapih', 'barang_tertukar', 'terlambat', 'lainnya'] as $kunci) {
            $this->assertStringContainsString('value="'.$kunci.'"', $html,
                "Kategori tim '$kunci' tidak ada di form intake.");
        }
    }

    public function test_kategori_karangan_hilang_dari_form_dan_ditolak_server(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('value="salah_tagih"', $html);
        $this->assertStringNotContainsString('value="sikap_petugas"', $html);

        // Layar bukan penjaganya. Servernya yang menolak.
        foreach (['salah_tagih', 'sikap_petugas'] as $karangan) {
            $this->actingAs($this->userAs('customer_care'))
                ->post('/complaints', $this->intake(['category' => $karangan]))
                ->assertSessionHasErrors('category');
        }

        $this->assertSame(0, Complaint::count());
    }

    public function test_kurang_rapih_bisa_dipilih_dan_tersimpan(): void
    {
        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints', $this->intake(['category' => 'kurang_rapih']))
            ->assertRedirect();

        $this->assertSame('kurang_rapih', Complaint::latest('id')->first()->category);
    }

    /**
     * Sub-kategori yang naik jadi kategori tidak boleh tetap menggantung di
     * bawah induk lamanya — dua jalan menuju hal yang sama membuat rekapnya
     * terbelah dua.
     */
    public function test_tidak_ada_sub_kategori_yang_menduplikasi_kategori(): void
    {
        $kategori = collect(config('complaint.categories'));
        $label = $kategori->map(fn ($c) => mb_strtolower($c['label']))->values();

        foreach ($kategori as $kunci => $definisi) {
            foreach ($definisi['sub'] as $sub) {
                $this->assertNotContains(mb_strtolower($sub), $label,
                    "Sub-kategori '$sub' di bawah '$kunci' menduplikasi sebuah kategori.");
            }
        }
    }

    /* ---------- 2. SLA lewat jalur intake yang sebenarnya ---------- */

    public function test_complaint_ringan_dan_berat_mendapat_tenggat_hari_yang_benar(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake(['bobot' => 'ringan']));
        $ringan = Complaint::latest('id')->first();

        $this->actingAs($cc)->post('/complaints', $this->intake(['bobot' => 'berat']));
        $berat = Complaint::latest('id')->first();

        $this->assertSame(2 * 24 * 60, (int) $ringan->created_at->diffInMinutes($ringan->due_resolution_at));
        $this->assertSame(5 * 24 * 60, (int) $berat->created_at->diffInMinutes($berat->due_resolution_at));

        // Respon pertama sama untuk keduanya: janji publik 1x24 jam.
        $this->assertSame(24 * 60, (int) $ringan->created_at->diffInMinutes($ringan->due_response_at));
        $this->assertSame(24 * 60, (int) $berat->created_at->diffInMinutes($berat->due_response_at));
    }

    public function test_layanan_wajib_diisi(): void
    {
        $data = $this->intake();
        unset($data['layanan']);

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints', $data)->assertSessionHasErrors('layanan');

        $this->assertSame(0, Complaint::count());
    }

    public function test_bobot_wajib_diisi_dan_di_luar_daftar_ditolak(): void
    {
        $cc = $this->userAs('customer_care');

        $data = $this->intake();
        unset($data['bobot']);
        $this->actingAs($cc)->post('/complaints', $data)->assertSessionHasErrors('bobot');

        $this->actingAs($cc)->post('/complaints', $this->intake(['bobot' => 'urgent']))
            ->assertSessionHasErrors('bobot');

        $this->assertSame(0, Complaint::count());
    }

    /* ---------- 3. Papan pantau ---------- */

    public function test_complaint_berumur_enam_jam_tidak_ditandai_merah(): void
    {
        $cc = $this->userAs('customer_care');

        // Bobot Ringan: tenggat terpendek yang ada. Kalau yang ini pun tidak
        // merah di jam keenam, tidak ada bobot lain yang bisa.
        $this->actingAs($cc)->post('/complaints', $this->intake(['bobot' => 'ringan']));

        $complaint = Complaint::latest('id')->first();
        $complaint->forceFill(['created_at' => now()->subHours(6)])->save();
        $complaint->applySla();
        $complaint->save();

        $complaint->refresh();

        $this->assertFalse($complaint->isOverdue(), 'complaint berumur 6 jam sudah dianggap lewat tenggat');
        $this->assertFalse($complaint->isResponseOverdue());
        $this->assertNotSame('late', $complaint->slaMeter()['state']);
        $this->assertNotSame('warn', $complaint->slaMeter()['state']);

        // Papannya menyebut complaint ini terbuka, bukan lewat tenggat.
        $this->actingAs($cc)->get('/dashboard')->assertOk()
            ->assertSee('semuanya masih dalam tenggat', false)
            ->assertDontSee('Lewat tenggat — tangani lebih dulu', false);
    }

    /* ---------- 6. Laporan ---------- */

    public function test_laporan_bisa_dikelompokkan_per_layanan_dan_tindak_lanjut(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake(['layanan' => 'satuan_bedding']));
        $satuan = Complaint::latest('id')->first();

        $this->actingAs($cc)->post('/complaints/'.$satuan->id.'/status', [
            'lock_version' => $satuan->fresh()->lock_version,
            'status' => 'close', 'close_reason' => 'selesai', 'tindak_lanjut' => 'proses_ulang',
        ])->assertSessionHasNoErrors();

        $this->actingAs($cc)->post('/complaints', $this->intake(['layanan' => 'kiloan']));

        $html = $this->actingAs($cc)->get('/reports')->assertOk()->getContent();

        $this->assertStringContainsString('Layanan yang dikeluhkan', $html);
        $this->assertStringContainsString('Satuan Bedding', $html);
        $this->assertStringContainsString('Tindak lanjut', $html);
        $this->assertStringContainsString('Proses Ulang', $html);
    }

    /**
     * "Ditolak" bukan lagi status tersendiri. Kemampuan memisahkannya dari
     * "selesai" tidak boleh ikut hilang — hanya pindah tempat.
     */
    public function test_laporan_memisahkan_yang_selesai_dari_yang_ditolak(): void
    {
        $cc = $this->userAs('customer_care');

        foreach (['selesai', 'ditolak'] as $alasan) {
            $this->actingAs($cc)->post('/complaints', $this->intake());
            $complaint = Complaint::latest('id')->first();

            $this->actingAs($cc)->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'close', 'close_reason' => $alasan,
            ])->assertSessionHasNoErrors();
        }

        $html = $this->actingAs($cc)->get('/reports')->assertOk()->getContent();

        $this->assertStringContainsString('Ditutup Selesai', $html);
        $this->assertStringContainsString('Ditutup Ditolak', $html);

        $csv = $this->actingAs($cc)->get('/reports/export')->streamedContent();

        $this->assertStringContainsString('Alasan Penutupan', $csv);
        $this->assertStringContainsString('Ditolak', $csv);
        $this->assertStringNotContainsString('Prioritas', $csv);
        $this->assertStringContainsString('Bobot', $csv);
    }

    public function test_tiket_close_wajib_menyebut_alasan_penutupan(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake());
        $complaint = Complaint::latest('id')->first();

        $this->actingAs($cc)->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version, 'status' => 'close',
        ])->assertSessionHasErrors('close_reason');

        $this->assertSame('open', $complaint->fresh()->status);
    }
}

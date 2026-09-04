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
 * pernah muncul sekali pun pada 545 baris data nyata, sementara `Kurang Rapih`
 * (5,9% kasus 2026) tidak punya pilihan sama sekali — kasir yang mengalaminya
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
        $this->assertStringContainsString('Proses ulang', $html);
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

    /* ---------- Isi dan urutan enum (koreksi dari CSV asli, 545 baris) ---------- */

    /**
     * Urutan dropdown itu bagian dari datanya, bukan selera.
     *
     * Kasir mengisi form sambil melayani antrean; pilihan yang paling sering
     * dipakai harus paling dekat dengan jempol. Test ini menahan seseorang
     * merapikan daftarnya menurut abjad suatu hari nanti — perubahan yang
     * terlihat tidak berbahaya dan tidak akan menggagalkan apa pun selain ini.
     */
    public function test_urutan_kategori_mengikuti_porsi_2026(): void
    {
        $this->assertSame([
            'kurang_bersih',   // 27,8%
            'barang_rusak',    // 20,7%
            'barang_hilang',   // 13,9%
            'terlambat',       // 12,2%
            'berbau',          //  8,4%
            'lainnya',         //  7,6%
            'kurang_rapih',    //  5,9%
            'barang_tertukar', //  3,4%
        ], array_keys(config('complaint.categories')));
    }

    /**
     * Layanan punya ENAM nilai. Kiloan dicatat tim dalam tiga varian, dan
     * menggabungkannya jadi satu membuang justru perbedaan yang mau dilihat.
     */
    public function test_layanan_punya_enam_nilai_termasuk_tiga_varian_kiloan(): void
    {
        $layanan = config('complaint.layanan');

        $this->assertCount(6, $layanan);
        $this->assertSame([
            'kiloan_cuset', 'kiloan', 'kiloan_culip',
            'satuan_non_cloth', 'satuan_cloth', 'satuan_bedding',
        ], array_keys($layanan));

        // Empat nilai lama tetap sah — tidak ada complaint tersimpan yang
        // kehilangan layanannya karena daftarnya bertambah.
        foreach (['kiloan', 'satuan_cloth', 'satuan_bedding', 'satuan_non_cloth'] as $lama) {
            $this->assertArrayHasKey($lama, $layanan);
        }
    }

    public function test_urutan_tindak_lanjut_mengikuti_porsi_2026(): void
    {
        $this->assertSame([
            'proses_ulang',   // 38,0%
            'tracking',       // 19,8%
            'terkonfirmasi',  // 18,6%
            'compensate',     // 12,7%
            'voucher',        //  6,3%
            'repair',         //  2,1%
            'delivery_ulang', //  1,7%
            'pickup_ulang',   //  0,4%
            'repaint',        //     0% pada 2026, 2 kejadian pada 2025
        ], array_keys(config('complaint.tindak_lanjut')));
    }

    /** Nilai baru benar-benar bisa disimpan lewat jalur intake, bukan cuma ada di config. */
    public function test_varian_kiloan_baru_bisa_disimpan(): void
    {
        $cc = $this->userAs('customer_care');

        foreach (['kiloan_cuset', 'kiloan_culip'] as $layanan) {
            $this->actingAs($cc)->post('/complaints', $this->intake(['layanan' => $layanan]))
                ->assertRedirect();

            $this->assertSame($layanan, Complaint::latest('id')->first()->layanan);
        }
    }

    /**
     * Config boleh benar urutannya sementara view menyusunnya ulang. Yang
     * dilihat kasir adalah HTML-nya, jadi itu yang diperiksa.
     */
    public function test_urutan_dropdown_di_form_sama_dengan_urutan_config(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        foreach (['cat' => 'categories', 'lay' => 'layanan'] as $id => $kunciConfig) {
            preg_match('/<select id="'.$id.'".*?<\/select>/s', $html, $m);
            $this->assertNotEmpty($m, "Dropdown '$id' tidak ditemukan di form intake.");

            preg_match_all('/<option value="([a-z_]+)"/', $m[0], $opsi);

            $this->assertSame(
                array_keys(config('complaint.'.$kunciConfig)),
                $opsi[1],
                "Urutan dropdown '$id' di layar berbeda dari urutan di config."
            );
        }
    }

    /* ---------- 5. Complaint yang belum ditangani harus berhenti di Open ---------- */

    /**
     * Dari 545 baris data tim, `Open` tidak pernah dipakai sekali pun — sheet
     * diisi saat complaint DITUTUP, jadi tiket yang masih menggantung tidak
     * pernah tercatat sama sekali.
     *
     * Papan kerja ini akan memperlihatkan hal yang selama ini tidak terlihat
     * siapa pun. Syaratnya `Open` harus benar-benar bisa dicapai: tiket yang
     * dibuat tanpa penanganan berhenti di sana, dan tidak melompat ke
     * `Handling` hanya karena pembuatnya dianggap penangannya.
     */
    public function test_complaint_baru_berhenti_di_open_bukan_handling(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake())->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertSame('open', $complaint->status);
        $this->assertNull($complaint->assigned_to, 'complaint baru tidak boleh menugaskan pembuatnya sendiri');
        $this->assertNull($complaint->first_response_at, 'complaint yang belum disentuh tidak boleh terhitung sudah direspon');
        $this->assertSame('open', $complaint->activities()->where('type', 'created')->first()->to_status);
    }

    /** Menugaskan seseorang itu pencatatan, bukan penanganan — statusnya tidak ikut bergerak. */
    public function test_menugaskan_penanggung_jawab_tidak_memindahkan_tiket_dari_open(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake())->assertRedirect();
        $complaint = Complaint::latest('id')->first();

        $this->actingAs($cc)->post('/complaints/'.$complaint->id.'/assign', [
            'assigned_to' => $cc->id,
        ])->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame($cc->id, $complaint->assigned_to);
        $this->assertSame('open', $complaint->status,
            'tiket melompat ke Handling hanya karena ada yang ditugaskan — Open jadi status yang tidak pernah terlihat');
    }

    /** Papan kerja memang menampilkan tiket Open, bukan menyembunyikannya. */
    public function test_papan_kerja_menampilkan_tiket_open(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->intake())->assertRedirect();
        $complaint = Complaint::latest('id')->first();

        $this->actingAs($cc)->get('/complaints')->assertOk()
            ->assertSee($complaint->ticket_number, false)
            ->assertSee('Open', false);
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

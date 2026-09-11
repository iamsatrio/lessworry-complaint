<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Support\PolaNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * API-26 — intake satu langkah.
 *
 * Dua hal yang diuji di sini, dan keduanya soal kepercayaan:
 *
 *   Bagian 1 — centang "sudah saya tangani di tempat" tidak boleh jadi jalan
 *   pintas melewati wewenang. Syaratnya sama persis dengan menutup lewat
 *   halaman complaint.
 *
 *   Bagian 2 — pengenal diambil dari nota WhatsApp, dan SISANYA dibuang.
 *   Teks itu memuat alamat, telepon outlet, dan saldo deposit pelanggan.
 */
class IntakeSatuLangkahTest extends TestCase
{
    use RefreshDatabase;

    /** Nota WhatsApp nyata dari satrio, dipendekkan seperlunya. */
    private const NOTA_WA = <<<'TXT'
        🧺 Jati Padang
        Jl. Raya Ragunan No. 12, Jakarta Selatan
        Telp outlet: 021-7890123

        ✨ Pantau progres cucianmu di sini:
        https://nevira.id/webstruk/f3379aef701b

        Nota INV/122/1788010384114/1
        Masuk     : 29-08-2026 20:33
        Estimasi  : 01-09-2026 20:33

        • Kiloan - Cuci Setrika (3 Hari) · 3 Kg

        Saldo Awal  : Rp55.226
        Saldo Akhir : Rp32.726
        TXT;

    private function outlet(string $name = 'Tebet', ?string $neviraId = '118'): Outlet
    {
        return Outlet::create(['name' => $name, 'nevira_outlet_id' => $neviraId]);
    }

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

    /** @param array<string,mixed> $extra */
    private function formIntake(array $extra = []): array
    {
        return array_merge([
            'channel' => 'kasir',
            'reporter_name' => 'Pelapor',
            'reporter_phone' => '081200001111',
            'nevira_transaction_number' => 'INV/118/1787749345365/1',
            'category' => 'kurang_bersih',
            'bobot' => 'ringan',
            'layanan' => 'kiloan',
            'description' => 'Kaos putih masih ada noda.',
        ], $extra);
    }

    private function terbaru(): Complaint
    {
        return Complaint::latest('id')->firstOrFail();
    }

    /* ================= Kriteria 1 — Ringan tanpa kompensasi ================= */

    public function test_kasir_menutup_complaint_ringan_dalam_satu_kali_simpan(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Dicuci ulang saat itu juga, pelanggan menunggu.',
            'tindak_lanjut' => 'proses_ulang',
        ]))->assertRedirect();

        $complaint = $this->terbaru();

        $this->assertSame('close', $complaint->status);
        $this->assertSame('selesai', $complaint->close_reason);
        $this->assertNotNull($complaint->resolved_at);
        $this->assertSame('proses_ulang', $complaint->tindak_lanjut);
        $this->assertSame(0, (int) $complaint->compensation_amount);
        // Sudah ditangani berarti sudah direspons.
        $this->assertNotNull($complaint->first_response_at);
    }

    /**
     * Riwayat memperlihatkan DUA kejadian terpisah walau lahir dari satu kali
     * simpan. Diringkas jadi satu baris akan menghemat sebaris dan merusak
     * laporan waktu penyelesaian — ia dihitung dari selisih kedua stempel itu.
     */
    public function test_riwayat_memuat_dibuat_lalu_ditutup(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Dicuci ulang.',
            'tindak_lanjut' => 'proses_ulang',
        ]));

        $jejak = $this->terbaru()->activities()->orderBy('id')->get();

        $this->assertCount(2, $jejak, 'riwayat tiket tangani-di-tempat harus dua baris');
        $this->assertSame('created', $jejak[0]->type);
        $this->assertSame('open', $jejak[0]->to_status);
        $this->assertSame('status_change', $jejak[1]->type);
        $this->assertSame('open', $jejak[1]->from_status);
        $this->assertSame('close', $jejak[1]->to_status);
    }

    public function test_waktu_penyelesaian_nol_hari_bukan_hilang(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Dicuci ulang.',
            'tindak_lanjut' => 'proses_ulang',
        ]));

        $complaint = $this->terbaru();

        $this->assertNotNull($complaint->resolved_at);
        $this->assertSame(0, (int) $complaint->created_at->diffInDays($complaint->resolved_at));
    }

    /* ================= Kriteria 2 — bobot Berat ================= */

    public function test_kasir_mencentang_pada_complaint_berat_menghasilkan_handling(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'bobot' => 'berat',
            'tangani_di_tempat' => '1',
            'resolution' => 'Sudah saya ganti rugi di tempat.',
            'tindak_lanjut' => 'compensate',
        ]));

        $complaint = $this->terbaru();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertNull($complaint->resolved_at);
        // Catatan penyelesaiannya TIDAK hilang — itu yang membedakan
        // "tersimpan sebagai Handling" dari "ditolak".
        $this->assertSame('Sudah saya ganti rugi di tempat.', $complaint->resolution);
        $this->assertSame('compensate', $complaint->tindak_lanjut);

        $alasan = (string) $response->getSession()->get('penutupan_ditolak');
        $this->assertStringContainsString('Ringan', $alasan);
        $this->assertStringContainsString('Berat', $alasan);
    }

    /* ================= Kriteria 3 — kompensasi di atas batas ================= */

    public function test_kasir_mencentang_pada_ringan_berkompensasi_seratus_ribu_menghasilkan_handling(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Diganti dengan yang baru.',
            'tindak_lanjut' => 'compensate',
            'compensation_amount' => 100000,
        ]));

        $complaint = $this->terbaru();

        $this->assertSame('handling', $complaint->status);
        $this->assertSame(100000, (int) $complaint->compensation_amount);

        // Alasannya menyebut ANGKANYA, bukan pesan galat umum.
        $alasan = (string) $response->getSession()->get('penutupan_ditolak');
        $this->assertStringContainsString('50.000', $alasan);
        $this->assertStringContainsString('100.000', $alasan);
    }

    public function test_batas_kompensasi_kasir_tepat_lima_puluh_ribu_masih_boleh(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Diganti.',
            'tindak_lanjut' => 'compensate',
            'compensation_amount' => 50000,
        ]));

        $this->assertSame('close', $this->terbaru()->status);
    }

    public function test_satu_rupiah_di_atas_batas_sudah_tidak_boleh(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Diganti.',
            'tindak_lanjut' => 'compensate',
            'compensation_amount' => 50001,
        ]));

        $this->assertSame('handling', $this->terbaru()->status);
    }

    public function test_customer_care_menutup_bobot_apa_pun(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', $this->formIntake([
            'channel' => 'wa_cc',
            'bobot' => 'berat',
            'tangani_di_tempat' => '1',
            'resolution' => 'Sudah diselesaikan lewat telepon.',
            'tindak_lanjut' => 'terkonfirmasi',
        ]));

        $this->assertSame('close', $this->terbaru()->status);
    }

    /* ================= Kriteria 4 — melewati tampilan ================= */

    /**
     * Permintaan yang dirakit tangan tidak punya jalan lain: statusnya sendiri
     * tidak pernah diterima sebagai masukan, dan wewenangnya dihitung ulang
     * di server dari peran dan bobot.
     */
    public function test_status_yang_dipaksakan_lewat_permintaan_diabaikan(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'bobot' => 'berat',
            'tangani_di_tempat' => '1',
            'resolution' => 'Beres.',
            'tindak_lanjut' => 'compensate',
            // Dua-duanya dipaksakan dari luar tampilan.
            'status' => 'close',
            'close_reason' => 'selesai',
            'resolved_at' => now()->toDateTimeString(),
        ]));

        $complaint = $this->terbaru();

        $this->assertSame('handling', $complaint->status, 'status dari payload tidak boleh dipakai');
        $this->assertNull($complaint->close_reason);
        $this->assertNull($complaint->resolved_at);
    }

    /**
     * Kolom penyelesaian hanya berlaku lewat centangnya. Tanpa pemisahan ini,
     * resolusi dan kompensasi bisa dititipkan ke tiket biasa tanpa pernah
     * melewati wewenang penutupan.
     */
    public function test_kolom_penyelesaian_diabaikan_kalau_centangnya_tidak_dipakai(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'resolution' => 'Diam-diam terisi.',
            'tindak_lanjut' => 'compensate',
            'compensation_amount' => 500000,
        ]));

        $complaint = $this->terbaru();

        $this->assertSame('open', $complaint->status);
        $this->assertNull($complaint->resolution);
        $this->assertNull($complaint->tindak_lanjut);
        $this->assertSame(0, (int) $complaint->compensation_amount);
    }

    public function test_centang_tanpa_penyelesaian_ditolak_validasi(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)
            ->post('/complaints', $this->formIntake(['tangani_di_tempat' => '1']))
            ->assertSessionHasErrors(['resolution', 'tindak_lanjut']);

        $this->assertSame(0, Complaint::count());
    }

    public function test_tindak_lanjut_karangan_ditolak(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'tangani_di_tempat' => '1',
            'resolution' => 'Beres.',
            'tindak_lanjut' => 'dikarang',
        ]))->assertSessionHasErrors('tindak_lanjut');
    }

    /* ================= Kriteria 5 dan 6 — tempel nota WhatsApp ================= */

    public function test_nomor_nota_terambil_dari_nota_whatsapp(): void
    {
        $hasil = PolaNota::ambil(self::NOTA_WA);

        $this->assertSame('INV/122/1788010384114/1', $hasil['invoice']);
        $this->assertSame('f3379aef701b', $hasil['webstruk']);
        $this->assertSame('29-08-2026', $hasil['masuk']);
        $this->assertFalse($hasil['terlalu_panjang']);
    }

    public function test_tanpa_baris_nota_inv_tidak_ada_yang_ditebak(): void
    {
        // Baris "Nota INV/…" dibuang; angka pendek 4348 sengaja ditinggal.
        $tanpaInv = str_replace('Nota INV/122/1788010384114/1', 'Nota 4348 (Agustus)', self::NOTA_WA);

        $hasil = PolaNota::ambil($tanpaInv);

        $this->assertNull($hasil['invoice'], 'angka pendek tidak boleh diterima sebagai nomor nota');
        // Webstruk tetap terambil sebagai rujukan manusia.
        $this->assertSame('f3379aef701b', $hasil['webstruk']);
    }

    public function test_teks_raksasa_ditolak_sebelum_regex_dijalankan(): void
    {
        $hasil = PolaNota::ambil(str_repeat('a', 100_000));

        $this->assertTrue($hasil['terlalu_panjang']);
        $this->assertNull($hasil['invoice']);
    }

    public function test_teks_kosong_bukan_kesalahan(): void
    {
        foreach ([null, '', '   '] as $teks) {
            $hasil = PolaNota::ambil($teks);
            $this->assertNull($hasil['invoice']);
            $this->assertFalse($hasil['terlalu_panjang']);
        }
    }

    /* ================= Kriteria 7 — data pribadi tidak tersimpan ================= */

    /**
     * Pengambilan terjadi di peramban, jadi teks tempelan tidak pernah dikirim.
     * Test ini membuktikan lapis keduanya: permintaan yang MEMAKSA mengirimnya
     * tetap tidak menaruh apa pun dari teks itu ke basis data.
     */
    public function test_saldo_deposit_alamat_dan_telepon_outlet_tidak_masuk_database(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            // Kolom yang tidak ada di aturan validasi — dirakit tangan.
            'nota_tempel' => self::NOTA_WA,
            'tempel' => self::NOTA_WA,
            'nevira_webstruk_token' => 'f3379aef701b',
        ]))->assertRedirect();

        $baris = DB::table('complaints')->get()
            ->concat(DB::table('complaint_activities')->get());
        $isi = $baris->map(fn ($r) => json_encode($r))->implode(' ');

        foreach (['55.226', '32.726', 'Saldo', 'Jl. Raya Ragunan', '021-7890123'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi,
                'potongan nota WhatsApp bocor ke database: '.$rahasia);
        }

        // Yang BOLEH tersimpan hanya token webstruk-nya.
        $this->assertSame('f3379aef701b', $this->terbaru()->nevira_webstruk_token);
    }

    public function test_token_webstruk_yang_bukan_token_ditolak(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->post('/complaints', $this->formIntake([
            'nevira_webstruk_token' => 'Saldo Awal: Rp55.226',
        ]))->assertSessionHasErrors('nevira_webstruk_token');
    }

    /* ================= Form ================= */

    public function test_form_intake_memuat_kotak_tempel_dan_centangnya(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->get('/complaints/create');

        $response->assertOk();
        $response->assertSee('id="tempel"', false);
        $response->assertSee('name="tangani_di_tempat"', false);
        // Kotak tempel TIDAK punya name: isinya tidak boleh ikut terkirim.
        $this->assertStringNotContainsString('name="tempel"', $response->getContent());
    }

    public function test_kasir_diberi_tahu_batas_wewenangnya_sebelum_mengetik(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->get('/complaints/create')
            ->assertSee('Rp 50.000', false)
            ->assertSee('Ringan', false);
    }
}

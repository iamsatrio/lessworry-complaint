<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Id internal NEVIRA tidak boleh keluar dari sistem lewat jalan mana pun.
 * (API-8 T2/T12, API-14 #4, API-58 nomor 1)
 *
 * Yang dipegang petugas adalah nomor nota. Id internal hanya alat panggil
 * API — kalau bocor, ia jadi bahan tebakan untuk menyisir NEVIRA.
 *
 * Berkas ini dulu hanya menjaga `nevira_transaction_id`, dan jenis id yang
 * lain lolos begitu saja — `id_staff` tembus ke DOM sebagai nilai checkbox
 * selama berbulan-bulan tanpa satu pun test yang jatuh. Sekarang yang
 * dituntut SETIAP nilai internal yang dibawa snapshot nota, bukan satu kolom.
 */
class InternalIdLeakTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    private const ID_INTERNAL = '31242';

    /**
     * Id internal yang dibawa snapshot nota selain id transaksi. Nilainya
     * sengaja dibuat panjang dan khas: angka pendek seperti `244` bisa muncul
     * kebetulan di dalam CSS atau di dalam heksa kunci, dan test yang jatuh
     * karena kebetulan sama buruknya dengan test yang tidak pernah jatuh.
     */
    private const ID_KASIR = 'STAFF-INTERNAL-KASIR-99110';

    private const ID_PRODUKSI = 'STAFF-INTERNAL-PRODUKSI-99120';

    private const ID_KURIR = 'STAFF-INTERNAL-KURIR-99130';

    private const ID_PELANGGAN = 'CUSTOMER-INTERNAL-99140';

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaintTertaut(?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'reporter_phone' => '081200001111',
            'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
            'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill(['nevira_transaction_id' => self::ID_INTERNAL])->save();

        return $complaint;
    }

    public function test_ekspor_csv_supervisor_tidak_memuat_id_internal(): void
    {
        $this->complaintTertaut();

        $csv = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export')->streamedContent();

        $this->assertStringNotContainsString(self::ID_INTERNAL, $csv,
            'id internal NEVIRA ikut terekspor; CSV rekap beredar lewat WhatsApp');
        $this->assertStringNotContainsString('ID Transaksi NEVIRA', $csv,
            'kolomnya masih berlabel id internal');
    }

    public function test_ekspor_csv_kasir_tidak_memuat_id_internal(): void
    {
        $outlet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);
        $this->complaintTertaut($outlet);

        $csv = $this->actingAs($this->userAs('kasir', $outlet))
            ->get('/reports/export')->streamedContent();

        $this->assertStringNotContainsString(self::ID_INTERNAL, $csv);
    }

    public function test_ekspor_csv_tetap_membawa_nomor_nota(): void
    {
        $this->complaintTertaut();

        $csv = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export')->streamedContent();

        $this->assertStringContainsString(self::NOTA, $csv,
            'nomor nota justru yang dibutuhkan di rekap — jangan ikut dibuang');
        $this->assertStringContainsString('Nomor Nota', $csv);
    }

    /**
     * Complaint bernota tertaut, lengkap dengan snapshot yang membawa setiap
     * jenis id internal yang bisa ikut terbawa dari NEVIRA.
     */
    private function complaintBersnapshot(?Outlet $outlet = null): Complaint
    {
        $complaint = $this->complaintTertaut($outlet);

        $complaint->forceFill(['nevira_snapshot' => [
            'invoice' => self::NOTA,
            'outlet_id' => 118,
            'customer_id' => self::ID_PELANGGAN,
            'cashier_name' => 'Gilang Ramadhan',
            'cashier_nip' => 'LW/06-0002',
            'cashier_id' => self::ID_KASIR,
            'processes' => [[
                'stage' => 'Cuci', 'staff_name' => 'Budi Santoso', 'staff_nip' => 'LW/02',
                'staff_id' => self::ID_PRODUKSI, 'status' => 'COMPLETED', 'duration' => 600,
            ]],
            'deliveries' => [[
                'id' => 1, 'date' => '2026-08-20', 'status_code' => 2, 'status' => 'Diantar',
                'courier_name' => 'Rizky Kurir', 'courier_nip' => 'LW/07-0010',
                'courier_id' => self::ID_KURIR, 'queue_no' => 3, 'proof_count' => 0,
            ]],
        ]])->save();

        return $complaint;
    }

    /* ---------- Setiap id internal, bukan cuma id transaksi (API-58 #1) ---------- */

    /**
     * Gagal sebelum API-58 nomor 1: kunci kandidat pelaku dirender apa adanya
     * sebagai `value="staff:<id_staff>"`, jadi pengenal karyawan di sistem
     * lain beredar ke peramban setiap petugas yang membuka complaint.
     */
    public function test_halaman_complaint_tidak_memuat_satu_pun_id_internal(): void
    {
        $complaint = $this->complaintBersnapshot();

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        foreach ([
            'id transaksi' => self::ID_INTERNAL,
            'id kasir' => self::ID_KASIR,
            'id karyawan produksi' => self::ID_PRODUKSI,
            'id kurir' => self::ID_KURIR,
            'id pelanggan' => self::ID_PELANGGAN,
        ] as $sebutan => $nilai) {
            $this->assertStringNotContainsString($nilai, $html,
                $sebutan.' NEVIRA sampai ke browser');
        }

        // Bentuk kuncinya sendiri, bukan cuma nilainya: `staff:` di dalam
        // sebuah atribut form berarti identitas internal dipakai apa adanya.
        $this->assertStringNotContainsString('value="staff:', $html);
        $this->assertStringNotContainsString('peran[staff:', $html);
    }

    /**
     * Yang boleh tampil tetap tampil. Perbaikan yang menyembunyikan nama dan
     * NIP karyawan sekalian bukan perbaikan — petugas memilih pelaku dari
     * daftar itu.
     */
    public function test_nama_dan_nip_karyawan_tetap_tampil(): void
    {
        $complaint = $this->complaintBersnapshot();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)->assertOk()
            ->assertSee('Gilang Ramadhan')
            ->assertSee('Budi Santoso')
            ->assertSee('LW/02');
    }

    /**
     * Kunci buram tidak ada gunanya kalau penetapan pelakunya jadi rusak.
     * Kunci diambil dari halaman yang sungguhan dirender, lalu dikirim balik
     * persis seperti yang dilakukan peramban.
     */
    public function test_penetapan_pelaku_tetap_bekerja_dengan_kunci_buram(): void
    {
        $complaint = $this->complaintBersnapshot();
        $supervisor = $this->userAs('supervisor');

        $html = $this->actingAs($supervisor)
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="pelaku\[\]" value="([0-9a-f]{32})"/', $html,
            'kunci kandidat harus heksa HMAC, bukan identitas yang bisa dibaca');

        preg_match_all('/name="pelaku\[\]" value="([0-9a-f]{32})"/', $html, $m);
        $kunci = $m[1][0];

        $this->actingAs($supervisor)->post('/complaints/'.$complaint->id.'/pelaku', [
            'pelaku' => [$kunci],
            'peran' => [$kunci => 'kasir'],
            'alasan' => 'Nota salah input sejak awal.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $complaint->responsibles()->count());

        // Id NEVIRA-nya tetap tersimpan di server — yang diganti bentuk
        // kunci yang dirender, bukan data yang dicatat.
        $this->assertNotNull($complaint->responsibles()->first()->staff_name);
    }

    /**
     * Kunci yang tidak dikenali tetap ditolak sebagai kesalahan form, bukan
     * dilewati diam-diam: pelaku yang dicentang lalu tidak tersimpan adalah
     * kehilangan data.
     */
    public function test_kunci_yang_tidak_dikenali_tetap_ditolak(): void
    {
        $complaint = $this->complaintBersnapshot();

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/pelaku', [
                'pelaku' => [str_repeat('a', 32)],
                'alasan' => 'Percobaan kunci karangan.',
            ])->assertSessionHasErrors('pelaku');

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    /**
     * Identitas lama yang ditebak orang juga tidak boleh lolos — kalau
     * `staff:<id>` masih diterima, kunci buramnya cuma hiasan.
     */
    public function test_identitas_mentah_tidak_lagi_diterima_sebagai_kunci(): void
    {
        $complaint = $this->complaintBersnapshot();

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/pelaku', [
                'pelaku' => ['staff:'.self::ID_KASIR],
                'alasan' => 'Percobaan identitas mentah.',
            ])->assertSessionHasErrors('pelaku');

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    public function test_papan_kerja_tidak_bisa_dicari_pakai_id_internal(): void
    {
        $complaint = $this->complaintTertaut();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints?q='.self::ID_INTERNAL)
            ->assertDontSee($complaint->ticket_number);
    }

    public function test_papan_kerja_masih_bisa_dicari_pakai_nomor_nota(): void
    {
        $complaint = $this->complaintTertaut();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints?q='.urlencode(self::NOTA))
            ->assertSee($complaint->ticket_number);
    }
}

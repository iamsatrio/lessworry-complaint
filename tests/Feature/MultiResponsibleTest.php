<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API-19 — satu complaint bisa punya beberapa pelaku, dipilih dari daftar
 * karyawan outlet.
 *
 * Yang dijaga di sini bukan hanya "bisa lebih dari satu": kolom Pelaku di
 * spreadsheet lama terisi 2 dari 90 baris. Kalau memilihnya masih perlu
 * mengetik nama dan NIP, hasilnya akan sama kosongnya. Karena itu sebagian
 * besar test di bawah menguji bahwa memilih SATU nama dari daftar sudah cukup —
 * identitas karyawannya diambil server dari daftarnya sendiri.
 */
class MultiResponsibleTest extends TestCase
{
    use RefreshDatabase;

    private const KARYAWAN_OUTLET = 'Siti Nur Aisyah';

    private const NIP_KARYAWAN_OUTLET = 'LW/06-0311';

    private const ID_KARYAWAN_OUTLET = 8801;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/user/by-outlet/*' => Http::response(['data' => [
                [
                    'id_user'  => self::ID_KARYAWAN_OUTLET,
                    'username' => self::KARYAWAN_OUTLET,
                    'nip'      => self::NIP_KARYAWAN_OUTLET,
                    'id_role'  => 4,
                    'status'   => 1,
                ],
                [
                    'id_user'  => 8802,
                    'username' => 'Karyawan Nonaktif',
                    'nip'      => 'LW/06-0312',
                    'id_role'  => 4,
                    'status'   => 0,
                ],
            ]], 200),
        ]);
    }

    private function userAs(string $role, ?Outlet $outlet = null, ?string $name = null): User
    {
        return User::create([
            'name' => $name ?? ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function outlet(): Outlet
    {
        return Outlet::firstOrCreate(['name' => 'Tebet'], ['nevira_outlet_id' => '118']);
    }

    /** Complaint dengan jejak order lengkap: kasir, dua tahap produksi, satu kurir. */
    private function complaint(array $attrs = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Kemeja masih bernoda dan telat diantar.',
            'nevira_transaction_number' => 'INV/118/1787749345365/1',
            'outlet_id' => $this->outlet()->id,
        ], $attrs));
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        $complaint->forceFill(['nevira_snapshot' => [
            'invoice'      => 'INV/118/1787749345365/1',
            'outlet_id'    => 118,
            'cashier_name' => 'Gilang Ramadhan',
            'cashier_nip'  => 'LW/06-0002',
            'cashier_id'   => 535,
            'processes'    => [
                ['stage' => 'Cuci', 'staff_name' => 'Budi Santoso', 'staff_nip' => 'LW/02', 'staff_id' => 244, 'status' => 'COMPLETED', 'duration' => 600],
            ],
            'deliveries'   => [[
                'id' => 1, 'date' => '2026-08-20', 'status_code' => 2, 'status' => 'Diantar',
                'courier_name' => 'Rizky Kurir', 'courier_nip' => 'LW/07-0010', 'courier_id' => 196,
                'queue_no' => 3, 'proof_count' => 0,
            ]],
        ]])->save();

        return $complaint;
    }

    private function tetapkan(User $actor, Complaint $complaint, array $payload)
    {
        return $this->actingAs($actor)->post('/complaints/'.$complaint->id.'/pelaku', $payload);
    }

    /* ---------- Beberapa pelaku dalam satu complaint ---------- */

    public function test_satu_complaint_bisa_punya_beberapa_pelaku_sekaligus(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, [
            'pelaku' => ['staff:535', 'staff:244', 'staff:196'],
            'alasan' => 'Noda tidak dicek saat serah terima, dan pengantaran telat sehari.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pelaku = $complaint->responsibles()->orderBy('id')->get();

        $this->assertCount(3, $pelaku, 'tiga pelaku yang dipilih tidak semuanya tersimpan');
        $this->assertSame(
            ['Gilang Ramadhan', 'Budi Santoso', 'Rizky Kurir'],
            $pelaku->pluck('staff_name')->all()
        );
        $this->assertSame(['kasir', 'produksi', 'kurir'], $pelaku->pluck('role')->all());
    }

    public function test_memilih_dari_daftar_tidak_perlu_mengetik_nama_dan_nip(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        // Hanya kunci kandidat dan alasan. Identitas karyawannya diambil
        // server dari daftarnya sendiri — itu inti dari mengurangi klik.
        $this->tetapkan($cc, $complaint, [
            'pelaku' => ['staff:244'],
            'alasan' => 'Noda kerah masih ada setelah tahap cuci.',
        ])->assertSessionHasNoErrors();

        $pelaku = $complaint->responsibles()->sole();

        $this->assertSame('Budi Santoso', $pelaku->staff_name);
        $this->assertSame('LW/02', $pelaku->staff_nip);
        $this->assertSame('244', (string) $pelaku->nevira_user_id);
        $this->assertSame('produksi', $pelaku->role);
        $this->assertSame('Cuci', $pelaku->stage);
        $this->assertSame($cc->id, $pelaku->set_by);
        $this->assertNotNull($pelaku->set_at);
    }

    public function test_karyawan_outlet_dari_nevira_bisa_dipilih_sebagai_pelaku(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, [
            'pelaku' => ['staff:'.self::ID_KARYAWAN_OUTLET],
            'peran'  => ['staff:'.self::ID_KARYAWAN_OUTLET => 'produksi'],
            'alasan' => 'Mengerjakan ulang cucian ini di luar catatan NEVIRA.',
        ])->assertSessionHasNoErrors();

        $pelaku = $complaint->responsibles()->sole();

        $this->assertSame(self::KARYAWAN_OUTLET, $pelaku->staff_name);
        $this->assertSame(self::NIP_KARYAWAN_OUTLET, $pelaku->staff_nip);
        $this->assertSame('produksi', $pelaku->role);
    }

    public function test_daftar_karyawan_outlet_muncul_di_halaman_complaint(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->actingAs($cc)->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee(self::KARYAWAN_OUTLET)
            ->assertSee('Rizky Kurir')
            ->assertDontSee('Karyawan Nonaktif');
    }

    public function test_orang_di_luar_daftar_tetap_bisa_diisi_manual(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, [
            'manual_nama'  => 'Kurir Outlet Lain',
            'manual_nip'   => 'LW/09-0001',
            'manual_peran' => 'kurir',
            'alasan'       => 'Mengantar dari outlet lain, tidak tercatat di nota ini.',
        ])->assertSessionHasNoErrors();

        $pelaku = $complaint->responsibles()->sole();

        $this->assertSame('Kurir Outlet Lain', $pelaku->staff_name);
        $this->assertSame('kurir', $pelaku->role);
        $this->assertNull($pelaku->nevira_user_id);
    }

    public function test_orang_yang_sama_tidak_tersimpan_dua_kali(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda lagi.']);

        $this->assertSame(1, $complaint->responsibles()->count(),
            'orang yang sama tercatat dua kali sebagai pelaku di complaint yang sama');
    }

    public function test_complaint_tanpa_pelaku_tetap_wajar(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->assertFalse($complaint->hasResponsibility());
        $this->actingAs($cc)->get('/complaints/'.$complaint->id)->assertOk();
    }

    /* ---------- Alasan wajib ---------- */

    public function test_pelaku_tanpa_alasan_ditolak(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244']])
            ->assertSessionHasErrors('alasan');

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    public function test_pelaku_manual_tanpa_alasan_ditolak(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, ['manual_nama' => 'Seseorang'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    public function test_alasan_tanpa_siapa_pun_yang_dipilih_ditolak(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, ['alasan' => 'Ada yang salah.'])
            ->assertSessionHasErrors('pelaku');

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    public function test_mengubah_alasan_pelaku_tetap_menuntut_alasan(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Awal.']);
        $pelaku = $complaint->responsibles()->sole();

        $this->actingAs($cc)
            ->put('/complaints/'.$complaint->id.'/pelaku/'.$pelaku->id, ['peran' => 'produksi'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame('Awal.', $pelaku->fresh()->reason);
    }

    /* ---------- Riwayat ---------- */

    public function test_penambahan_pelaku_masuk_riwayat_beserta_alasan(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->tetapkan($cc, $complaint, [
            'pelaku' => ['staff:244'],
            'alasan' => 'Noda kerah masih ada setelah tahap cuci.',
        ]);

        $note = (string) $complaint->activities()->latest('id')->first()?->note;

        $this->assertStringContainsString('Budi Santoso', $note);
        $this->assertStringContainsString('Noda kerah masih ada setelah tahap cuci.', $note);
    }

    public function test_perubahan_pelaku_masuk_riwayat(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Awal.']);
        $pelaku = $complaint->responsibles()->sole();

        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/pelaku/'.$pelaku->id, [
            'peran'  => 'kurir',
            'alasan' => 'Ternyata rusaknya saat diantar, bukan saat dicuci.',
        ])->assertSessionHasNoErrors();

        $note = (string) $complaint->activities()->latest('id')->first()?->note;

        $this->assertSame('kurir', $pelaku->fresh()->role);
        $this->assertStringContainsString('Budi Santoso', $note);
        $this->assertStringContainsString('Ternyata rusaknya saat diantar', $note);
    }

    public function test_pencabutan_pelaku_masuk_riwayat(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Awal.']);
        $pelaku = $complaint->responsibles()->sole();

        $this->actingAs($cc)
            ->delete('/complaints/'.$complaint->id.'/pelaku/'.$pelaku->id)
            ->assertRedirect();

        $this->assertSame(0, $complaint->responsibles()->count());
        $this->assertStringContainsString('dicabut', (string) $complaint->activities()->latest('id')->first()?->note);
    }

    /* ---------- Wewenang dan kerahasiaan data karyawan ---------- */

    public function test_kasir_tidak_bisa_menetapkan_pelaku(): void
    {
        $outlet = $this->outlet();
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->tetapkan($kasir, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'coba-coba'])
            ->assertForbidden();

        $this->assertSame(0, $complaint->responsibles()->count());
    }

    public function test_kasir_tidak_melihat_daftar_karyawan_outlet(): void
    {
        $outlet = $this->outlet();
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertDontSee(self::KARYAWAN_OUTLET)
            ->assertDontSee(self::NIP_KARYAWAN_OUTLET);
    }

    public function test_kasir_tidak_melihat_pelaku_yang_sudah_ditetapkan(): void
    {
        $outlet = $this->outlet();
        $kasir = $this->userAs('kasir', $outlet);
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);

        $this->actingAs($kasir)->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertDontSee('Budi Santoso');
    }

    public function test_kasir_tidak_bisa_mencabut_pelaku(): void
    {
        $outlet = $this->outlet();
        $kasir = $this->userAs('kasir', $outlet);
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);
        $pelaku = $complaint->responsibles()->sole();

        $this->actingAs($kasir)->delete('/complaints/'.$complaint->id.'/pelaku/'.$pelaku->id)
            ->assertForbidden();

        $this->assertSame(1, $complaint->responsibles()->count());
    }

    public function test_pelaku_complaint_lain_tidak_bisa_dicabut_lewat_complaint_ini(): void
    {
        $cc = $this->userAs('customer_care');
        $satu = $this->complaint();
        $dua  = $this->complaint();

        $this->tetapkan($cc, $satu, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);
        $pelaku = $satu->responsibles()->sole();

        $this->actingAs($cc)->delete('/complaints/'.$dua->id.'/pelaku/'.$pelaku->id)
            ->assertNotFound();

        $this->assertSame(1, $satu->responsibles()->count());
    }

    /* ---------- Laporan ---------- */

    public function test_rekap_menghitung_tiap_pelaku_bukan_satu_per_complaint(): void
    {
        $supervisor = $this->userAs('supervisor');
        $complaint = $this->complaint();

        $this->tetapkan($supervisor, $complaint, [
            'pelaku' => ['staff:244', 'staff:196'],
            'alasan' => 'Noda dan telat antar.',
        ]);

        $this->actingAs($supervisor)->get('/reports')
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Rizky Kurir');
    }

    public function test_ekspor_csv_memuat_semua_pelaku(): void
    {
        $supervisor = $this->userAs('supervisor');
        $complaint = $this->complaint();

        $this->tetapkan($supervisor, $complaint, [
            'pelaku' => ['staff:244', 'staff:196'],
            'alasan' => 'Noda dan telat antar.',
        ]);

        $csv = $this->actingAs($supervisor)->get('/reports/export')->streamedContent();

        $this->assertStringContainsString('Budi Santoso', $csv);
        $this->assertStringContainsString('Rizky Kurir', $csv);
    }

    public function test_rekap_pelaku_tidak_bocor_ke_kasir(): void
    {
        $outlet = $this->outlet();
        $cc = $this->userAs('customer_care');
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);

        $this->actingAs($kasir)->get('/reports')
            ->assertOk()
            ->assertDontSee('Budi Santoso');

        $csv = $this->actingAs($kasir)->get('/reports/export')->streamedContent();
        $this->assertStringNotContainsString('Budi Santoso', $csv);
    }

    /* ---------- Pelaku ikut terhapus bersama complaint-nya ---------- */

    public function test_pelaku_terhapus_bersama_complaint(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $this->tetapkan($cc, $complaint, ['pelaku' => ['staff:244'], 'alasan' => 'Noda.']);

        $complaint->delete();

        $this->assertSame(0, ComplaintResponsible::count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private function outlet(string $name = 'Outlet A'): Outlet
    {
        return Outlet::create(['name' => $name, 'nevira_outlet_id' => '1']);
    }

    private function userAs(string $role, ?Outlet $outlet = null, ?string $division = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $outlet?->id, 'division' => $division,
        ]);
    }

    private function makeComplaint(array $attrs = []): Complaint
    {
        $c = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelanggan', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Keluhan uji',
        ], $attrs));
        $c->status = $attrs['status'] ?? 'open';
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->created_at = now();
        $c->applySla();
        $c->save();

        return $c;
    }

    /* ---------- Autentikasi (API-14) ---------- */

    public function test_tamu_tidak_bisa_membuka_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_akun_nonaktif_ditolak_walau_password_benar(): void
    {
        $user = $this->userAs('supervisor');
        $user->update(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_pengguna_dinonaktifkan_saat_sesi_berjalan_langsung_dikeluarkan(): void
    {
        $user = $this->userAs('customer_care');
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_password_disimpan_sebagai_hash(): void
    {
        $user = $this->userAs('kasir');

        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(password_verify('secret123', $user->password));
    }

    /* ---------- Hak akses per peran (API-13) ---------- */

    public function test_kasir_hanya_melihat_complaint_outletnya(): void
    {
        $a = $this->outlet('Outlet A');
        $b = $this->outlet('Outlet B');
        $kasir = $this->userAs('kasir', $a);

        $this->makeComplaint(['outlet_id' => $a->id]);
        $lain = $this->makeComplaint(['outlet_id' => $b->id]);

        $this->actingAs($kasir)->get('/complaints')->assertOk();
        $this->actingAs($kasir)->get('/complaints/'.$lain->id)->assertForbidden();
    }

    public function test_complaint_kasir_dipaksa_memakai_outlet_kasir(): void
    {
        $a = $this->outlet('Outlet A');
        $b = $this->outlet('Outlet B');
        $kasir = $this->userAs('kasir', $a);

        $this->actingAs($kasir)->post('/complaints', [
            'channel' => 'kasir', 'reporter_name' => 'Budi', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Noda tidak hilang',
            'outlet_id' => $b->id, 'nota_exemption' => 'lebih_sebulan',
        ]);

        $this->assertSame($a->id, Complaint::latest('id')->first()->outlet_id);
    }

    public function test_divisi_hanya_melihat_complaint_yang_diteruskan_ke_divisinya(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');

        $milik = $this->makeComplaint(['forwarded_division' => 'produksi']);
        $bukan = $this->makeComplaint(['forwarded_division' => 'kurir']);

        $this->actingAs($divisi)->get('/complaints/'.$milik->id)->assertOk();
        $this->actingAs($divisi)->get('/complaints/'.$bukan->id)->assertForbidden();
    }

    public function test_kasir_tidak_boleh_menutup_complaint(): void
    {
        $a = $this->outlet();
        $kasir = $this->userAs('kasir', $a);
        $complaint = $this->makeComplaint(['outlet_id' => $a->id]);

        $this->actingAs($kasir)
            ->post('/complaints/'.$complaint->id.'/status', ['lock_version' => $complaint->fresh()->lock_version, 'status' => 'close', 'close_reason' => 'selesai'])
            ->assertSessionHasErrors('status');

        $this->assertSame('open', $complaint->fresh()->status);
    }

    public function test_kompensasi_di_atas_batas_wewenang_ditolak(): void
    {
        $a = $this->outlet();
        $kasir = $this->userAs('kasir', $a);
        $complaint = $this->makeComplaint(['outlet_id' => $a->id]);

        $this->actingAs($kasir)->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version, 'status' => 'handling', 'compensation_amount' => 500000,
        ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame(0, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_supervisor_bisa_menutup_dan_waktu_selesai_tercatat(): void
    {
        $supervisor = $this->userAs('supervisor');
        $complaint = $this->makeComplaint();

        $this->actingAs($supervisor)->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version, 'status' => 'close', 'close_reason' => 'selesai', 'resolution' => 'Dicuci ulang gratis',
        ]);

        $complaint->refresh();

        $this->assertSame('close', $complaint->status);
        $this->assertSame('selesai', $complaint->close_reason);
        $this->assertNotNull($complaint->resolved_at);
        $this->assertSame(1, $complaint->activities()->where('type', 'status_change')->count());
    }

    /* ---------- SLA (API-6, disetel ulang di API-25) ---------- */

    /**
     * Pengganti test lama yang mengunci SLA pada `priority`. Sumbunya
     * berganti jadi bobot dan satuannya jadi hari; yang diuji tetap sama —
     * tenggat mengikuti berat-ringannya keluhan.
     */
    public function test_tenggat_penyelesaian_mengikuti_bobot_dalam_hari(): void
    {
        $hari = [
            'ringan' => 2,
            'sedang' => 3,
            'berat'  => 5,
        ];

        foreach ($hari as $bobot => $target) {
            $complaint = $this->makeComplaint(['bobot' => $bobot, 'layanan' => 'kiloan']);

            $this->assertSame(
                $target * 24 * 60,
                (int) $complaint->created_at->diffInMinutes($complaint->due_resolution_at),
                "Tenggat penyelesaian complaint $bobot seharusnya $target hari."
            );
        }
    }

    /** Janji publik 1x24 jam berlaku sama untuk semua bobot. */
    public function test_tenggat_respon_pertama_selalu_24_jam(): void
    {
        foreach (['ringan', 'sedang', 'berat'] as $bobot) {
            $complaint = $this->makeComplaint(['bobot' => $bobot, 'layanan' => 'kiloan']);

            $this->assertSame(
                24 * 60,
                (int) $complaint->created_at->diffInMinutes($complaint->due_response_at)
            );
        }
    }

    public function test_complaint_terbuka_yang_lewat_tenggat_ditandai(): void
    {
        $complaint = $this->makeComplaint();
        $complaint->forceFill(['due_resolution_at' => now()->subHour()])->save();

        $this->assertTrue($complaint->isOverdue());

        $complaint->forceFill(['status' => 'close', 'close_reason' => 'selesai', 'resolved_at' => now()])->save();

        $this->assertFalse($complaint->fresh()->isOverdue());
    }

    /* ---------- Ketahanan integrasi NEVIRA (API-8, API-10) ---------- */

    public function test_complaint_tetap_tersimpan_walau_nevira_mati(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
        Http::fake(['*' => Http::response('', 500)]);

        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Sinta', 'category' => 'terlambat',
            'bobot' => 'berat', 'layanan' => 'kiloan', 'description' => 'Telat 2 hari',
            'nevira_transaction_number' => 'TRX-1',
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->assertNotNull($complaint);
        $this->assertSame('TRX-1', $complaint->nevira_transaction_number);
        $this->assertNotNull($complaint->nevira_sync_error);
    }

    public function test_ringkasan_order_ditarik_saat_nevira_sehat(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok123'], 200),
            '*/transactions/31191' => Http::response(['data' => [
                'id_transaction' => 31191,
                'transaction_number' => 'INV/119/000123',
                'order_type' => 'REGULAR',
                'status' => 'PROCESSING',
                'payment_status' => 'PAID',
                'grand_total' => 75000,
                'id_outlet' => 119,
                'outlet_name' => 'Outlet Pusat',
                'id_customer' => 27171,
                'customer' => ['id_customer' => 27171, 'customer_name' => 'Ibu Rina', 'phone' => '081234567801'],
                'services' => [['quantity' => 2, 'status' => 'ORDER', 'notes' => 'Cuci kering']],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31191, 'transaction_number' => 'INV/119/000123'],
            ]], 200),
        ]);

        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Rina', 'category' => 'lainnya',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Tagihan tidak sesuai',
            'nevira_transaction_number' => 'INV/119/000123',
        ]);

        $snapshot = Complaint::latest('id')->first()->nevira_snapshot;

        $this->assertSame('INV/119/000123', $snapshot['invoice']);
        $this->assertSame('Ibu Rina', $snapshot['customer_name']);
        $this->assertSame('Outlet Pusat', $snapshot['outlet_name']);
        $this->assertSame(27171, $snapshot['customer_id']);
    }

    /**
     * NEVIRA menolak header "Bearer <token>" dengan 500, bukan 401. Token
     * harus dikirim mentah. Test ini mengunci perilaku itu supaya tidak
     * tanpa sengaja diganti ke withToken() di kemudian hari.
     */
    public function test_token_dikirim_tanpa_awalan_bearer(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok123'], 200),
            '*/transactions/*' => Http::response(['data' => ['id_transaction' => 1]], 200),
        ]);

        app(\App\Services\NeviraClient::class)->transaction('1');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transactions/')) {
                return true;
            }

            return $request->header('Authorization') === ['tok123'];
        });
    }

    public function test_memakai_endpoint_transaksi_yang_benar(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok123'], 200),
            '*' => Http::response(['data' => ['id_transaction' => 42]], 200),
        ]);

        app(\App\Services\NeviraClient::class)->transaction('42');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/transaction/detail/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/transactions/42'));
    }

    /* ---------- Nomor nota wajib, dengan pengecualian yang harus disebut ---------- */

    public function test_complaint_tanpa_nota_dan_tanpa_alasan_ditolak(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Deni', 'category' => 'lainnya',
            'bobot' => 'ringan', 'layanan' => 'kiloan', 'description' => 'Kasir kurang ramah',
        ])->assertSessionHasErrors('nevira_transaction_number');

        $this->assertSame(0, Complaint::count());
    }

    public function test_complaint_tanpa_nota_diterima_kalau_alasannya_disebut(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Deni', 'category' => 'terlambat',
            'sub_category' => 'Telat jemput', 'bobot' => 'berat', 'layanan' => 'kiloan',
            'description' => 'Kurir belum datang menjemput',
            'nota_exemption' => 'belum_terbit',
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertNull($complaint->nevira_transaction_number);
        $this->assertSame('belum_terbit', $complaint->nota_exemption);
        $this->assertSame(
            'Complaint keterlambatan penjemputan — nota belum terbit',
            $complaint->notaExemptionLabel()
        );
    }

    public function test_alasan_di_luar_daftar_ditolak(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Deni', 'category' => 'kurang_bersih',
            'bobot' => 'ringan', 'layanan' => 'kiloan', 'description' => 'x', 'nota_exemption' => 'malas-ngetik',
        ])->assertSessionHasErrors('nota_exemption');
    }

    public function test_nota_terisi_membatalkan_alasan_pengecualian(): void
    {
        config(['nevira.enabled' => false]);
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Deni', 'category' => 'kurang_bersih',
            'bobot' => 'ringan', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => '31197', 'nota_exemption' => 'lebih_sebulan',
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertSame('31197', $complaint->nevira_transaction_number);
        $this->assertNull($complaint->nota_exemption);
    }

    public function test_umur_transaksi_dihitung_dari_snapshot(): void
    {
        $baru = $this->makeComplaint();
        $baru->forceFill(['nevira_snapshot' => ['created_at' => now()->subDays(3)->toIso8601String()]])->save();

        $lama = $this->makeComplaint();
        $lama->forceFill(['nevira_snapshot' => ['created_at' => now()->subDays(45)->toIso8601String()]])->save();

        $this->assertFalse($baru->fresh()->transactionIsOld());
        $this->assertTrue($lama->fresh()->transactionIsOld());
        $this->assertSame(45, $lama->fresh()->transactionAgeDays());
    }

    /* ---------- Menautkan order setelah complaint tersimpan (API-8) ---------- */

    public function test_complaint_tanpa_order_bisa_ditautkan_menyusul(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok123'], 200),
            '*/transactions/31197' => Http::response(['data' => [
                'id_transaction' => 31197, 'transaction_number' => 'INV/179/1',
                'outlet_name' => 'Citra Garden Serpong', 'id_customer' => 81290,
                'customer' => ['id_customer' => 81290, 'customer_name' => 'Ibu Tuti'],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31197, 'transaction_number' => 'INV/179/1'],
            ]], 200),
        ]);

        $cc = $this->userAs('customer_care');
        $complaint = $this->makeComplaint();

        $this->assertNull($complaint->nevira_transaction_number);

        $this->actingAs($cc)
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => 'INV/179/1'])
            ->assertRedirect();

        $complaint->refresh();

        // Yang tersimpan dan ditampilkan adalah nomor notanya.
        $this->assertSame('INV/179/1', $complaint->nevira_transaction_number);
        $this->assertSame('INV/179/1', $complaint->nevira_snapshot['invoice']);
        // Id internal NEVIRA tidak pernah ikut ke snapshot.
        $this->assertArrayNotHasKey('transaction_id', $complaint->nevira_snapshot);
        $this->assertNull($complaint->nevira_sync_error);
    }

    public function test_nomor_order_salah_ketik_bisa_dibetulkan(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok123'], 200),
            '*/transactions/222' => Http::response(['data' => [
                'id_transaction' => 222, 'transaction_number' => 'INV/BENAR',
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 222, 'transaction_number' => 'INV/BENAR'],
            ]], 200),
        ]);

        $cc = $this->userAs('customer_care');
        $complaint = $this->makeComplaint(['nevira_transaction_number' => '111']);
        $complaint->forceFill(['nevira_snapshot' => ['invoice' => 'INV/SALAH']])->save();

        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => 'INV/BENAR']);

        $complaint->refresh();

        $this->assertSame('INV/BENAR', $complaint->nevira_transaction_number);
        // Snapshot order lama tidak boleh tertinggal — itu data order orang lain.
        $this->assertSame('INV/BENAR', $complaint->nevira_snapshot['invoice']);
    }

    public function test_melepas_tautan_membuang_snapshot_order_lama(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->makeComplaint(['nevira_transaction_number' => '111']);
        $complaint->forceFill([
            'nevira_snapshot'    => ['invoice' => 'INV/LAMA'],
            'nevira_customer_id' => '999',
        ])->save();

        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => '']);

        $complaint->refresh();

        $this->assertNull($complaint->nevira_transaction_number);
        $this->assertNull($complaint->nevira_snapshot);
        $this->assertNull($complaint->nevira_customer_id);
    }

    public function test_perubahan_tautan_tercatat_di_riwayat(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->makeComplaint();

        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => '31197']);

        $note = $complaint->activities()->latest('id')->first()->note;

        $this->assertStringContainsString('31197', $note);
    }

    public function test_kasir_tidak_bisa_menautkan_complaint_outlet_lain(): void
    {
        $a = $this->outlet('Outlet A');
        $b = $this->outlet('Outlet B');
        $kasir = $this->userAs('kasir', $a);

        $lain = $this->makeComplaint(['outlet_id' => $b->id]);

        $this->actingAs($kasir)
            ->put('/complaints/'.$lain->id.'/link', ['nevira_transaction_number' => '31197'])
            ->assertForbidden();

        $this->assertNull($lain->fresh()->nevira_transaction_number);
    }

    public function test_nomor_tiket_unik_dan_berurutan(): void
    {
        $a = $this->makeComplaint();
        $b = $this->makeComplaint();

        $this->assertNotSame($a->ticket_number, $b->ticket_number);
        $this->assertStringStartsWith('LW-'.now()->format('Ymd'), $b->ticket_number);
    }
}

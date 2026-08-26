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
            'channel' => 'wa_cc', 'reporter_name' => 'Pelanggan', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'status' => 'baru', 'description' => 'Keluhan uji',
        ], $attrs));
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
            'channel' => 'kasir', 'reporter_name' => 'Budi', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'Noda tidak hilang',
            'outlet_id' => $b->id,
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
            ->post('/complaints/'.$complaint->id.'/status', ['status' => 'selesai'])
            ->assertSessionHasErrors('status');

        $this->assertSame('baru', $complaint->fresh()->status);
    }

    public function test_kompensasi_di_atas_batas_wewenang_ditolak(): void
    {
        $a = $this->outlet();
        $kasir = $this->userAs('kasir', $a);
        $complaint = $this->makeComplaint(['outlet_id' => $a->id]);

        $this->actingAs($kasir)->post('/complaints/'.$complaint->id.'/status', [
            'status' => 'ditangani', 'compensation_amount' => 500000,
        ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame(0, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_supervisor_bisa_menutup_dan_waktu_selesai_tercatat(): void
    {
        $supervisor = $this->userAs('supervisor');
        $complaint = $this->makeComplaint();

        $this->actingAs($supervisor)->post('/complaints/'.$complaint->id.'/status', [
            'status' => 'selesai', 'resolution' => 'Dicuci ulang gratis',
        ]);

        $complaint->refresh();

        $this->assertSame('selesai', $complaint->status);
        $this->assertNotNull($complaint->resolved_at);
        $this->assertSame(1, $complaint->activities()->where('type', 'status_change')->count());
    }

    /* ---------- SLA (API-6) ---------- */

    public function test_tenggat_sla_mengikuti_prioritas(): void
    {
        $urgent = $this->makeComplaint(['priority' => 'urgent']);

        $this->assertSame(30, (int) $urgent->created_at->diffInMinutes($urgent->due_response_at));
        $this->assertSame(240, (int) $urgent->created_at->diffInMinutes($urgent->due_resolution_at));
    }

    public function test_complaint_terbuka_yang_lewat_tenggat_ditandai(): void
    {
        $complaint = $this->makeComplaint();
        $complaint->forceFill(['due_resolution_at' => now()->subHour()])->save();

        $this->assertTrue($complaint->isOverdue());

        $complaint->forceFill(['status' => 'selesai', 'resolved_at' => now()])->save();

        $this->assertFalse($complaint->fresh()->isOverdue());
    }

    /* ---------- Ketahanan integrasi NEVIRA (API-8, API-10) ---------- */

    public function test_complaint_tetap_tersimpan_walau_nevira_mati(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
        Http::fake(['*' => Http::response('', 500)]);

        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Sinta', 'category' => 'keterlambatan',
            'priority' => 'high', 'description' => 'Telat 2 hari',
            'nevira_transaction_id' => 'TRX-1',
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->assertNotNull($complaint);
        $this->assertSame('TRX-1', $complaint->nevira_transaction_id);
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
        ]);

        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Rina', 'category' => 'salah_tagih',
            'priority' => 'medium', 'description' => 'Tagihan tidak sesuai',
            'nevira_transaction_id' => '31191',
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

    public function test_complaint_tanpa_tautan_order_tetap_diterima(): void
    {
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Deni', 'category' => 'sikap_petugas',
            'priority' => 'low', 'description' => 'Kasir kurang ramah',
        ])->assertRedirect();

        $this->assertNull(Complaint::latest('id')->first()->nevira_transaction_id);
    }

    public function test_nomor_tiket_unik_dan_berurutan(): void
    {
        $a = $this->makeComplaint();
        $b = $this->makeComplaint();

        $this->assertNotSame($a->ticket_number, $b->ticket_number);
        $this->assertStringStartsWith('LW-'.now()->format('Ymd'), $b->ticket_number);
    }
}

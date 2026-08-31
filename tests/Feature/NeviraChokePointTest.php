<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * API-8 T1 — pengaman NEVIRA harus berlaku di SETIAP rute yang menyentuhnya,
 * bukan hanya di /nevira/lookup.
 *
 * Setiap test di sini menembak seluruh rute yang bisa memanggil NEVIRA:
 *
 *   GET  /nevira/lookup
 *   POST /complaints
 *   PUT  /complaints/{id}/link
 *   POST /complaints/{id}/resync
 *
 * Kalau suatu hari ada rute baru yang menyentuh NEVIRA, test arsitektur di
 * bawah yang menangkapnya: controller tidak boleh memegang NeviraClient.
 */
class NeviraChokePointTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA_ASING = 'INV/999/1787749345365/1';

    /** Outlet pemilik nota di NEVIRA — bukan outlet kasir yang diuji. */
    private const OUTLET_NEVIRA_ASING = 999;

    private const NAMA_PELANGGAN = 'Bu Rahasia';

    private const TELEPON_PELANGGAN = '081298765432';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/777001' => Http::response(['data' => [
                'id_transaction' => 777001,
                'transaction_number' => self::NOTA_ASING,
                'id_outlet' => self::OUTLET_NEVIRA_ASING,
                'outlet_name' => 'Outlet Lain',
                'grand_total' => 78000,
                'customer' => [
                    'id_customer' => 900,
                    'customer_name' => self::NAMA_PELANGGAN,
                    'phone' => self::TELEPON_PELANGGAN,
                ],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 777001, 'transaction_number' => self::NOTA_ASING],
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => []], 200),
        ]);
    }

    private function outlet(string $name, ?string $neviraId): Outlet
    {
        return Outlet::create(['name' => $name, 'nevira_outlet_id' => $neviraId]);
    }

    private function userAs(string $role, ?Outlet $outlet = null, ?string $division = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $outlet?->id, 'division' => $division,
        ]);
    }

    private function complaint(array $attrs = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ], $attrs));
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function assertTidakAdaDataPelanggan(Complaint $complaint, string $where): void
    {
        $complaint = $complaint->fresh();
        $blob = json_encode([$complaint->nevira_snapshot, $complaint->reporter_name, $complaint->reporter_phone]);

        $this->assertStringNotContainsString(self::NAMA_PELANGGAN, (string) $blob, $where.': nama pelanggan bocor ke complaint');
        $this->assertStringNotContainsString(self::TELEPON_PELANGGAN, (string) $blob, $where.': telepon pelanggan bocor ke complaint');
        $this->assertNull($complaint->nevira_transaction_id, $where.': id internal tersimpan padahal aksesnya ditolak');
    }

    /* ---------- Lingkup outlet berlaku di semua pintu ---------- */

    public function test_kasir_tidak_bisa_menarik_nota_outlet_lain_lewat_lookup(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet('Tebet', '118'));

        $response = $this->actingAs($kasir)->getJson('/nevira/lookup?id='.self::NOTA_ASING);

        $response->assertOk()->assertJson(['ok' => false]);
        $response->assertDontSee(self::NAMA_PELANGGAN);
        $response->assertDontSee(self::TELEPON_PELANGGAN);
    }

    public function test_kasir_tidak_bisa_menarik_nota_outlet_lain_lewat_form_intake(): void
    {
        $outlet = $this->outlet('Tebet', '118');
        $kasir = $this->userAs('kasir', $outlet);

        $this->actingAs($kasir)->post('/complaints', [
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA_ASING,
        ])->assertRedirect();

        $this->assertTidakAdaDataPelanggan(Complaint::latest('id')->first(), 'POST /complaints');
    }

    public function test_kasir_tidak_bisa_menarik_nota_outlet_lain_lewat_link(): void
    {
        $outlet = $this->outlet('Tebet', '118');
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => self::NOTA_ASING])
            ->assertRedirect();

        $this->assertTidakAdaDataPelanggan($complaint, 'PUT /complaints/{id}/link');
    }

    public function test_kasir_tidak_bisa_menarik_nota_outlet_lain_lewat_resync(): void
    {
        $outlet = $this->outlet('Tebet', '118');
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint([
            'outlet_id' => $outlet->id,
            'nevira_transaction_number' => self::NOTA_ASING,
        ]);

        $this->actingAs($kasir)->post('/complaints/'.$complaint->id.'/resync')->assertRedirect();

        $this->assertTidakAdaDataPelanggan($complaint, 'POST /complaints/{id}/resync');
    }

    public function test_halaman_complaint_tidak_menampilkan_pelanggan_outlet_lain(): void
    {
        $outlet = $this->outlet('Tebet', '118');
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => self::NOTA_ASING]);

        $response = $this->actingAs($kasir)->get('/complaints/'.$complaint->id);
        $response->assertDontSee(self::NAMA_PELANGGAN);
        $response->assertDontSee(self::TELEPON_PELANGGAN);
    }

    /* ---------- Divisi ditolak di semua pintu ---------- */

    public function test_divisi_ditolak_di_lookup(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');

        $this->actingAs($divisi)->getJson('/nevira/lookup?id='.self::NOTA_ASING)->assertForbidden();
    }

    public function test_divisi_ditolak_saat_menautkan_complaint_yang_diteruskan_padanya(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $complaint = $this->complaint(['forwarded_division' => 'produksi']);

        $this->actingAs($divisi)
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => self::NOTA_ASING])
            ->assertForbidden();

        $this->assertTidakAdaDataPelanggan($complaint, 'divisi PUT /link');
    }

    public function test_divisi_ditolak_saat_menarik_ulang(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $complaint = $this->complaint([
            'forwarded_division' => 'produksi',
            'nevira_transaction_number' => self::NOTA_ASING,
        ]);

        $this->actingAs($divisi)->post('/complaints/'.$complaint->id.'/resync')->assertForbidden();

        $this->assertTidakAdaDataPelanggan($complaint, 'divisi POST /resync');
    }

    /* ---------- Batas laju dihitung lintas rute, bukan per rute ---------- */

    public function test_batas_laju_berlaku_di_rute_penautan_bukan_hanya_lookup(): void
    {
        $cc = $this->userAs('customer_care');

        $berhasil = 0;

        for ($i = 0; $i < 40; $i++) {
            $complaint = $this->complaint([]);
            $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', [
                'nevira_transaction_number' => self::NOTA_ASING,
            ]);

            if ($complaint->fresh()->nevira_snapshot !== null) {
                $berhasil++;
            }
        }

        $this->assertSame(
            NeviraGateLimit::PER_MINUTE,
            $berhasil,
            'PUT /link tidak dibatasi laju: '.$berhasil.' dari 40 percobaan berhasil menarik data pelanggan'
        );
    }

    public function test_batas_laju_dipakai_bersama_antara_lookup_dan_penautan(): void
    {
        $cc = $this->userAs('customer_care');

        for ($i = 0; $i < NeviraGateLimit::PER_MINUTE; $i++) {
            $this->actingAs($cc)->getJson('/nevira/lookup?id='.self::NOTA_ASING);
        }

        $complaint = $this->complaint([]);
        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', [
            'nevira_transaction_number' => self::NOTA_ASING,
        ]);

        $this->assertNull(
            $complaint->fresh()->nevira_snapshot,
            'jatah lookup habis tapi penautan masih bisa menarik data — batasnya per rute, bukan per pengguna'
        );
    }

    public function test_batas_laju_tidak_menghilangkan_complaint(): void
    {
        $cc = $this->userAs('customer_care');

        RateLimiter::clear('nevira:'.$cc->id);
        for ($i = 0; $i < NeviraGateLimit::PER_MINUTE; $i++) {
            RateLimiter::hit('nevira:'.$cc->id, 60);
        }

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA_ASING,
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertNotNull($complaint, 'complaint hilang saat batas laju terlampaui');
        $this->assertNull($complaint->nevira_snapshot);
        $this->assertNotNull($complaint->nevira_sync_error);
    }

    /* ---------- Pintunya tidak boleh pindah lagi ---------- */

    public function test_tidak_ada_controller_yang_memegang_nevira_client_langsung(): void
    {
        $pelanggar = [];

        foreach (glob(app_path('Http/Controllers/*.php')) as $file) {
            if (str_contains((string) file_get_contents($file), 'NeviraClient')) {
                $pelanggar[] = basename($file);
            }
        }

        $this->assertSame([], $pelanggar,
            'Controller ini memanggil NeviraClient tanpa lewat NeviraGate — pengamannya bisa dilewati: '
            .implode(', ', $pelanggar));
    }
}

/** Alias supaya batasnya tidak ditulis dua kali di test. */
class NeviraGateLimit
{
    public const PER_MINUTE = \App\Services\NeviraGate::PER_MINUTE;
}

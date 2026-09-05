<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Celah yang pernah terbuka, dikunci supaya tidak terbuka lagi.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    private function userAs(string $role, ?Outlet $outlet = null, ?string $division = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $outlet?->id, 'division' => $division,
        ]);
    }

    private function fakeNevira(int $outletId = 118): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/31242' => Http::response(['data' => [
                'id_transaction' => 31242, 'transaction_number' => self::NOTA,
                'id_outlet' => $outletId, 'outlet_name' => 'Tebet', 'grand_total' => 78000,
                'customer' => ['id_customer' => 9, 'customer_name' => 'Ibu Sari', 'phone' => '0812000'],
                'cashier' => ['username' => 'Kasir POS', 'nip' => 'LW/1'],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31242, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => []], 200),
        ]);
    }

    /* ---------- Id internal NEVIRA tidak boleh terekspos ---------- */

    public function test_halaman_complaint_tidak_pernah_menampilkan_id_internal(): void
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill([
            'nevira_transaction_id' => '31242',
            'nevira_snapshot' => ['invoice' => self::NOTA],
            'nevira_synced_at' => now(),
        ])->save();

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertStringContainsString(self::NOTA, $html);
        $this->assertStringNotContainsString('31242', $html);
    }

    public function test_id_internal_tidak_bisa_diisi_lewat_request(): void
    {
        $this->fakeNevira();

        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nota_exemption' => 'lebih_sebulan',
            'nevira_transaction_id' => '999999',
            'status' => 'close', 'close_reason' => 'selesai',
            'compensation_amount' => 999999,
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->assertNull($complaint->nevira_transaction_id);
        $this->assertSame('open', $complaint->status);
        $this->assertSame(0, (int) $complaint->compensation_amount);
    }

    /* ---------- Lookup bukan alat menyisir data NEVIRA ---------- */

    public function test_divisi_tidak_boleh_memakai_lookup(): void
    {
        $this->fakeNevira();

        $this->actingAs($this->userAs('divisi', null, 'produksi'))
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))
            ->assertForbidden();
    }

    public function test_pencarian_sebagian_ditolak(): void
    {
        $this->fakeNevira();

        // "INV" cocok sebagian dengan banyak nota. Dulu ini mengembalikan
        // order pelanggan mana pun.
        $this->actingAs($this->userAs('customer_care'))
            ->getJson('/nevira/lookup?id=INVXXX')
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_kasir_tidak_bisa_memeriksa_nota_outlet_lain(): void
    {
        $this->fakeNevira(outletId: 118);

        $outletLain = Outlet::create(['name' => 'Outlet Lain', 'nevira_outlet_id' => '999']);

        $this->actingAs($this->userAs('kasir', $outletLain))
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_kasir_boleh_memeriksa_nota_outletnya_sendiri(): void
    {
        $this->fakeNevira(outletId: 118);

        $outlet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_nama_kasir_pos_tidak_dikirim_ke_peran_yang_tidak_berhak(): void
    {
        $this->fakeNevira(outletId: 118);
        $outlet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $kasir = $this->actingAs($this->userAs('kasir', $outlet))
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))->json();

        $this->assertArrayNotHasKey('cashier_name', $kasir['data']);

        $cc = $this->actingAs($this->userAs('customer_care'))
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))->json();

        $this->assertSame('Kasir POS', $cc['data']['cashier_name']);
    }

    /* ---------- Foto bukti tidak boleh terbuka tanpa wewenang ---------- */

    public function test_foto_bukti_butuh_login_dan_wewenang(): void
    {
        Storage::fake('local');

        $outletA = Outlet::create(['name' => 'A']);
        $outletB = Outlet::create(['name' => 'B']);
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x', 'outlet_id' => $outletA->id,
            'nota_exemption' => 'lebih_sebulan',
            'attachments' => [UploadedFile::fake()->image('bukti.jpg')],
        ]);

        $complaint = Complaint::latest('id')->first();
        $lampiran = $complaint->attachments()->first();

        $this->assertNotNull($lampiran, 'Lampiran gagal tersimpan.');

        // Berkasnya berada di disk privat, bukan public.
        Storage::disk('local')->assertExists($lampiran->path);
        $this->assertStringNotContainsString('public', $lampiran->path);

        $url = '/complaints/'.$complaint->id.'/lampiran/'.$lampiran->id;

        // Tamu ditolak.
        auth()->logout();
        $this->get($url)->assertRedirect('/login');

        // Kasir outlet lain ditolak.
        $this->actingAs($this->userAs('kasir', $outletB))->get($url)->assertForbidden();

        // Yang berwenang boleh.
        $this->actingAs($cc)->get($url)->assertOk();
    }

    public function test_lampiran_tidak_bisa_diambil_lewat_complaint_lain(): void
    {
        Storage::fake('local');
        $cc = $this->userAs('customer_care');

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x', 'nota_exemption' => 'lebih_sebulan',
            'attachments' => [UploadedFile::fake()->image('bukti.jpg')],
        ]);

        $berlampiran = Complaint::latest('id')->first();
        $lampiran = $berlampiran->attachments()->first();

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Q', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'y', 'nota_exemption' => 'lebih_sebulan',
        ]);

        $lain = Complaint::latest('id')->first();

        $this->actingAs($cc)
            ->get('/complaints/'.$lain->id.'/lampiran/'.$lampiran->id)
            ->assertNotFound();
    }
}

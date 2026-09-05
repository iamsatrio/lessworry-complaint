<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use App\Services\NeviraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaResolveTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
    }

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            // Detail hanya menerima id numerik.
            '*/transactions/31242' => Http::response(['data' => [
                'id_transaction' => 31242, 'transaction_number' => self::NOTA,
                'outlet_name' => 'Tebet', 'grand_total' => 78000, 'status' => 'ORDER',
                'id_customer' => 900,
                'customer' => ['id_customer' => 900, 'customer_name' => 'Ibu Sari', 'phone' => '081200001111'],
            ]], 200),
            // Pencarian nomor nota.
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31242, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_nomor_nota_dicari_dulu_lalu_detailnya_diambil(): void
    {
        $this->fakeOk();

        $resolved = app(NeviraClient::class)->resolveTransaction(self::NOTA);

        $this->assertSame('31242', $resolved['id']);
        $this->assertSame(self::NOTA, $resolved['payload']['data']['transaction_number']);
    }

    public function test_id_numerik_langsung_ke_detail_tanpa_mencari(): void
    {
        $this->fakeOk();

        app(NeviraClient::class)->resolveTransaction('31242');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'keyword='));
    }

    public function test_nota_yang_tidak_ada_memberi_pesan_yang_jelas(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions?*' => Http::response(['data' => []], 200),
        ]);

        $this->expectExceptionMessage('tidak ditemukan di NEVIRA');

        app(NeviraClient::class)->resolveTransaction('INV/999/0/1');
    }

    public function test_nota_dipilih_yang_cocok_persis_saat_hasil_lebih_dari_satu(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 111, 'transaction_number' => 'INV/118/9999/2'],
                ['id_transaction' => 31242, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/transactions/31242' => Http::response(['data' => ['id_transaction' => 31242]], 200),
        ]);

        $this->assertSame('31242', app(NeviraClient::class)->resolveTransaction(self::NOTA)['id']);
    }

    /* ---------- Lewat form ---------- */

    public function test_complaint_dengan_nomor_nota_tersimpan_dan_tersinkron(): void
    {
        $this->fakeOk();

        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Noda belum hilang',
            'nevira_transaction_number' => self::NOTA,
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        // Yang tersimpan dan dipakai petugas adalah nomor nota.
        $this->assertSame(self::NOTA, $complaint->nevira_transaction_number);
        $this->assertSame(self::NOTA, $complaint->nevira_snapshot['invoice']);
        // Id internal disimpan terpisah, hanya untuk panggilan API.
        $this->assertSame('31242', $complaint->nevira_transaction_id);
        $this->assertNull($complaint->nevira_sync_error);
    }

    /**
     * Nama pelapor wajib diisi saat intake, jadi yang benar-benar bisa
     * terisi menyusul adalah nomor teleponnya — sering tidak sempat
     * ditanyakan saat antrean ramai.
     */
    public function test_telepon_pelapor_terisi_dari_order_saat_ditautkan_menyusul(): void
    {
        $this->fakeOk();

        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'status' => 'open', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        $this->assertNull($complaint->reporter_phone);

        $this->actingAs($this->cc())
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => self::NOTA]);

        $complaint->refresh();

        $this->assertSame('081200001111', $complaint->reporter_phone);
        $this->assertSame('Ibu Sari', $complaint->reporter_name);
    }

    public function test_data_pelapor_yang_sudah_diisi_petugas_tidak_ditimpa(): void
    {
        $this->fakeOk();

        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Adik pemilik order',
            'reporter_phone' => '089999999999', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Diantar adiknya',
            'nevira_transaction_number' => self::NOTA,
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->assertSame('Adik pemilik order', $complaint->reporter_name);
        $this->assertSame('089999999999', $complaint->reporter_phone);
    }

    public function test_endpoint_lookup_tidak_pernah_membocorkan_id_internal(): void
    {
        $this->fakeOk();

        $response = $this->actingAs($this->cc())
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('data.invoice', self::NOTA)
            ->assertJsonPath('data.customer_name', 'Ibu Sari');

        $isi = $response->json();

        $this->assertArrayNotHasKey('id', $isi);
        $this->assertArrayNotHasKey('transaction_id', $isi['data']);
        $this->assertStringNotContainsString('31242', $response->getContent());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutletFromOrderTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
    }

    private function fake(int $outletNevira = 118): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/31242' => Http::response(['data' => [
                'id_transaction' => 31242, 'transaction_number' => self::NOTA,
                'id_outlet' => $outletNevira, 'outlet_name' => 'Tebet',
                'customer' => ['id_customer' => 9, 'customer_name' => 'Ibu Sari', 'phone' => '0812000'],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31242, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => []], 200),
        ]);
    }

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    public function test_outlet_ditentukan_dari_nota_kalau_belum_dipilih(): void
    {
        $this->fake();
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
        ])->assertRedirect();

        $this->assertSame($tebet->id, Complaint::latest('id')->first()->outlet_id);
    }

    public function test_outlet_yang_sudah_dipilih_petugas_tidak_ditimpa(): void
    {
        $this->fake();
        Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);
        $lain = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);

        // Complaint bisa dilaporkan di outlet lain daripada tempat cuciannya.
        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x', 'outlet_id' => $lain->id,
            'nevira_transaction_number' => self::NOTA,
        ]);

        $this->assertSame($lain->id, Complaint::latest('id')->first()->outlet_id);
    }

    public function test_outlet_nevira_yang_belum_terdaftar_dibiarkan_kosong(): void
    {
        $this->fake(outletNevira: 999);

        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
        ]);

        // Lebih baik kosong daripada salah outlet.
        $this->assertNull(Complaint::latest('id')->first()->outlet_id);
    }

    public function test_lookup_mengembalikan_id_outlet_lokal_bukan_id_nevira(): void
    {
        $this->fake();
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $json = $this->actingAs($this->cc())
            ->getJson('/nevira/lookup?id='.urlencode(self::NOTA))
            ->assertOk()->json();

        $this->assertSame($tebet->id, $json['data']['outlet_id']);
        $this->assertNotSame(118, $json['data']['outlet_id']);
        $this->assertSame('Tebet', $json['data']['outlet_name']);
    }

    public function test_perintah_sinkron_outlet_memetakan_yang_belum_terpetakan(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/outlet*' => Http::response(['data' => [
                ['id_outlet' => 118, 'outlet_name' => 'Tebet'],
                ['id_outlet' => 115, 'outlet_name' => 'Kemang'],
            ]], 200),
        ]);

        $lama = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => null]);

        $this->artisan('nevira:sync-outlets')->assertSuccessful();

        $this->assertSame('118', $lama->fresh()->nevira_outlet_id);
        $this->assertNotNull(Outlet::where('nevira_outlet_id', '115')->first());
    }

    public function test_perintah_sinkron_tidak_menghapus_outlet_yang_hilang_dari_nevira(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/outlet*' => Http::response(['data' => [['id_outlet' => 118, 'outlet_name' => 'Tebet']]], 200),
        ]);

        $lama = Outlet::create(['name' => 'Outlet Tutup', 'nevira_outlet_id' => '900']);

        $this->artisan('nevira:sync-outlets')->assertSuccessful();

        // Complaint lama menunjuk ke sini; menghapusnya memutus riwayat.
        $this->assertNotNull($lama->fresh());
    }
}

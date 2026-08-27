<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API-8 T4 — aturan cocok-persis harus berlaku untuk masukan angka juga.
 *
 * Petugas memegang nota, bukan id internal. Masukan yang seluruhnya angka
 * ditembakkan langsung ke endpoint detail, melewati aturan cocok-persis —
 * artinya nomor bisa dicoba satu per satu, persis yang mau dicegah.
 */
class NeviraNumericInputTest extends TestCase
{
    use RefreshDatabase;

    private const NAMA_PELANGGAN = 'Bu Rahasia';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

    }

    /**
     * @param  bool  $pencarianKetemu  apakah nomor nota ada di hasil pencarian.
     *                                 false meniru penebak yang hanya pegang angka.
     */
    private function fakeNevira(bool $pencarianKetemu): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/777001' => Http::response(['data' => [
                'id_transaction' => 777001, 'transaction_number' => 'INV/118/1/1',
                'id_outlet' => 118, 'outlet_name' => 'Tebet',
                'customer' => ['customer_name' => self::NAMA_PELANGGAN, 'phone' => '081298765432'],
            ]], 200),
            '*/transactions?*' => Http::response(['data' => $pencarianKetemu ? [
                ['id_transaction' => 777001, 'transaction_number' => 'INV/118/1/1'],
            ] : []], 200),
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

    public function test_lookup_menolak_masukan_yang_seluruhnya_angka(): void
    {
        $this->fakeNevira(pencarianKetemu: false);

        $response = $this->actingAs($this->cc())->getJson('/nevira/lookup?id=777001');

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringNotContainsString(self::NAMA_PELANGGAN, $response->getContent(),
            'id internal ditebak lewat lookup dan data pelanggannya keluar');
    }

    public function test_penautan_menolak_masukan_yang_seluruhnya_angka(): void
    {
        $this->fakeNevira(pencarianKetemu: false);

        $cc = $this->cc();
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        $this->actingAs($cc)->put('/complaints/'.$complaint->id.'/link', [
            'nevira_transaction_number' => '777001',
        ])->assertRedirect();

        $this->assertNull($complaint->fresh()->nevira_snapshot,
            'id internal ditebak lewat PUT /link dan data pelanggannya tersimpan');
    }

    public function test_form_intake_menolak_masukan_yang_seluruhnya_angka(): void
    {
        $this->fakeNevira(pencarianKetemu: false);

        $this->actingAs($this->cc())->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
            'nevira_transaction_number' => '777001',
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertNull($complaint->nevira_snapshot);
        $this->assertNotNull($complaint->nevira_sync_error);
        $this->assertNotNull($complaint, 'complaint harus tetap tersimpan');
    }

    public function test_nomor_nota_yang_wajar_tetap_diterima(): void
    {
        $this->fakeNevira(pencarianKetemu: true);

        $this->actingAs($this->cc())->getJson('/nevira/lookup?id='.urlencode('INV/118/1/1'))
            ->assertOk()->assertJson(['ok' => true]);
    }
}

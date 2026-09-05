<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * API-8 T3 — jalur GAGAL tidak boleh menempelkan id internal NEVIRA ke layar.
 *
 * Test lama hanya menguji jalur sukses. Yang bocor justru jalur gagal, dan
 * itu jalur yang paling sering muncul saat NEVIRA rewel.
 */
class NeviraErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    private const ID_INTERNAL = '777001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        // Pencarian nota berhasil (dapat id internal), detailnya yang gagal —
        // NEVIRA 5xx, timeout, atau transaksinya sudah dihapus.
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/'.self::ID_INTERNAL => Http::response('gateway down', 503),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => self::ID_INTERNAL, 'transaction_number' => self::NOTA],
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

    public function test_kegagalan_sinkron_tidak_menyimpan_id_internal_di_pesan_error(): void
    {
        $cc = $this->cc();

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
        ])->assertRedirect();

        $complaint = Complaint::latest('id')->first();

        $this->assertNotNull($complaint->nevira_sync_error, 'kegagalan harus tetap tercatat');
        $this->assertStringNotContainsString(self::ID_INTERNAL, $complaint->nevira_sync_error,
            'id internal NEVIRA tersimpan di nevira_sync_error');
        $this->assertStringNotContainsString('/transactions/', $complaint->nevira_sync_error,
            'path endpoint NEVIRA ikut tersimpan');
    }

    public function test_halaman_complaint_tidak_menampilkan_id_internal_di_jalur_gagal(): void
    {
        $cc = $this->cc();

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->actingAs($cc)->get('/complaints/'.$complaint->id)
            ->assertDontSee(self::ID_INTERNAL)
            ->assertSee('Data order belum bisa ditarik');
    }

    public function test_lookup_tidak_mengembalikan_id_internal_di_jalur_gagal(): void
    {
        $response = $this->actingAs($this->cc())->getJson('/nevira/lookup?id='.self::NOTA);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringNotContainsString(self::ID_INTERNAL, $response->getContent());
        $this->assertStringNotContainsString('/transactions/', $response->getContent());
    }

    public function test_detail_teknis_tetap_tercatat_di_log_supaya_bisa_ditelusuri(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'NEVIRA')
                && ($context['status'] ?? null) === 503);

        $this->actingAs($this->cc())->getJson('/nevira/lookup?id='.self::NOTA);
    }
}

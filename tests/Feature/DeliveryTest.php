<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use App\Services\NeviraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeComplaint(array $attrs = []): Complaint
    {
        $c = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelanggan', 'category' => 'keterlambatan',
            'priority' => 'medium', 'description' => 'Keluhan uji',
        ], $attrs));
        $c->status = $attrs['status'] ?? 'baru';
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->created_at = now();
        $c->applySla();
        $c->save();

        return $c;
    }

    private function rows(): array
    {
        return [
            [
                'id_deliveries_transaction' => 11722, 'delivery_date' => '2026-05-03',
                'status' => 7, 'type' => 1, 'queue_no' => 113, 'distance' => 0.1,
                'id_user_courier' => 196, 'notes' => null, 'notes_courier' => 'Diterima satpam',
                'courier' => ['username' => 'Muhamad Adezta Fauzan', 'nip' => 'LW/09-0002'],
                'proof_images' => [['id' => 1]],
            ],
            [
                'id_deliveries_transaction' => 11700, 'delivery_date' => '2026-05-01',
                'status' => 6, 'type' => 1, 'cancel_type' => 'COURIER',
                'id_user_courier' => 196, 'courier' => ['username' => 'Zulfa Senja', 'nip' => 'LW/09-0003'],
            ],
        ];
    }

    public function test_kode_status_diterjemahkan_memakai_peta_nevira(): void
    {
        $summary = app(NeviraClient::class)->summarizeDeliveries($this->rows());

        // Diurutkan menurut tanggal jadwal, yang lebih awal di depan.
        $this->assertSame('2026-05-01', $summary[0]['date']);
        $this->assertSame('Batal', $summary[0]['status']);
        $this->assertSame('Dibatalkan kurir', $summary[0]['cancel_reason']);

        $this->assertSame('Selesai', $summary[1]['status']);
        $this->assertNull($summary[1]['cancel_reason']);
        $this->assertSame('Muhamad Adezta Fauzan', $summary[1]['courier_name']);
        $this->assertSame(1, $summary[1]['proof_count']);
        $this->assertSame('Diterima satpam', $summary[1]['courier_notes']);
    }

    public function test_kode_status_tidak_dikenal_ditampilkan_apa_adanya(): void
    {
        $summary = app(NeviraClient::class)->summarizeDeliveries([
            ['id_deliveries_transaction' => 1, 'status' => 99, 'delivery_date' => '2026-05-01'],
        ]);

        $this->assertSame('Kode 99', $summary[0]['status']);
    }

    public function test_perjalanan_kurir_ditarik_saat_sinkron(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/832' => Http::response(['data' => [
                'id_transaction' => 832, 'transaction_number' => 'INV/123/1',
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => $this->rows()], 200),
        ]);

        $cc = User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Tuti', 'category' => 'keterlambatan',
            'priority' => 'high', 'description' => 'Telat diantar',
            'nevira_transaction_number' => '832',
        ]);

        $deliveries = Complaint::latest('id')->first()->deliveries();

        $this->assertCount(2, $deliveries);
        $this->assertSame('Muhamad Adezta Fauzan', $deliveries[1]['courier_name']);
    }

    public function test_gagal_menarik_kurir_tidak_membatalkan_sinkron_order(): void
    {
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);

        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/832' => Http::response(['data' => [
                'id_transaction' => 832, 'transaction_number' => 'INV/123/1',
            ]], 200),
            '*/deliveries-transactions*' => Http::response('', 500),
        ]);

        $cc = User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);

        $this->actingAs($cc)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Tuti', 'category' => 'keterlambatan',
            'priority' => 'high', 'description' => 'Telat diantar',
            'nevira_transaction_number' => '832',
        ]);

        $complaint = Complaint::latest('id')->first();

        // Order tetap tersinkron, hanya bagian kurirnya yang kosong.
        $this->assertSame('INV/123/1', $complaint->nevira_snapshot['invoice']);
        $this->assertNull($complaint->nevira_sync_error);
        $this->assertSame([], $complaint->deliveries());
    }

    public function test_panel_kurir_tampil_di_halaman_complaint(): void
    {
        $cc = User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);

        $complaint = $this->makeComplaint(['nevira_transaction_number' => '832']);
        $complaint->forceFill([
            'nevira_snapshot' => ['deliveries' => app(NeviraClient::class)->summarizeDeliveries($this->rows())],
            'nevira_synced_at' => now(),
        ])->save();

        $this->actingAs($cc)->get('/complaints/'.$complaint->id)
            ->assertSee('Perjalanan kurir')
            ->assertSee('Muhamad Adezta Fauzan')
            ->assertSee('Dibatalkan kurir');
    }
}

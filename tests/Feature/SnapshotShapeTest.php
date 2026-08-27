<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman complaint tidak boleh pecah kalau bentuk snapshot bergeser.
 * (API-14 #11)
 *
 * Snapshot adalah data dari sistem lain yang tersimpan berbulan-bulan.
 * Bentuknya bisa berbeda antara baris lama dan baru, dan halaman yang
 * mengakses kuncinya tanpa penjagaan membalas HTTP 500.
 */
class SnapshotShapeTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): User
    {
        return User::create([
            'name' => 'SV', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);
    }

    private function complaintDenganSnapshot(array $snapshot): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'keterlambatan',
            'priority' => 'medium', 'description' => 'x',
            'nevira_transaction_number' => 'INV/118/1/1',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill(['nevira_snapshot' => $snapshot, 'nevira_synced_at' => now()])->save();

        return $complaint;
    }

    public function test_baris_kurir_yang_kunci_kuncinya_kurang_tidak_memecahkan_halaman(): void
    {
        // Baris versi lama: hanya punya sebagian kunci.
        $complaint = $this->complaintDenganSnapshot([
            'invoice'    => 'INV/118/1/1',
            'deliveries' => [['status' => 'Diantar', 'date' => '2026-05-03']],
        ]);

        $this->actingAs($this->supervisor())
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('Diantar');
    }

    public function test_baris_kurir_kosong_sama_sekali_tidak_memecahkan_halaman(): void
    {
        $complaint = $this->complaintDenganSnapshot([
            'invoice'    => 'INV/118/1/1',
            'deliveries' => [[]],
        ]);

        $this->actingAs($this->supervisor())
            ->get('/complaints/'.$complaint->id)
            ->assertOk();
    }

    public function test_deliveries_yang_bukan_daftar_tidak_memecahkan_halaman(): void
    {
        $complaint = $this->complaintDenganSnapshot([
            'invoice'    => 'INV/118/1/1',
            'deliveries' => 'bukan daftar',
        ]);

        $this->actingAs($this->supervisor())
            ->get('/complaints/'.$complaint->id)
            ->assertOk();
    }

    public function test_snapshot_tanpa_kunci_deliveries_tidak_memecahkan_halaman(): void
    {
        $complaint = $this->complaintDenganSnapshot(['invoice' => 'INV/118/1/1']);

        $this->actingAs($this->supervisor())
            ->get('/complaints/'.$complaint->id)
            ->assertOk();
    }

    public function test_baris_kurir_lengkap_tetap_tampil_utuh(): void
    {
        $complaint = $this->complaintDenganSnapshot([
            'invoice'    => 'INV/118/1/1',
            'deliveries' => [[
                'status' => 'Diantar', 'date' => '2026-05-03', 'courier_name' => 'Budi Kurir',
                'courier_nip' => 'NIP-1', 'queue_no' => 7, 'distance' => 1.2,
                'proof_count' => 2, 'notes' => 'Titip satpam', 'courier_notes' => 'Rumah kosong',
                'cancel_reason' => null,
            ]],
        ]);

        $this->actingAs($this->supervisor())
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('Budi Kurir')
            ->assertSee('Rumah kosong')
            ->assertSee('Titip satpam');
    }
}

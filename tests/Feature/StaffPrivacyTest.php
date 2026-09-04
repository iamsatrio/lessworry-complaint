<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data karyawan tidak untuk kasir. (API-14 #5 dan #6)
 *
 * Aturannya sudah ditegakkan untuk kasir POS dan penanggung jawab, tapi
 * bocor lewat panel kurir dan lewat dropdown penanggung jawab yang memuat
 * seluruh pegawai perusahaan. Setengah pagar bukan pagar.
 */
class StaffPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const KURIR = 'Budi Kurir';

    private const NIP_KURIR = 'NIP-0099';

    private function userAs(string $role, ?Outlet $outlet = null, ?string $name = null): User
    {
        return User::create([
            'name' => $name ?? ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaintDenganKurir(?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'terlambat',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => 'INV/118/1/1',
            'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->applySla();
        $complaint->save();

        $complaint->forceFill(['nevira_snapshot' => [
            'invoice' => 'INV/118/1/1',
            'deliveries' => [[
                'id' => 1, 'date' => '2026-05-03', 'status_code' => 2, 'status' => 'Diantar',
                'cancel_reason' => null,
                'courier_name' => self::KURIR, 'courier_nip' => self::NIP_KURIR, 'courier_id' => 196,
                'queue_no' => 113, 'distance' => 0.4, 'notes' => null, 'courier_notes' => 'Diterima satpam',
                'proof_count' => 1, 'updated_at' => null,
            ]],
        ]])->save();

        return $complaint;
    }

    /* ---------- #5 nama dan NIP kurir ---------- */

    public function test_kasir_tidak_melihat_nama_dan_nip_kurir(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat', 'nevira_outlet_id' => '118']);
        $complaint = $this->complaintDenganKurir($outlet);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->get('/complaints/'.$complaint->id)
            ->assertDontSee(self::KURIR)
            ->assertDontSee(self::NIP_KURIR);
    }

    public function test_kasir_tetap_melihat_perjalanan_kurirnya(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat', 'nevira_outlet_id' => '118']);
        $complaint = $this->complaintDenganKurir($outlet);

        // Statusnya memang perlu dilihat kasir — yang tidak boleh hanya
        // identitas orangnya.
        $this->actingAs($this->userAs('kasir', $outlet))
            ->get('/complaints/'.$complaint->id)
            ->assertSee('Diantar');
    }

    public function test_customer_care_tetap_melihat_nama_kurir(): void
    {
        $complaint = $this->complaintDenganKurir();

        $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/'.$complaint->id)
            ->assertSee(self::KURIR);
    }

    /* ---------- #6 daftar seluruh pegawai ---------- */

    public function test_kasir_tidak_menerima_daftar_pegawai_perusahaan(): void
    {
        $pusat = Outlet::create(['name' => 'Pusat', 'nevira_outlet_id' => '118']);
        $cabang = Outlet::create(['name' => 'Cabang', 'nevira_outlet_id' => '119']);

        $this->userAs('kasir', $cabang, 'Kasir Cabang Rahasia');
        $this->userAs('supervisor', null, 'Supervisor Rahasia');

        $complaint = $this->complaintDenganKurir($pusat);

        $this->actingAs($this->userAs('kasir', $pusat))
            ->get('/complaints/'.$complaint->id)
            ->assertDontSee('Kasir Cabang Rahasia')
            ->assertDontSee('Supervisor Rahasia');
    }

    public function test_divisi_tidak_menerima_daftar_pegawai_perusahaan(): void
    {
        $this->userAs('supervisor', null, 'Supervisor Rahasia');

        $complaint = $this->complaintDenganKurir();
        $complaint->forceFill(['forwarded_division' => 'produksi'])->save();

        $divisi = User::create([
            'name' => 'Divisi', 'email' => 'div'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'divisi', 'division' => 'produksi',
        ]);

        $this->actingAs($divisi)->get('/complaints/'.$complaint->id)
            ->assertDontSee('Supervisor Rahasia');
    }

    public function test_customer_care_tetap_bisa_memilih_penanggung_jawab(): void
    {
        $this->userAs('supervisor', null, 'Supervisor Terlihat');

        $complaint = $this->complaintDenganKurir();

        $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/'.$complaint->id)
            ->assertSee('Supervisor Terlihat');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menentukan siapa yang menangani complaint adalah wewenang, bukan
 * pencatatan. (API-14 #3, API-8 T11)
 *
 * Rute /responsibility sudah dijaga canAssignResponsibility(). Rute /assign
 * di sebelahnya — yang baris auditnya sendiri menulis "Penanggung jawab
 * diperbarui" — hanya memeriksa canView.
 */
class AssignAuthorityTest extends TestCase
{
    use RefreshDatabase;

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
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ], $attrs));
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_kasir_tidak_bisa_mengubah_penanggung_jawab(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $kasir = $this->userAs('kasir', $outlet);
        $lain  = $this->userAs('customer_care');
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)
            ->post('/complaints/'.$complaint->id.'/assign', ['assigned_to' => $lain->id])
            ->assertForbidden();

        $this->assertNull($complaint->fresh()->assigned_to);
    }

    public function test_kasir_tidak_bisa_meneruskan_ke_divisi(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)
            ->post('/complaints/'.$complaint->id.'/assign', ['forwarded_division' => 'produksi'])
            ->assertForbidden();

        $this->assertNull($complaint->fresh()->forwarded_division);
    }

    public function test_divisi_tidak_bisa_melempar_complaint_ke_divisi_lain(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $complaint = $this->complaint(['forwarded_division' => 'produksi']);

        $this->actingAs($divisi)
            ->post('/complaints/'.$complaint->id.'/assign', ['forwarded_division' => 'keuangan'])
            ->assertForbidden();

        $this->assertSame('produksi', $complaint->fresh()->forwarded_division);
    }

    public function test_divisi_tidak_bisa_membuat_complaint_lenyap_dari_semua_antrean(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $complaint = $this->complaint(['forwarded_division' => 'produksi']);

        $this->actingAs($divisi)
            ->post('/complaints/'.$complaint->id.'/assign', ['forwarded_division' => ''])
            ->assertForbidden();

        $this->assertSame('produksi', $complaint->fresh()->forwarded_division);
    }

    public function test_customer_care_tetap_bisa_menugaskan(): void
    {
        $cc = $this->userAs('customer_care');
        $petugas = $this->userAs('supervisor');
        $complaint = $this->complaint();

        $this->actingAs($cc)
            ->post('/complaints/'.$complaint->id.'/assign', ['assigned_to' => $petugas->id])
            ->assertRedirect();

        $this->assertSame($petugas->id, $complaint->fresh()->assigned_to);
    }

    public function test_customer_care_tetap_bisa_meneruskan_ke_divisi(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->actingAs($cc)
            ->post('/complaints/'.$complaint->id.'/assign', ['forwarded_division' => 'produksi'])
            ->assertRedirect();

        $this->assertSame('produksi', $complaint->fresh()->forwarded_division);
    }

    public function test_penanggung_jawab_tidak_bisa_diarahkan_ke_akun_nonaktif(): void
    {
        $cc = $this->userAs('customer_care');
        $mati = $this->userAs('supervisor');
        $mati->forceFill(['is_active' => false])->save();

        $complaint = $this->complaint();

        $this->actingAs($cc)
            ->post('/complaints/'.$complaint->id.'/assign', ['assigned_to' => $mati->id])
            ->assertSessionHasErrors('assigned_to');

        $this->assertNull($complaint->fresh()->assigned_to);
    }

    public function test_penanggung_jawab_tidak_bisa_diarahkan_ke_pengguna_divisi(): void
    {
        $cc = $this->userAs('customer_care');
        $divisi = $this->userAs('divisi', null, 'produksi');
        $complaint = $this->complaint();

        $this->actingAs($cc)
            ->post('/complaints/'.$complaint->id.'/assign', ['assigned_to' => $divisi->id])
            ->assertSessionHasErrors('assigned_to');

        $this->assertNull($complaint->fresh()->assigned_to);
    }

    public function test_kartu_penugasan_tidak_tampil_untuk_kasir(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $kasir = $this->userAs('kasir', $outlet);
        $complaint = $this->complaint(['outlet_id' => $outlet->id]);

        $this->actingAs($kasir)->get('/complaints/'.$complaint->id)
            ->assertDontSee('Simpan Penugasan');
    }
}

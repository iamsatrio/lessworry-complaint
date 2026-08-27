<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batas wewenang kompensasi berlaku dua arah. (API-14 #10)
 *
 * Batas atas sudah dijaga. Yang tidak: kasir bisa MENURUNKAN angka yang
 * sudah disetujui supervisor — 1.000.000 jadi 1 — dan tidak ada apa pun di
 * riwayat yang mencatat bahwa nilainya berubah.
 */
class CompensationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(int $kompensasi = 0, ?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x', 'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'ditangani';
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill(['compensation_amount' => $kompensasi])->save();

        return $complaint;
    }

    public function test_kasir_tidak_bisa_menurunkan_kompensasi_yang_disetujui_supervisor(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint(1_000_000, $outlet);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => 1,
            ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame(1_000_000, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_kasir_tetap_bisa_memperbarui_status_tanpa_menyentuh_kompensasi(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint(1_000_000, $outlet);

        // Form mengirim nilai yang sekarang apa adanya — itu bukan perubahan.
        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'menunggu_pelanggan', 'compensation_amount' => 1_000_000,
            ])->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('menunggu_pelanggan', $complaint->status);
        $this->assertSame(1_000_000, (int) $complaint->compensation_amount);
    }

    public function test_supervisor_bisa_menurunkan_kompensasi_yang_dia_setujui(): void
    {
        $complaint = $this->complaint(1_000_000);

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => 250_000,
            ])->assertSessionHasNoErrors();

        $this->assertSame(250_000, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_kompensasi_bisa_dinolkan_oleh_yang_berwenang(): void
    {
        $complaint = $this->complaint(40_000);

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => 0,
            ])->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_perubahan_kompensasi_tercatat_di_riwayat(): void
    {
        $complaint = $this->complaint(0);

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => 150_000,
            ]);

        $catatan = $complaint->activities()->pluck('note')->implode(' | ');

        $this->assertStringContainsString('150.000', $catatan,
            'nilai kompensasi berubah tanpa jejak siapa yang mengubahnya');
    }

    public function test_batas_atas_tetap_berlaku(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint(0, $outlet);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => 999_999,
            ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame(0, (int) $complaint->fresh()->compensation_amount);
    }

    public function test_nilai_negatif_ditolak(): void
    {
        $complaint = $this->complaint(0);

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditangani', 'compensation_amount' => -5000,
            ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame(0, (int) $complaint->fresh()->compensation_amount);
    }
}

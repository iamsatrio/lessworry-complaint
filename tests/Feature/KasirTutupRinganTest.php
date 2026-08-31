<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-25 nomor 5 — kasir boleh menutup complaint Ringan, dan hanya itu.
 *
 * Ini bagian yang paling mudah salah, karena dua batas berbeda bekerja pada
 * tombol yang sama: bobot menentukan SIAPA yang boleh menutup, kompensasi
 * menentukan SAMPAI ANGKA BERAPA. Complaint Ringan berkompensasi Rp 200.000
 * lolos satu batas dan harus tertahan di batas satunya.
 *
 * Semua pemeriksaan di sisi server. Menyembunyikan tombol bukan wewenang —
 * setiap test di bawah menembak rutenya langsung.
 */
class KasirTutupRinganTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $bobot, int $kompensasi = 0, ?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => $bobot, 'layanan' => 'kiloan', 'description' => 'x', 'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'handling';
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill(['compensation_amount' => $kompensasi])->save();

        return $complaint;
    }

    private function tutup(User $user, Complaint $complaint, array $ganti = [])
    {
        return $this->actingAs($user)->post('/complaints/'.$complaint->id.'/status', array_merge([
            'lock_version' => $complaint->fresh()->lock_version,
            'status'       => 'close',
            'close_reason' => 'selesai',
        ], $ganti));
    }

    /* ---------- Yang boleh ---------- */

    public function test_kasir_bisa_menutup_complaint_ringan_tanpa_kompensasi(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 0, $outlet);

        $this->tutup($this->userAs('kasir', $outlet), $complaint)->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('close', $complaint->status);
        $this->assertSame('selesai', $complaint->close_reason);
        $this->assertNotNull($complaint->resolved_at);
    }

    public function test_kasir_bisa_menutup_complaint_ringan_tepat_di_batas_kompensasi(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 50_000, $outlet);

        $this->tutup($this->userAs('kasir', $outlet), $complaint, ['compensation_amount' => 50_000])
            ->assertSessionHasNoErrors();

        $this->assertSame('close', $complaint->fresh()->status);
    }

    /* ---------- Yang tidak boleh ---------- */

    public function test_kasir_ditolak_menutup_complaint_ringan_satu_rupiah_di_atas_batas(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 50_001, $outlet);

        $this->tutup($this->userAs('kasir', $outlet), $complaint)
            ->assertSessionHasErrors('compensation_amount');

        $this->assertSame('handling', $complaint->fresh()->status);
    }

    public function test_kasir_ditolak_menutup_complaint_sedang_dan_berat_berapa_pun_kompensasinya(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);

        foreach (['sedang', 'berat'] as $bobot) {
            foreach ([0, 50_000] as $kompensasi) {
                $complaint = $this->complaint($bobot, $kompensasi, $outlet);

                $this->tutup($this->userAs('kasir', $outlet), $complaint)
                    ->assertSessionHasErrors('status');

                $this->assertSame('handling', $complaint->fresh()->status,
                    "kasir berhasil menutup complaint $bobot berkompensasi $kompensasi");
            }
        }
    }

    public function test_kasir_ditolak_menutup_complaint_ringan_outlet_lain(): void
    {
        $punyaKasir = Outlet::create(['name' => 'Tebet']);
        $outletLain = Outlet::create(['name' => 'Kemang']);

        $complaint = $this->complaint('ringan', 0, $outletLain);

        $this->tutup($this->userAs('kasir', $punyaKasir), $complaint)->assertForbidden();

        $this->assertSame('handling', $complaint->fresh()->status);
    }

    public function test_divisi_tetap_tidak_boleh_menutup_complaint_ringan(): void
    {
        $complaint = $this->complaint('ringan');
        $complaint->forceFill(['forwarded_division' => 'produksi'])->save();

        $divisi = $this->userAs('divisi');
        $divisi->update(['division' => 'produksi']);

        $this->tutup($divisi, $complaint)->assertSessionHasErrors('status');

        $this->assertSame('handling', $complaint->fresh()->status);
    }

    /* ---------- Yang tidak berubah ---------- */

    public function test_customer_care_tetap_bisa_menutup_complaint_berat(): void
    {
        $complaint = $this->complaint('berat', 200_000);

        $this->tutup($this->userAs('customer_care'), $complaint)->assertSessionHasNoErrors();

        $this->assertSame('close', $complaint->fresh()->status);
    }

    /**
     * Batas kompensasi berdiri sendiri dari bobot: Customer Care pun tertahan
     * pada complaint yang angkanya disetujui di atas wewenangnya.
     */
    public function test_customer_care_ditolak_menutup_complaint_di_atas_batas_wewenangnya(): void
    {
        $complaint = $this->complaint('berat', 1_000_000);

        $this->tutup($this->userAs('customer_care'), $complaint)
            ->assertSessionHasErrors('compensation_amount');

        $this->assertSame('handling', $complaint->fresh()->status);

        // Supervisor tidak punya batas, jadi ia yang menyelesaikannya.
        $this->tutup($this->userAs('supervisor'), $complaint)->assertSessionHasNoErrors();

        $this->assertSame('close', $complaint->fresh()->status);
    }

    public function test_kasir_tetap_bisa_memperbarui_complaint_berat_tanpa_menutupnya(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('berat', 0, $outlet);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'handling', 'note' => 'Sudah dihubungi pelanggan.',
            ])->assertSessionHasNoErrors();

        $this->assertSame('handling', $complaint->fresh()->status);
    }
}

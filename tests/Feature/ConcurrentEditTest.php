<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua orang menutup complaint yang sama, yang terakhir menang diam-diam.
 * (API-8 T6)
 *
 * CC menutup "selesai" dengan resolusi "Diganti baru, kompensasi diberikan";
 * supervisor — yang membuka halaman itu sebelum CC menyimpan — menutup
 * "ditolak" dengan "Klaim tidak terbukti". Hasil akhir: resolusi CC hilang
 * tanpa peringatan ke siapa pun. Riwayat memang mencatat keduanya, tapi
 * kolom keputusannya hanya menyimpan yang terakhir menekan tombol.
 */
class ConcurrentEditTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
        ]);
    }

    private function complaint(): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'ditangani';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_perubahan_dari_halaman_basi_ditolak(): void
    {
        $complaint = $this->complaint();
        $versiDibuka = $complaint->lock_version;

        // CC menyimpan lebih dulu.
        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'selesai', 'lock_version' => $versiDibuka,
                'resolution' => 'Diganti baru, kompensasi diberikan',
            ])->assertSessionHasNoErrors();

        // Supervisor menyimpan dari halaman yang dibuka sebelum itu.
        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditolak', 'lock_version' => $versiDibuka,
                'resolution' => 'Klaim tidak terbukti',
            ])->assertSessionHasErrors('lock_version');

        $complaint->refresh();

        $this->assertSame('selesai', $complaint->status);
        $this->assertSame('Diganti baru, kompensasi diberikan', $complaint->resolution,
            'resolusi yang ditulis lebih dulu tertimpa tanpa peringatan');
    }

    public function test_perubahan_dari_halaman_terbaru_tetap_masuk(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'selesai', 'lock_version' => $complaint->lock_version,
                'resolution' => 'Diganti baru',
            ])->assertSessionHasNoErrors();

        $complaint->refresh();

        // Supervisor memuat ulang dulu, baru menyimpan.
        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditolak', 'lock_version' => $complaint->lock_version,
                'resolution' => 'Ditinjau ulang: klaim tidak terbukti',
            ])->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('ditolak', $complaint->status);
        $this->assertSame('Ditinjau ulang: klaim tidak terbukti', $complaint->resolution);
    }

    public function test_versi_naik_setiap_perubahan_status(): void
    {
        $complaint = $this->complaint();
        $awal = $complaint->lock_version;

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'menunggu_pelanggan', 'lock_version' => $awal,
            ]);

        $this->assertSame($awal + 1, $complaint->fresh()->lock_version);
    }

    public function test_form_membawa_versi_yang_sedang_ditampilkan(): void
    {
        $complaint = $this->complaint();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertSee('name="lock_version"', false);
    }

    public function test_isian_petugas_tidak_hilang_saat_ditolak(): void
    {
        $complaint = $this->complaint();
        $versiDibuka = $complaint->lock_version;

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'selesai', 'lock_version' => $versiDibuka,
            ]);

        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'ditolak', 'lock_version' => $versiDibuka,
                'resolution' => 'Ketikan panjang yang tidak boleh hilang',
            ])->assertSessionHasInput('resolution', 'Ketikan panjang yang tidak boleh hilang');
    }
}

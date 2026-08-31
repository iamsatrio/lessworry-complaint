<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * API-25 nomor 4 — "Menunggu Pelanggan" turun jadi penanda jeda.
 *
 * Yang dijaga: tiketnya tetap terlihat Handling di papan, tapi jam SLA-nya
 * berhenti. Tanpa itu, tiket yang menunggu balasan pelanggan selama dua hari
 * jadi merah karena hal yang bukan urusan tim, dan papan yang merah karena
 * alasan salah akan berhenti dibaca.
 */
class JedaSlaTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $bobot = 'sedang'): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => $bobot, 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'handling';
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function simpan(User $user, Complaint $complaint, array $data)
    {
        return $this->actingAs($user)->post('/complaints/'.$complaint->id.'/status', array_merge([
            'lock_version' => $complaint->fresh()->lock_version,
            'status'       => 'handling',
        ], $data));
    }

    public function test_jeda_dua_hari_memundurkan_tenggat_dua_hari_dan_tiket_tetap_handling(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $tenggatAwal = $complaint->due_resolution_at->copy();
        $responAwal = $complaint->due_response_at->copy();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status, 'tiket yang dijeda harus tetap Handling');
        $this->assertTrue($complaint->isPaused());
        $this->assertEquals($tenggatAwal, $complaint->due_resolution_at,
            'tenggat tidak boleh bergeser saat jedanya baru dimulai');

        // Dua hari berlalu sambil menunggu pelanggan membalas.
        Carbon::setTestNow(now()->addDays(2));

        $this->assertFalse($complaint->fresh()->isOverdue(),
            'tiket yang dijeda tidak boleh dihitung lewat tenggat');
        $this->assertSame('paused', $complaint->fresh()->slaMeter()['state']);

        $this->simpan($cc, $complaint, ['pause_reason' => ''])->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertFalse($complaint->isPaused());
        $this->assertNull($complaint->paused_at);
        $this->assertSame(
            2 * 24 * 60,
            (int) $tenggatAwal->diffInMinutes($complaint->due_resolution_at),
            'tenggat penyelesaian harus mundur sebanyak lama jeda'
        );
        $this->assertSame(
            2 * 24 * 60,
            (int) $responAwal->diffInMinutes($complaint->due_response_at)
        );

        Carbon::setTestNow();
    }

    public function test_menjeda_dua_kali_tidak_memundurkan_tenggat_dua_kali(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $tenggatAwal = $complaint->due_resolution_at->copy();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        Carbon::setTestNow(now()->addDay());

        // Menyimpan form yang sama lagi tidak boleh memperpanjang apa pun.
        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(now()->addDay());

        $this->simpan($cc, $complaint, ['pause_reason' => '']);

        $this->assertSame(
            2 * 24 * 60,
            (int) $tenggatAwal->diffInMinutes($complaint->fresh()->due_resolution_at),
            'jeda yang disimpan ulang menggeser tenggat lebih jauh daripada lama jeda sebenarnya'
        );

        Carbon::setTestNow();
    }

    public function test_jeda_ditolak_untuk_tiket_yang_belum_ditangani(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();
        $complaint->forceFill(['status' => 'open'])->save();

        $this->simpan($cc, $complaint, ['status' => 'open', 'pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasErrors('pause_reason');

        $this->assertNull($complaint->fresh()->paused_at);
    }

    public function test_menutup_tiket_yang_sedang_dijeda_mengakhiri_jedanya(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        Carbon::setTestNow(now()->addDay());

        $this->simpan($cc, $complaint, ['status' => 'close', 'close_reason' => 'selesai'])
            ->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('close', $complaint->status);
        $this->assertNull($complaint->paused_at);
        $this->assertFalse($complaint->isPaused());

        Carbon::setTestNow();
    }

    public function test_jeda_dan_lanjut_tercatat_di_riwayat(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        Carbon::setTestNow(now()->addDays(2));

        $this->simpan($cc, $complaint, ['pause_reason' => '']);

        $catatan = $complaint->activities()->pluck('note')->implode(' | ');

        $this->assertStringContainsString('SLA dijeda', $catatan);
        $this->assertStringContainsString('SLA dilanjutkan', $catatan);

        Carbon::setTestNow();
    }

    public function test_papan_kerja_menampilkan_tiket_yang_dijeda_sebagai_handling(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        $html = $this->actingAs($cc)->get('/complaints')->assertOk()->getContent();

        $this->assertStringContainsString($complaint->ticket_number, $html);
        $this->assertStringContainsString('Handling', $html);
        $this->assertStringContainsString('Menunggu Pelanggan', $html);
    }

    public function test_alasan_jeda_di_luar_daftar_ditolak(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_vendor'])
            ->assertSessionHasErrors('pause_reason');

        $this->assertNull($complaint->fresh()->paused_at);
    }
}

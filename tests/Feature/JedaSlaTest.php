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

    /* ---------- Jeda bukan waktu kerja (Review PR #1 nomor 3) ---------- */

    /**
     * Kasus Buffon persis: Berat dibuat, hari ke-1 dijeda, ditutup 10 hari
     * kemudian. Waktu penyelesaian yang benar 1 hari, bukan 11.
     *
     * Sebelum perbaikan, satu tiket melaporkan dua kebenaran yang bertabrakan:
     * `isOverdue()` bilang tepat waktu karena tenggatnya ikut mundur, laporan
     * bilang sebelas hari karena menghitung mentah dari created_at.
     */
    public function test_lama_jeda_tidak_dihitung_sebagai_waktu_penyelesaian(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint('berat');

        Carbon::setTestNow(now()->addDay());
        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(now()->addDays(10));
        $this->simpan($cc, $complaint, ['status' => 'close', 'close_reason' => 'selesai'])
            ->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('close', $complaint->status);
        $this->assertSame(24 * 60, $complaint->resolutionMinutes(),
            'waktu menunggu pelanggan ikut terhitung sebagai waktu kerja tim');
        $this->assertSame(10 * 24 * 60, $complaint->totalPauseMinutes());

        // Kedua angka harus sepakat: tidak telat menurut SLA, dan tidak
        // sebelas hari menurut laporan.
        $this->assertFalse($complaint->isOverdue());
        $this->assertStringContainsString('1 hari', $complaint->slaMeter()['label']);

        Carbon::setTestNow();
    }

    /** Dijeda dua kali: yang dikurangi totalnya, bukan jeda terakhir saja. */
    public function test_jeda_berulang_ditotalkan_bukan_ditimpa(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint('berat');

        foreach ([2, 3] as $lama) {
            $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);
            Carbon::setTestNow(now()->addDays($lama));
            $this->simpan($cc, $complaint, ['pause_reason' => '']);
        }

        Carbon::setTestNow(now()->addDay());
        $this->simpan($cc, $complaint, ['status' => 'close', 'close_reason' => 'selesai']);

        $complaint->refresh();

        $this->assertSame(5 * 24 * 60, $complaint->totalPauseMinutes(),
            'jeda kedua menimpa jeda pertama, bukan menambahinya');
        $this->assertSame(24 * 60, $complaint->resolutionMinutes());

        Carbon::setTestNow();
    }

    /** Laporan dan CSV memakai angka yang sama, jadi keduanya ikut bersih. */
    public function test_laporan_tidak_memasukkan_jeda_sebagai_waktu_kerja(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint('berat');

        Carbon::setTestNow(now()->addDay());
        $this->simpan($cc, $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        Carbon::setTestNow(now()->addDays(10));
        $this->simpan($cc, $complaint, ['status' => 'close', 'close_reason' => 'selesai']);

        $csv = $this->actingAs($cc)->get('/reports/export')->streamedContent();
        $baris = collect(explode("\n", $csv))->first(fn ($b) => str_contains($b, $complaint->ticket_number));

        $this->assertStringContainsString(',1440,', $baris,
            'kolom Menit Penyelesaian di CSV masih memasukkan lama jeda');

        $this->actingAs($cc)->get('/reports')->assertOk()->assertDontSee('11 hari', false);

        Carbon::setTestNow();
    }

    /* ---------- Wewenang menjeda (Review PR #1 temuan B) ---------- */

    /**
     * Jeda menghentikan jam SLA, jadi ia menentukan apakah sebuah tiket bisa
     * berubah merah. Tanpa batas, satu outlet punya cara membungkam papan per
     * tiket — persis kebalikan dari yang mau dicapai issue ini.
     */
    public function test_kasir_ditolak_menjeda_complaint_sedang_dan_berat(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);

        foreach (['sedang', 'berat'] as $bobot) {
            $complaint = $this->complaint($bobot);
            $complaint->forceFill(['outlet_id' => $outlet->id])->save();

            $this->simpan($this->userAs('kasir', $outlet), $complaint, ['pause_reason' => 'menunggu_pelanggan'])
                ->assertSessionHasErrors('pause_reason');

            $complaint->refresh();

            $this->assertNull($complaint->paused_at, "kasir berhasil menjeda complaint $bobot");
            $this->assertNull($complaint->pause_reason);
            $this->assertFalse($complaint->isPaused());
        }
    }

    /** Jeda yang tidak sah tidak boleh menyembunyikan tiket dari papan merah. */
    public function test_complaint_berat_yang_gagal_dijeda_kasir_tetap_bisa_jadi_merah(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('berat');
        $complaint->forceFill(['outlet_id' => $outlet->id])->save();

        $this->simpan($this->userAs('kasir', $outlet), $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasErrors('pause_reason');

        // Tenggat Berat 5 hari; 30 hari kemudian tiket ini wajib merah.
        Carbon::setTestNow(now()->addDays(30));

        $this->assertTrue($complaint->fresh()->isOverdue(),
            'complaint Berat tetap tidak merah setelah 30 hari — papannya bisa dibungkam per tiket');

        Carbon::setTestNow();
    }

    public function test_kasir_boleh_menjeda_complaint_ringan(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan');
        $complaint->forceFill(['outlet_id' => $outlet->id])->save();

        $this->simpan($this->userAs('kasir', $outlet), $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($complaint->fresh()->isPaused());
    }

    /**
     * Yang dibatasi memulai jeda, bukan mempertahankannya. Kasir yang
     * memperbarui tiket Berat yang sudah dijeda Customer Care tidak sedang
     * menjalankan wewenang itu — menolaknya hanya mengunci tiketnya.
     */
    public function test_kasir_tetap_bisa_memperbarui_tiket_berat_yang_sudah_dijeda_customer_care(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('berat');
        $complaint->forceFill(['outlet_id' => $outlet->id])->save();

        $this->simpan($this->userAs('customer_care'), $complaint, ['pause_reason' => 'menunggu_pelanggan'])
            ->assertSessionHasNoErrors();

        // Kasir menambah catatan tanpa mengirim field jeda sama sekali.
        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'handling', 'note' => 'Pelanggan sudah dihubungi lagi.',
            ])->assertSessionHasNoErrors();

        $this->assertTrue($complaint->fresh()->isPaused(), 'jedanya ikut hilang saat kasir menambah catatan');
    }

    /** Melanjutkan tiket mengembalikannya ke hitungan SLA — arah yang aman. */
    public function test_kasir_boleh_melanjutkan_tiket_berat_yang_dijeda(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('berat');
        $complaint->forceFill(['outlet_id' => $outlet->id])->save();

        $this->simpan($this->userAs('customer_care'), $complaint, ['pause_reason' => 'menunggu_pelanggan']);

        $this->simpan($this->userAs('kasir', $outlet), $complaint, ['pause_reason' => ''])
            ->assertSessionHasNoErrors();

        $this->assertFalse($complaint->fresh()->isPaused());
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

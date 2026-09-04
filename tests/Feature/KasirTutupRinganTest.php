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
            'status' => 'close',
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

    /** Angka di atas batas yang DIKIRIM di request, bukan yang sudah tersimpan. */
    public function test_kasir_ditolak_menaikkan_kompensasi_di_atas_batas_sambil_menutup(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 0, $outlet);

        $this->tutup($this->userAs('kasir', $outlet), $complaint, ['compensation_amount' => 50_001])
            ->assertSessionHasErrors('compensation_amount');

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertSame(0, (int) $complaint->compensation_amount,
            'kompensasi di atas batas ikut tersimpan meski penutupannya ditolak');
    }

    /**
     * Jebakan yang paling mudah terlewat: kompensasi Rp 200.000 sudah
     * tersimpan, dan kasir menutup tiket **tanpa mengirim field kompensasi
     * sama sekali**.
     *
     * Pemeriksaan yang hanya berjalan saat nilainya BERUBAH akan melewatkan
     * ini seluruhnya — tidak ada perubahan, jadi tidak ada yang diperiksa,
     * dan complaint tertutup. Batasnya harus diuji terhadap nilai yang
     * TERSIMPAN saat menutup.
     */
    public function test_kasir_ditolak_menutup_complaint_ringan_berkompensasi_besar_tanpa_mengirim_field_kompensasi(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 200_000, $outlet);

        $balasan = $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'close',
                'close_reason' => 'selesai',
                // Sengaja: tidak ada 'compensation_amount' di payload.
            ]);

        $balasan->assertSessionHasErrors('compensation_amount');

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status,
            'kasir menutup complaint Rp 200.000 hanya dengan tidak mengirim field kompensasi');
        $this->assertNull($complaint->close_reason);
        $this->assertNull($complaint->resolved_at);
        $this->assertSame(200_000, (int) $complaint->compensation_amount);
    }

    /** Bentuk kedua dari jebakan yang sama: form mengirim balik nilai yang sudah ada. */
    public function test_kasir_ditolak_menutup_complaint_ringan_yang_mengirim_ulang_kompensasi_besar(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 200_000, $outlet);

        $this->tutup($this->userAs('kasir', $outlet), $complaint, ['compensation_amount' => 200_000])
            ->assertSessionHasErrors('compensation_amount');

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertSame(200_000, (int) $complaint->compensation_amount);
    }

    public function test_kasir_ditolak_menutup_complaint_sedang_dan_berat_berapa_pun_kompensasinya(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);

        foreach (['sedang', 'berat'] as $bobot) {
            foreach ([0, 50_000] as $kompensasi) {
                $complaint = $this->complaint($bobot, $kompensasi, $outlet);

                $this->tutup($this->userAs('kasir', $outlet), $complaint)
                    ->assertSessionHasErrors('status');

                $complaint->refresh();

                $this->assertSame('handling', $complaint->status,
                    "kasir berhasil menutup complaint $bobot berkompensasi $kompensasi");
                $this->assertNull($complaint->close_reason);
                $this->assertNull($complaint->resolved_at);
            }
        }
    }

    public function test_kasir_ditolak_menutup_complaint_ringan_outlet_lain(): void
    {
        $punyaKasir = Outlet::create(['name' => 'Tebet']);
        $outletLain = Outlet::create(['name' => 'Kemang']);

        $complaint = $this->complaint('ringan', 0, $outletLain);

        $this->tutup($this->userAs('kasir', $punyaKasir), $complaint)->assertForbidden();

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertNull($complaint->resolved_at);
    }

    /**
     * Wewenang dicabut saat sesinya masih berjalan. Kasir yang sudah masuk
     * dan sedang membuka halaman complaint outletnya sendiri tidak boleh
     * tetap bisa menutup tiket setelah akunnya dinonaktifkan.
     */
    public function test_kasir_yang_dinonaktifkan_saat_sesi_berjalan_tidak_bisa_menutup(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 0, $outlet);
        $kasir = $this->userAs('kasir', $outlet);

        $this->actingAs($kasir);

        // Halaman complaint-nya memang bisa dibuka — sampai di sini sah.
        $this->get('/complaints/'.$complaint->id)->assertOk();

        $kasir->forceFill(['is_active' => false])->save();

        $this->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version,
            'status' => 'close', 'close_reason' => 'selesai',
        ])->assertRedirect(route('login'));

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertGuest();
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

    /* ---------- Membuka kembali = wewenang yang sama (Review PR #1 temuan A) ---------- */

    /**
     * Kebalikan sebuah tindakan berwewenang tetap tindakan berwewenang.
     *
     * Sebelumnya wewenang hanya diperiksa saat MENUTUP, jadi kasir tidak boleh
     * menutup complaint Berat tapi boleh membatalkan penutupan supervisor —
     * dan blok transaksinya mengosongkan `resolved_at`, jadi waktu penyelesaian
     * yang "selalu dihitung sistem" hilang permanen.
     */
    public function test_kasir_ditolak_membuka_kembali_complaint_berat_yang_ditutup_supervisor(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('berat', 1_000_000, $outlet);

        $this->tutup($this->userAs('supervisor'), $complaint)->assertSessionHasNoErrors();

        $ditutupPada = $complaint->fresh()->resolved_at;
        $this->assertNotNull($ditutupPada);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'handling',
            ])->assertSessionHasErrors('status');

        $complaint->refresh();

        $this->assertSame('close', $complaint->status, 'kasir membatalkan penutupan supervisor');
        $this->assertSame('selesai', $complaint->close_reason);
        $this->assertEquals($ditutupPada, $complaint->resolved_at,
            'waktu penyelesaian hilang saat penutupan dibatalkan');
    }

    /** Batas kompensasi ikut berlaku ke arah sebaliknya, bukan hanya saat menutup. */
    public function test_customer_care_ditolak_membuka_kembali_complaint_di_atas_batas_wewenangnya(): void
    {
        $complaint = $this->complaint('berat', 1_000_000);

        $this->tutup($this->userAs('supervisor'), $complaint)->assertSessionHasNoErrors();

        $this->actingAs($this->userAs('customer_care'))
            ->post('/complaints/'.$complaint->id.'/status', [
                'lock_version' => $complaint->fresh()->lock_version,
                'status' => 'handling',
            ])->assertSessionHasErrors('compensation_amount');

        $this->assertSame('close', $complaint->fresh()->status);
    }

    public function test_kasir_boleh_membuka_kembali_complaint_ringan_yang_ditutupnya_sendiri(): void
    {
        $outlet = Outlet::create(['name' => 'Pusat']);
        $complaint = $this->complaint('ringan', 0, $outlet);
        $kasir = $this->userAs('kasir', $outlet);

        $this->tutup($kasir, $complaint)->assertSessionHasNoErrors();
        $this->assertSame('close', $complaint->fresh()->status);

        $this->actingAs($kasir)->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version,
            'status' => 'handling',
        ])->assertSessionHasNoErrors();

        $complaint->refresh();

        $this->assertSame('handling', $complaint->status);
        $this->assertNull($complaint->close_reason);
        $this->assertNull($complaint->resolved_at);
    }

    /** Penutupan yang dibatalkan boleh, tapi tidak boleh tanpa bekas. */
    public function test_pembatalan_penutupan_meninggalkan_jejak_waktu_selesai_sebelumnya(): void
    {
        $complaint = $this->complaint('berat');
        $supervisor = $this->userAs('supervisor');

        $this->tutup($supervisor, $complaint)->assertSessionHasNoErrors();

        $this->actingAs($supervisor)->post('/complaints/'.$complaint->id.'/status', [
            'lock_version' => $complaint->fresh()->lock_version,
            'status' => 'handling',
        ])->assertSessionHasNoErrors();

        $catatan = $complaint->activities()->pluck('note')->implode(' | ');

        $this->assertStringContainsString('Penutupan dibatalkan', $catatan,
            'waktu penyelesaian dibuang tanpa satu baris pun di riwayat');
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

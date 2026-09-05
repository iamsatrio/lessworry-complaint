<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Id internal NEVIRA (nevira_transaction_id) tidak boleh keluar dari sistem
 * lewat jalan mana pun. (API-8 T2/T12, API-14 #4)
 *
 * Yang dipegang petugas adalah nomor nota. Id internal hanya alat panggil
 * API — kalau bocor, ia jadi bahan tebakan untuk menyisir NEVIRA.
 */
class InternalIdLeakTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    private const ID_INTERNAL = '31242';

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaintTertaut(?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'reporter_phone' => '081200001111',
            'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'nevira_transaction_number' => self::NOTA,
            'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->applySla();
        $complaint->save();
        $complaint->forceFill(['nevira_transaction_id' => self::ID_INTERNAL])->save();

        return $complaint;
    }

    public function test_ekspor_csv_supervisor_tidak_memuat_id_internal(): void
    {
        $this->complaintTertaut();

        $csv = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export')->streamedContent();

        $this->assertStringNotContainsString(self::ID_INTERNAL, $csv,
            'id internal NEVIRA ikut terekspor; CSV rekap beredar lewat WhatsApp');
        $this->assertStringNotContainsString('ID Transaksi NEVIRA', $csv,
            'kolomnya masih berlabel id internal');
    }

    public function test_ekspor_csv_kasir_tidak_memuat_id_internal(): void
    {
        $outlet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);
        $this->complaintTertaut($outlet);

        $csv = $this->actingAs($this->userAs('kasir', $outlet))
            ->get('/reports/export')->streamedContent();

        $this->assertStringNotContainsString(self::ID_INTERNAL, $csv);
    }

    public function test_ekspor_csv_tetap_membawa_nomor_nota(): void
    {
        $this->complaintTertaut();

        $csv = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export')->streamedContent();

        $this->assertStringContainsString(self::NOTA, $csv,
            'nomor nota justru yang dibutuhkan di rekap — jangan ikut dibuang');
        $this->assertStringContainsString('Nomor Nota', $csv);
    }

    public function test_papan_kerja_tidak_bisa_dicari_pakai_id_internal(): void
    {
        $complaint = $this->complaintTertaut();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints?q='.self::ID_INTERNAL)
            ->assertDontSee($complaint->ticket_number);
    }

    public function test_papan_kerja_masih_bisa_dicari_pakai_nomor_nota(): void
    {
        $complaint = $this->complaintTertaut();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints?q='.urlencode(self::NOTA))
            ->assertSee($complaint->ticket_number);
    }
}

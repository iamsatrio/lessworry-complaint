<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Complaint ganda untuk nota yang sama. (API-8 T7)
 *
 * Satu nota memang bisa punya dua keluhan berbeda — noda yang tidak hilang
 * DAN antarnya telat. Jadi ini peringatan, bukan larangan: petugas harus
 * tahu supaya tidak menghitung satu keluhan tiga kali di rekap SLA dan tidak
 * mengerjakannya bertiga secara paralel.
 */
class DuplicateReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(?string $nota = self::NOTA, ?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
            'nevira_transaction_number' => $nota,
            'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_complaint_kedua_untuk_nota_yang_sama_tetap_boleh_dibuat(): void
    {
        config(['nevira.enabled' => false]);
        $pertama = $this->complaint();

        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor Lain', 'category' => 'keterlambatan',
            'priority' => 'medium', 'description' => 'Keluhan berbeda untuk nota yang sama',
            'nevira_transaction_number' => self::NOTA,
        ])->assertRedirect();

        $this->assertSame(2, Complaint::where('nevira_transaction_number', self::NOTA)->count(),
            'satu nota memang boleh punya dua keluhan berbeda — jangan dilarang');
        $this->assertNotSame($pertama->id, Complaint::latest('id')->first()->id);
    }

    public function test_petugas_diperingatkan_saat_nota_sudah_pernah_dikeluhkan(): void
    {
        config(['nevira.enabled' => false]);
        $pertama = $this->complaint();

        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor Lain', 'category' => 'keterlambatan',
            'priority' => 'medium', 'description' => 'Keluhan berbeda',
            'nevira_transaction_number' => self::NOTA,
        ])->assertSessionHas('warning', fn ($pesan) => str_contains($pesan, $pertama->ticket_number));
    }

    public function test_halaman_complaint_menyebut_complaint_lain_untuk_nota_yang_sama(): void
    {
        $pertama = $this->complaint();
        $kedua = $this->complaint();

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$kedua->id)
            ->assertSee($pertama->ticket_number);
    }

    public function test_complaint_tanpa_nota_tidak_dianggap_kembar(): void
    {
        $a = $this->complaint(null);
        $b = $this->complaint(null);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$b->id)
            ->assertDontSee($a->ticket_number);
    }

    public function test_peringatan_tidak_membocorkan_complaint_outlet_lain(): void
    {
        $pusat = Outlet::create(['name' => 'Pusat']);
        $cabang = Outlet::create(['name' => 'Cabang']);

        $rahasia = $this->complaint(self::NOTA, $cabang);
        $milikku = $this->complaint(self::NOTA, $pusat);

        $this->actingAs($this->userAs('kasir', $pusat))
            ->get('/complaints/'.$milikku->id)
            ->assertDontSee($rahasia->ticket_number);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Policies\ComplaintPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan wewenang dipaku di sini, bukan disebar di controller.
 *
 * Dipindahnya 25 `abort_unless` ke ComplaintPolicy tidak boleh melonggarkan
 * apa pun. Test ini menyatakan aturannya langsung — kalau seseorang nanti
 * "menyederhanakan" policy, yang jatuh adalah berkas ini, bukan test HTTP
 * yang kebetulan tidak menyentuh kombinasi itu.
 */
class ComplaintPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ComplaintPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ComplaintPolicy;
    }

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
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'x',
        ], $attrs));
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /* ---------- create ---------- */

    public function test_hanya_peran_pencatat_yang_boleh_membuat_complaint(): void
    {
        $this->assertTrue($this->policy->create($this->userAs('kasir')));
        $this->assertTrue($this->policy->create($this->userAs('customer_care')));
        $this->assertTrue($this->policy->create($this->userAs('supervisor')));
        $this->assertFalse($this->policy->create($this->userAs('divisi', null, 'produksi')));
    }

    /* ---------- view ---------- */

    public function test_kasir_hanya_melihat_complaint_outletnya(): void
    {
        $a = Outlet::create(['name' => 'A']);
        $b = Outlet::create(['name' => 'B']);
        $kasir = $this->userAs('kasir', $a);

        $this->assertTrue($this->policy->view($kasir, $this->complaint(['outlet_id' => $a->id])));
        $this->assertFalse($this->policy->view($kasir, $this->complaint(['outlet_id' => $b->id])));
    }

    public function test_divisi_hanya_melihat_yang_diteruskan_ke_divisinya(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');

        $this->assertTrue($this->policy->view($divisi, $this->complaint(['forwarded_division' => 'produksi'])));
        $this->assertFalse($this->policy->view($divisi, $this->complaint(['forwarded_division' => 'keuangan'])));
    }

    /* ---------- penugasan dan pelaku ---------- */

    public function test_kasir_dan_divisi_tidak_boleh_menugaskan_atau_menetapkan_pelaku(): void
    {
        $outlet = Outlet::create(['name' => 'A']);
        $kasir = $this->userAs('kasir', $outlet);
        $milikKasir = $this->complaint(['outlet_id' => $outlet->id]);

        // Boleh melihat, tetap tidak boleh menugaskan. (API-14 #3)
        $this->assertTrue($this->policy->view($kasir, $milikKasir));
        $this->assertFalse($this->policy->assign($kasir, $milikKasir));
        $this->assertFalse($this->policy->manageResponsible($kasir, $milikKasir));

        $divisi = $this->userAs('divisi', null, 'produksi');
        $diteruskan = $this->complaint(['forwarded_division' => 'produksi']);

        $this->assertTrue($this->policy->view($divisi, $diteruskan));
        $this->assertFalse($this->policy->assign($divisi, $diteruskan));
        $this->assertFalse($this->policy->manageResponsible($divisi, $diteruskan));
    }

    public function test_customer_care_dan_supervisor_boleh_menugaskan_dan_menetapkan_pelaku(): void
    {
        $complaint = $this->complaint();

        foreach (['customer_care', 'supervisor'] as $role) {
            $user = $this->userAs($role);
            $this->assertTrue($this->policy->assign($user, $complaint), $role);
            $this->assertTrue($this->policy->manageResponsible($user, $complaint), $role);
        }
    }

    /* ---------- tautan NEVIRA ---------- */

    public function test_divisi_tidak_boleh_menautkan_walau_complaintnya_diteruskan_padanya(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $diteruskan = $this->complaint(['forwarded_division' => 'produksi']);

        $this->assertTrue($this->policy->view($divisi, $diteruskan));
        $this->assertFalse($this->policy->link($divisi, $diteruskan), 'divisi menarik data pelanggan dari NEVIRA');
    }

    public function test_kasir_boleh_menautkan_complaint_outletnya_sendiri_saja(): void
    {
        $a = Outlet::create(['name' => 'A']);
        $b = Outlet::create(['name' => 'B']);
        $kasir = $this->userAs('kasir', $a);

        $this->assertTrue($this->policy->link($kasir, $this->complaint(['outlet_id' => $a->id])));
        $this->assertFalse($this->policy->link($kasir, $this->complaint(['outlet_id' => $b->id])));
    }

    /* ---------- lampiran ---------- */

    public function test_lampiran_mengikuti_wewenang_melihat_complaintnya(): void
    {
        $a = Outlet::create(['name' => 'A']);
        $b = Outlet::create(['name' => 'B']);
        $kasir = $this->userAs('kasir', $a);

        $this->assertTrue($this->policy->viewAttachment($kasir, $this->complaint(['outlet_id' => $a->id])));
        $this->assertFalse($this->policy->viewAttachment($kasir, $this->complaint(['outlet_id' => $b->id])));
    }

    /* ---------- status dan catatan ---------- */

    public function test_status_dan_catatan_terbuka_untuk_siapa_pun_yang_boleh_melihat(): void
    {
        $divisi = $this->userAs('divisi', null, 'produksi');
        $diteruskan = $this->complaint(['forwarded_division' => 'produksi']);
        $lain = $this->complaint(['forwarded_division' => 'keuangan']);

        $this->assertTrue($this->policy->updateStatus($divisi, $diteruskan));
        $this->assertTrue($this->policy->addNote($divisi, $diteruskan));
        $this->assertFalse($this->policy->updateStatus($divisi, $lain));
        $this->assertFalse($this->policy->addNote($divisi, $lain));
    }
}

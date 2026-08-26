<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function makeComplaint(array $attrs = []): Complaint
    {
        $c = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelanggan', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'status' => 'baru', 'description' => 'Keluhan uji',
        ], $attrs));
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->created_at = now();
        $c->applySla();
        $c->save();

        return $c;
    }

    private function withTrail(): Complaint
    {
        $c = $this->makeComplaint(['nevira_transaction_id' => '31135']);
        $c->forceFill(['nevira_snapshot' => [
            'cashier_name' => 'Muhamad Gilang Ramadhan',
            'cashier_nip'  => 'LW/06-0002',
            'cashier_id'   => 535,
            'processes'    => [
                ['stage' => 'Spotting', 'staff_name' => 'Andi', 'staff_nip' => 'LW/01', 'staff_id' => 243, 'status' => 'COMPLETED', 'duration' => 817],
                ['stage' => 'Cuci', 'staff_name' => 'Budi', 'staff_nip' => 'LW/02', 'staff_id' => 244, 'status' => 'COMPLETED', 'duration' => 600],
            ],
        ]])->save();

        return $c;
    }

    /* ---------- Membaca jejak karyawan ---------- */

    public function test_jejak_karyawan_disusun_dari_kasir_dan_tahap_produksi(): void
    {
        $handlers = $this->withTrail()->orderHandlers();

        $this->assertCount(3, $handlers);
        $this->assertSame('Kasir penerima order', $handlers[0]['stage']);
        $this->assertSame('Muhamad Gilang Ramadhan', $handlers[0]['name']);
        $this->assertSame('Cuci', $handlers[2]['stage']);
        $this->assertSame('Budi', $handlers[2]['name']);
    }

    public function test_tahap_tanpa_nama_karyawan_dilewati(): void
    {
        $c = $this->makeComplaint();
        $c->forceFill(['nevira_snapshot' => [
            'processes' => [
                ['stage' => 'Cuci', 'staff_name' => null],
                ['stage' => 'Lipat', 'staff_name' => 'Citra'],
            ],
        ]])->save();

        $handlers = $c->orderHandlers();

        $this->assertCount(1, $handlers);
        $this->assertSame('Citra', $handlers[0]['name']);
    }

    /* ---------- Siapa boleh melihat ---------- */

    public function test_kasir_tidak_melihat_panel_karyawan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $kasir = $this->userAs('kasir', $outlet);
        $c = $this->withTrail();
        $c->forceFill(['outlet_id' => $outlet->id])->save();

        $response = $this->actingAs($kasir)->get('/complaints/'.$c->id);

        $response->assertOk();
        $response->assertDontSee('Karyawan yang menangani order ini');
        $response->assertDontSee('Muhamad Gilang Ramadhan');
    }

    public function test_customer_care_dan_supervisor_melihat_panel_karyawan(): void
    {
        $c = $this->withTrail();

        foreach (['customer_care', 'supervisor'] as $role) {
            $this->actingAs($this->userAs($role))
                ->get('/complaints/'.$c->id)
                ->assertSee('Karyawan yang menangani order ini');
        }
    }

    public function test_kasir_tidak_bisa_menetapkan_penanggung_jawab(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $kasir = $this->userAs('kasir', $outlet);
        $c = $this->withTrail();
        $c->forceFill(['outlet_id' => $outlet->id])->save();

        $this->actingAs($kasir)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => 'Budi',
            'responsibility_note'    => 'coba-coba',
        ])->assertForbidden();

        $this->assertNull($c->fresh()->responsible_staff_name);
    }

    /* ---------- Aturan penetapan ---------- */

    public function test_penetapan_tanpa_alasan_ditolak(): void
    {
        $cc = $this->userAs('customer_care');
        $c = $this->withTrail();

        $this->actingAs($cc)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => 'Budi',
        ])->assertSessionHasErrors('responsibility_note');

        $this->assertNull($c->fresh()->responsible_staff_name);
    }

    public function test_penetapan_menyimpan_siapa_yang_menetapkan_dan_kapan(): void
    {
        $cc = $this->userAs('customer_care');
        $c = $this->withTrail();

        $this->actingAs($cc)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => 'Budi',
            'responsible_staff_nip'  => 'LW/02',
            'responsible_stage'      => 'Cuci',
            'responsibility_note'    => 'Noda kerah masih ada setelah tahap cuci.',
        ])->assertRedirect();

        $c->refresh();

        $this->assertSame('Budi', $c->responsible_staff_name);
        $this->assertSame('Cuci', $c->responsible_stage);
        $this->assertSame($cc->id, $c->responsibility_set_by);
        $this->assertNotNull($c->responsibility_set_at);
    }

    public function test_penetapan_tercatat_di_riwayat_beserta_alasan(): void
    {
        $cc = $this->userAs('customer_care');
        $c = $this->withTrail();

        $this->actingAs($cc)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => 'Budi',
            'responsibility_note'    => 'Noda kerah masih ada.',
        ])->assertSessionHasNoErrors();

        $note = $c->activities()->orderByDesc('id')->first()?->note;
        $this->assertNotNull($note, 'Tidak ada aktivitas tercatat.');

        $this->assertStringContainsString('Budi', $note);
        $this->assertStringContainsString('Noda kerah masih ada.', $note);
    }

    public function test_penetapan_bisa_dicabut_dan_pencabutan_tercatat(): void
    {
        $cc = $this->userAs('customer_care');
        $c = $this->withTrail();

        $this->actingAs($cc)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => 'Budi', 'responsibility_note' => 'Awal.',
        ]);

        $this->actingAs($cc)->put('/complaints/'.$c->id.'/responsibility', [
            'responsible_staff_name' => '',
        ]);

        $c->refresh();

        $this->assertNull($c->responsible_staff_name);
        $this->assertNull($c->responsibility_set_by);
        $this->assertStringContainsString('dicabut', $c->activities()->latest('id')->first()->note);
    }

    /* ---------- Laporan ---------- */

    public function test_rekap_per_karyawan_tidak_bocor_ke_kasir(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $kasir = $this->userAs('kasir', $outlet);

        $c = $this->makeComplaint(['outlet_id' => $outlet->id]);
        $c->forceFill([
            'responsible_staff_name' => 'Budi', 'responsibility_note' => 'x',
        ])->save();

        $response = $this->actingAs($kasir)->get('/reports');

        $response->assertOk();
        $response->assertDontSee('Complaint per karyawan');
        $response->assertDontSee('Budi');
    }

    public function test_ekspor_csv_kasir_tidak_memuat_kolom_karyawan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $kasir = $this->userAs('kasir', $outlet);

        $c = $this->makeComplaint(['outlet_id' => $outlet->id]);
        $c->forceFill(['responsible_staff_name' => 'Budi', 'responsibility_note' => 'x'])->save();

        $csv = $this->actingAs($kasir)->get('/reports/export')->streamedContent();

        $this->assertStringNotContainsString('Budi', $csv);
        $this->assertStringNotContainsString('Karyawan Penanggung Jawab', $csv);
    }

    public function test_ekspor_csv_supervisor_memuat_kolom_karyawan(): void
    {
        $supervisor = $this->userAs('supervisor');

        $c = $this->makeComplaint();
        $c->forceFill(['responsible_staff_name' => 'Budi', 'responsibility_note' => 'x'])->save();

        $csv = $this->actingAs($supervisor)->get('/reports/export')->streamedContent();

        $this->assertStringContainsString('Karyawan Penanggung Jawab', $csv);
        $this->assertStringContainsString('Budi', $csv);
    }
}

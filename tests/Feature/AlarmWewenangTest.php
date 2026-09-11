<?php

namespace Tests\Feature;

use App\Alarms\ComplaintBelumDipegang;
use App\Alarms\Lingkup;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Siapa membuka Dashboard Operations, dan apa yang dilihatnya. (API-72
 * kriteria 5 dan 6, alur 4 di API-71)
 *
 * Dua hal yang dijaga terpisah, dan urutannya penting:
 *
 * 1. Menu Dashboard TIDAK DIRENDER untuk kasir dan Customer Care — bukan menu
 *    yang membalas 403.
 * 2. Yang menegakkan bukan menu itu. Permintaan langsung ke servernya ditolak,
 *    dan cakupan outlet tetap berlaku DI DALAM halaman kalau kelak peran
 *    ber-outlet diberi wewenang ini lewat `config('complaint.dashboard_roles')`.
 */
class AlarmWewenangTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaintMenganggur(?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = now()->subHours(6);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /* ---------- Kriteria 6 ---------- */

    public function test_kasir_dan_customer_care_ditolak_membuka_dashboard(): void
    {
        foreach (['kasir', 'customer_care', 'divisi'] as $role) {
            $this->actingAs($this->userAs($role))->get('/operasional')->assertForbidden();
        }
    }

    public function test_kasir_dan_customer_care_tidak_melihat_menu_dashboard(): void
    {
        foreach (['kasir', 'customer_care'] as $role) {
            $html = $this->actingAs($this->userAs($role))->get('/complaints')->assertOk()->getContent();

            $this->assertStringNotContainsString('>Dashboard</a>', $html,
                "menu Dashboard tidak boleh dirender untuk $role");
            $this->assertStringNotContainsString('/operasional', $html,
                "tautan ke Dashboard Operations tidak boleh ada di halaman $role");
        }
    }

    public function test_admin_dan_supervisor_melihat_menu_dashboard(): void
    {
        foreach (['admin', 'supervisor'] as $role) {
            $html = $this->actingAs($this->userAs($role))->get('/complaints')->assertOk()->getContent();

            $this->assertStringContainsString('>Dashboard</a>', $html);
            $this->assertStringContainsString('/operasional', $html);
        }
    }

    public function test_kasir_dan_customer_care_juga_tidak_bisa_menandai_alarm(): void
    {
        $this->complaintMenganggur();

        foreach (['kasir', 'customer_care'] as $role) {
            $this->actingAs($this->userAs($role))
                ->post('/operasional/alarm/complaint.belum_dipegang/tangani')
                ->assertForbidden();
        }
    }

    public function test_tamu_dialihkan_ke_login(): void
    {
        $this->get('/operasional')->assertRedirect('/login');
    }

    /* ---------- Kriteria 5 ---------- */

    public function test_alarm_hanya_menghitung_complaint_dalam_cakupan_pembacanya(): void
    {
        $kemang = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $milikKemang = $this->complaintMenganggur($kemang);
        $milikTebet = $this->complaintMenganggur($tebet);

        $kasirKemang = $this->userAs('kasir', $kemang);

        // Lewat alarmnya sendiri, bukan lewat halaman: yang diuji di sini
        // apakah alarm mengambil datanya lewat Complaint::visibleTo. Kalau ia
        // menulis kueri sendiri, kebocorannya tidak akan terlihat di halaman
        // complaint yang tetap benar.
        $nyala = (new ComplaintBelumDipegang)->periksa(new Lingkup($kasirKemang));

        $this->assertNotNull($nyala);
        $this->assertSame(1, $nyala->jumlah);
        $this->assertSame([$milikKemang->ticket_number], collect($nyala->daftar)->pluck('tiket')->all());

        $admin = $this->userAs('admin');
        $nyalaAdmin = (new ComplaintBelumDipegang)->periksa(new Lingkup($admin));

        $this->assertNotNull($nyalaAdmin);
        $this->assertSame(2, $nyalaAdmin->jumlah);
        $this->assertContains($milikTebet->ticket_number, collect($nyalaAdmin->daftar)->pluck('tiket')->all());
    }

    public function test_kasir_yang_diberi_wewenang_dashboard_hanya_melihat_outletnya(): void
    {
        // Keadaan yang diuji di sini BELUM ADA hari ini: kasir tidak punya
        // `dashboard.view`. Yang dijaga adalah kalau satrio kelak memberikannya
        // lewat halaman peran, cakupan outletnya sudah berlaku — bukan lubang
        // yang baru ditemukan setelah wewenangnya dinyalakan.
        config(['complaint.dashboard_roles' => ['admin', 'supervisor', 'kasir']]);

        $kemang = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $milikKemang = $this->complaintMenganggur($kemang);
        $milikTebet = $this->complaintMenganggur($tebet);

        $kasir = $this->userAs('kasir', $kemang);

        $this->actingAs($kasir)->get('/operasional')
            ->assertOk()
            ->assertSee($milikKemang->ticket_number)
            ->assertDontSee($milikTebet->ticket_number)
            // Nama outlet lain pun tidak muncul: jumlah outlet jaringan itu
            // sendiri informasi.
            ->assertDontSee('Tebet');
    }

    public function test_permintaan_langsung_dengan_outlet_lain_ditolak_bukan_dikosongkan(): void
    {
        config(['complaint.dashboard_roles' => ['admin', 'supervisor', 'kasir']]);

        $kemang = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $this->complaintMenganggur($tebet);

        $kasir = $this->userAs('kasir', $kemang);

        // Ditolak, bukan diam-diam dikembalikan "semua outlet": permintaan yang
        // dibiarkan lewat memperlihatkan lebih banyak daripada yang diminta,
        // tanpa satu pun tanda bahwa saringannya diabaikan.
        $this->actingAs($kasir)->get('/operasional?outlet='.$tebet->id)->assertForbidden();

        // Outlet yang tidak ada dijawab SAMA — selisih 403 dan 422 sudah cukup
        // untuk memetakan jaringan dengan mencoba id satu per satu.
        $this->actingAs($kasir)->get('/operasional?outlet=99999')->assertForbidden();

        // Outletnya sendiri tetap boleh.
        $this->actingAs($kasir)->get('/operasional?outlet='.$kemang->id)->assertOk();
    }

    public function test_saringan_outlet_menyempitkan_papan_untuk_yang_melihat_semua_outlet(): void
    {
        $kemang = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $milikKemang = $this->complaintMenganggur($kemang);
        $milikTebet = $this->complaintMenganggur($tebet);

        $admin = $this->userAs('admin');

        $this->actingAs($admin)->get('/operasional?outlet='.$kemang->id)
            ->assertOk()
            ->assertSee($milikKemang->ticket_number)
            ->assertDontSee($milikTebet->ticket_number);
    }
}

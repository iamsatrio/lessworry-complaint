<?php

namespace Tests\Feature;

use App\Alarms\ComplaintBelumDipegang;
use App\Alarms\ComplaintLewatSla;
use App\Alarms\Lingkup;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua alarm complaint: keadaan yang menyalakannya, dan keadaan yang TIDAK.
 * (API-72 bagian 2)
 *
 * Yang paling dijaga di sini kriteria 4 — tiket yang dijeda "Menunggu
 * Pelanggan" tidak boleh memicu alarm SLA. Jedanya menghentikan jam SLA
 * (API-18 #6), jadi alarm yang mengabaikannya menyalakan tiket yang bolanya
 * sedang di pelanggan, dan papan yang merah karena alasan salah akan berhenti
 * dibaca.
 */
class AlarmComplaintTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);
    }

    /**
     * Complaint dengan umur yang ditentukan. SLA-nya dihitung dari created_at,
     * jadi urutan di sini penting: tanggalnya dulu, applySla() sesudahnya.
     */
    private function complaint(array $atribut = [], ?int $umurJam = null): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ], array_intersect_key($atribut, array_flip(['outlet_id', 'bobot', 'assigned_to']))));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = $atribut['status'] ?? 'open';
        $complaint->created_at = now()->subHours($umurJam ?? 24);
        $complaint->applySla();

        foreach (['paused_at', 'pause_reason', 'resolved_at', 'due_resolution_at'] as $kolom) {
            if (array_key_exists($kolom, $atribut)) {
                $complaint->{$kolom} = $atribut[$kolom];
            }
        }

        $complaint->save();

        return $complaint;
    }

    private function lingkup(?User $user = null): Lingkup
    {
        return new Lingkup($user ?? $this->admin());
    }

    /* ---------- Alarm A: belum dipegang ---------- */

    public function test_complaint_open_tanpa_pemilik_menyalakan_alarm(): void
    {
        $this->complaint(umurJam: 6);

        $nyala = (new ComplaintBelumDipegang)->periksa($this->lingkup());

        $this->assertNotNull($nyala);
        $this->assertSame(1, $nyala->jumlah);
        $this->assertStringContainsString('tanpa pemilik', $nyala->ringkasan);
    }

    public function test_complaint_yang_baru_masuk_belum_menyalakan_alarm(): void
    {
        // Ambangnya 4 jam. Keluhan yang baru dicatat satu jam lalu masih
        // dikerjakan orang yang mencatatnya.
        $this->complaint(umurJam: 1);

        $this->assertNull((new ComplaintBelumDipegang)->periksa($this->lingkup()));
    }

    public function test_ambang_umur_bisa_disetel(): void
    {
        $this->complaint(umurJam: 3);

        config(['complaint.alarms.belum_dipegang_jam' => 2]);

        $this->assertNotNull((new ComplaintBelumDipegang)->periksa($this->lingkup()));
        $this->assertSame(2, ComplaintBelumDipegang::ambangJam());
    }

    public function test_complaint_yang_sudah_ada_pemiliknya_tidak_menyalakan_alarm(): void
    {
        $petugas = $this->admin();
        $this->complaint(['assigned_to' => $petugas->id], umurJam: 30);

        $this->assertNull((new ComplaintBelumDipegang)->periksa($this->lingkup()));
    }

    public function test_complaint_handling_tidak_masuk_alarm_belum_dipegang(): void
    {
        // `handling` berarti sudah ada yang menyentuhnya. Alarm ini mencari yang
        // belum tersentuh sama sekali, dan `open_statuses` memuat keduanya.
        $this->complaint(['status' => 'handling'], umurJam: 30);

        $this->assertNull((new ComplaintBelumDipegang)->periksa($this->lingkup()));
    }

    public function test_alarm_menyebut_yang_terlama_dan_sebaran_per_outlet(): void
    {
        $kemang = Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
        $tebet = Outlet::create(['name' => 'Tebet', 'nevira_outlet_id' => '118']);

        $this->complaint(['outlet_id' => $kemang->id], umurJam: 10);
        $this->complaint(['outlet_id' => $kemang->id], umurJam: 50);
        $this->complaint(['outlet_id' => $tebet->id], umurJam: 6);

        $nyala = (new ComplaintBelumDipegang)->periksa($this->lingkup());

        $this->assertNotNull($nyala);
        $this->assertSame(3, $nyala->jumlah);

        // Terburuk lebih dulu: yang paling lama menganggur ada di baris pertama.
        $this->assertSame('Kemang', $nyala->daftar[0]['outlet']);
        $this->assertSame('2 hari 2 jam', $nyala->daftar[0]['umur']);

        // Terbanyak lebih dulu, dan hanya outlet yang benar-benar muncul.
        $this->assertSame(
            [['outlet' => 'Kemang', 'jumlah' => 2], ['outlet' => 'Tebet', 'jumlah' => 1]],
            $nyala->perOutlet
        );
    }

    /* ---------- Alarm B: lewat SLA ---------- */

    public function test_tiket_lewat_tenggat_menyalakan_alarm_sla(): void
    {
        // Bobot sedang = 3 hari; umur 10 hari berarti telat 7 hari.
        $this->complaint(['status' => 'handling'], umurJam: 240);

        $nyala = (new ComplaintLewatSla)->periksa($this->lingkup());

        $this->assertNotNull($nyala);
        $this->assertSame(1, $nyala->jumlah);
        $this->assertSame('7 hari', $nyala->daftar[0]['umur']);
    }

    public function test_tiket_masih_dalam_tenggat_tidak_menyalakan_alarm_sla(): void
    {
        $this->complaint(['status' => 'handling'], umurJam: 24);

        $this->assertNull((new ComplaintLewatSla)->periksa($this->lingkup()));
    }

    public function test_tiket_yang_dijeda_menunggu_pelanggan_tidak_menyalakan_alarm_sla(): void
    {
        // Kriteria 4. Tiketnya lewat tenggat DAN masih terbuka — satu-satunya
        // yang membedakannya dari tiket di test sebelumnya adalah jedanya.
        $complaint = $this->complaint([
            'status' => 'handling',
            'paused_at' => now()->subDay(),
            'pause_reason' => 'menunggu_pelanggan',
        ], umurJam: 240);

        $this->assertTrue($complaint->isPaused());
        $this->assertFalse($complaint->isOverdue(), 'model sendiri tidak menganggapnya lewat tenggat');
        $this->assertNull((new ComplaintLewatSla)->periksa($this->lingkup()),
            'alarm SLA tidak boleh menyala untuk tiket yang bolanya sedang di pelanggan');
    }

    public function test_tiket_yang_dilanjutkan_kembali_menyalakan_alarm_sla(): void
    {
        $complaint = $this->complaint([
            'status' => 'handling',
            'paused_at' => now()->subMinutes(30),
            'pause_reason' => 'menunggu_pelanggan',
        ], umurJam: 240);

        $this->assertNull((new ComplaintLewatSla)->periksa($this->lingkup()));

        $complaint->resume();
        $complaint->save();

        $this->assertNotNull((new ComplaintLewatSla)->periksa($this->lingkup()),
            'jeda 30 menit hanya memundurkan tenggat 30 menit — tiketnya tetap telat berhari-hari');
    }

    public function test_tiket_yang_sudah_ditutup_tidak_menyalakan_alarm_sla(): void
    {
        $this->complaint(['status' => 'close', 'resolved_at' => now()], umurJam: 240);

        $this->assertNull((new ComplaintLewatSla)->periksa($this->lingkup()));
    }

    /**
     * Kueri alarm dan Complaint::isOverdue() harus sepakat tiket demi tiket.
     *
     * Alarm menyaring di SQL — lima ratus baris tidak boleh ditarik ke memori
     * tiap pagi — sementara papan kerja dan laporan memakai method modelnya.
     * Dua tempat yang menjawab "lewat tenggat" dengan caranya sendiri akan
     * berpisah diam-diam, dan yang salah adalah yang tidak dilihat orang.
     */
    public function test_kueri_alarm_sla_sepakat_dengan_is_overdue(): void
    {
        $this->complaint(['status' => 'handling'], umurJam: 240);                 // telat
        $this->complaint(['status' => 'open'], umurJam: 240);                     // telat
        $this->complaint(['status' => 'handling'], umurJam: 24);                  // belum telat
        $this->complaint(['status' => 'close', 'resolved_at' => now()], umurJam: 240);
        $this->complaint([                                                        // telat tapi dijeda
            'status' => 'handling', 'paused_at' => now(), 'pause_reason' => 'menunggu_pelanggan',
        ], umurJam: 240);
        $this->complaint(['status' => 'handling', 'due_resolution_at' => null], umurJam: 240);

        $menurutModel = Complaint::all()->filter->isOverdue()->pluck('ticket_number')->sort()->values()->all();

        $nyala = (new ComplaintLewatSla)->periksa($this->lingkup());

        $this->assertNotNull($nyala);
        $this->assertCount($nyala->jumlah, $menurutModel);
        $this->assertSame(
            $menurutModel,
            collect($nyala->daftar)->pluck('tiket')->sort()->values()->all(),
        );
    }
}

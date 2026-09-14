<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pemilih rentang tanggal halaman Laporan. (API-62 nomor 5)
 *
 * Yang paling berharga di contoh yang dilampirkan bukan kalendernya melainkan
 * pintasannya: orang membuka laporan untuk menjawab "bagaimana bulan ini", dan
 * sebelum ini itu menuntut mengetik dua tanggal.
 *
 * Tanpa satu pun paket JavaScript kalender. Antarmuka aplikasi ini belum
 * memuat satu pun paket JavaScript, dan sederet pintasan bukan alasan yang
 * cukup untuk memulainya — pintasannya tautan GET biasa.
 */
class PintasanTanggalTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $waktu, ?Outlet $outlet = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ]);

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    public function test_kedelapan_pintasan_tersedia(): void
    {
        $response = $this->actingAs($this->userAs('supervisor'))->get('/reports')->assertOk();

        foreach ([
            'Hari Ini', 'Kemarin', 'Minggu Ini', '7 Hari Terakhir',
            'Minggu Lalu', 'Bulan Ini', 'Bulan Lalu', 'Semua',
        ] as $nama) {
            $response->assertSee($nama);
        }
    }

    public function test_pintasan_bulan_ini_benar_benar_memilih_bulan_ini(): void
    {
        Carbon::setTestNow('2026-09-10 14:00');

        $awal = Carbon::parse('2026-09-01');
        $outlet = Outlet::create(['name' => 'Outlet A']);

        $this->complaint('2026-09-05 09:00', $outlet);
        $this->complaint('2026-08-05 09:00', $outlet);

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from='.$awal->format('Y-m-d').'&to=2026-09-10')
            ->assertOk();

        $response->assertSee('1 complaint masuk');
        // Pintasan yang sedang aktif ditandai, bukan cuma tersedia.
        $response->assertSee('aria-current="true"', false);

        Carbon::setTestNow();
    }

    /**
     * Tanggalnya ditulis utuh ke dalam tautannya, bukan disimpan sebagai kata
     * kunci seperti `?rentang=bulan_ini`. Tautan yang menyebut tanggalnya
     * sendiri tetap berarti sama kalau disalin ke orang lain atau dibuka
     * besok.
     */
    public function test_tautan_pintasan_memuat_tanggalnya_sendiri(): void
    {
        Carbon::setTestNow('2026-09-10 14:00');

        $html = $this->actingAs($this->userAs('supervisor'))->get('/reports')->assertOk()->getContent();

        $this->assertStringContainsString('from=2026-09-01&amp;to=2026-09-10', $html, 'Bulan Ini');
        $this->assertStringContainsString('from=2026-09-04&amp;to=2026-09-10', $html, '7 Hari Terakhir');
        $this->assertStringContainsString('from=2026-08-01&amp;to=2026-08-31', $html, 'Bulan Lalu');

        Carbon::setTestNow();
    }

    /**
     * "Semua" mencakup seluruh data YANG BOLEH DILIHAT pengguna ini. Kasir
     * mendapat awal sejarah outletnya, bukan awal sejarah jaringan — tanggal
     * complaint pertama outlet lain adalah informasi tentang outlet lain.
     */
    public function test_pintasan_semua_hanya_mencakup_data_yang_boleh_dilihat(): void
    {
        Carbon::setTestNow('2026-09-10 14:00');

        $milikKasir = Outlet::create(['name' => 'Outlet Kasir']);
        $lain = Outlet::create(['name' => 'Outlet Lain']);

        $this->complaint('2024-01-15 09:00', $lain);
        $this->complaint('2026-05-20 09:00', $milikKasir);

        $html = $this->actingAs($this->userAs('kasir', $milikKasir))
            ->get('/reports')->assertOk()->getContent();

        $this->assertStringContainsString('from=2026-05-20', $html);
        $this->assertStringNotContainsString('from=2024-01-15', $html);

        Carbon::setTestNow();
    }

    public function test_pintasan_semua_tidak_memecahkan_halaman_tanpa_data(): void
    {
        $this->actingAs($this->userAs('supervisor'))->get('/reports')->assertOk();
    }

    /* ---------- Rentang terpilih ditulis dengan kata ---------- */

    public function test_rentang_terpilih_ditulis_dengan_kata(): void
    {
        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-08-11&to=2026-09-10')
            ->assertOk();

        $response->assertSee('11 Agustus 2026 – 10 September 2026');
    }

    /** Isian tanggal bebas tetap ada — pintasannya menambah, bukan mengganti. */
    public function test_isian_tanggal_bebas_tetap_ada(): void
    {
        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-08-11&to=2026-09-10')
            ->assertOk()
            ->assertSee('type="date" name="from"', false)
            ->assertSee('type="date" name="to"', false);
    }

    /* ---------- Saringan lain tidak hilang saat pintasan ditekan ---------- */

    public function test_pintasan_mempertahankan_saringan_outlet(): void
    {
        $outlet = Outlet::create(['name' => 'Cipete']);
        $this->complaint('2026-09-05 09:00', $outlet);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?outlet='.$outlet->id.'&satuan=bulanan')
            ->assertOk()->getContent();

        // Menekan sebuah pintasan tidak boleh diam-diam melebarkan cakupan
        // kembali ke seluruh outlet.
        $this->assertStringContainsString('outlet='.$outlet->id, $html);
        $this->assertStringContainsString('satuan=bulanan', $html);
    }

    /* ---------- Hari terakhir rentang ikut terhitung ---------- */

    /**
     * Sebelum perbaikan ini `$sampai` bernilai pukul 00:00 hari itu, jadi
     * `whereBetween` membuang seluruh hari terakhir rentang. Test pintasan
     * yang lain menaruh datanya di tengah rentang dan berhenti sebelum hari
     * terakhirnya — itu sebabnya delapan pintasan hijau sementara lima di
     * antaranya mengembalikan nol di layar. (Tinjauan Maldini PR #27, API-56)
     */
    public function test_complaint_pada_hari_terakhir_rentang_ikut_terhitung(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 14:00:00'));

        $this->complaint('2026-09-10 13:00:00');

        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertViewHas('total', 1)
            ->assertDontSee('Tidak ada data pada periode ini');

        Carbon::setTestNow();
    }

    /**
     * Kasus paling ekstremnya: satu hari yang sama untuk "dari" dan "sampai".
     * Rentangnya dulu `[00:00, 00:00]`, jadi hanya complaint yang masuk tepat
     * tengah malam yang terhitung.
     */
    public function test_pintasan_hari_ini_menampilkan_complaint_hari_ini(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 16:00:00'));

        $this->complaint('2026-09-10 09:00:00');
        $this->complaint('2026-09-10 13:00:00');
        // Tepat tengah malam: satu-satunya yang terhitung sebelum perbaikan.
        $this->complaint('2026-09-10 00:00:00');
        $this->complaint('2026-09-10 23:59:00');
        // Batas bawahnya tetap batas: kemarin tidak boleh ikut terseret masuk.
        $this->complaint('2026-09-09 23:00:00');

        $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertViewHas('total', 4);

        Carbon::setTestNow();
    }

    /**
     * Ekspor CSV membaca saringan yang sama, tapi lewat baris kode yang
     * berbeda — jadi ia dituntut sendiri, bukan diasumsikan ikut benar.
     */
    public function test_ekspor_csv_memuat_complaint_hari_terakhir_rentang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 14:00:00'));

        $complaint = $this->complaint('2026-09-10 13:00:00');

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export?from=2026-09-10&to=2026-09-10')
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString($complaint->ticket_number, $csv);

        Carbon::setTestNow();
    }

    /**
     * Lima dari delapan pintasan berakhir hari ini. Satu complaint hari ini
     * harus muncul di kelimanya — kalau salah satunya nol, tombolnya berbohong.
     */
    public function test_kelima_pintasan_yang_berakhir_hari_ini_tidak_mengosongkan_hari_ini(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 14:00:00'));

        $this->complaint('2026-09-10 13:00:00');
        $user = $this->userAs('supervisor');

        $pintasan = $this->actingAs($user)->get('/reports')->assertOk()
            ->viewData('pintasan');

        $hariIni = now()->format('Y-m-d');
        $diuji = 0;

        foreach ($pintasan as $p) {
            if ($p['sampai'] !== $hariIni) {
                continue;
            }

            $diuji++;

            $this->actingAs($user)
                ->get('/reports?from='.$p['dari'].'&to='.$p['sampai'])
                ->assertOk()
                ->assertViewHas('total', 1);
        }

        $this->assertSame(5, $diuji, 'Lima pintasan berakhir hari ini: Hari Ini, Minggu Ini, 7 Hari Terakhir, Bulan Ini, Semua.');

        Carbon::setTestNow();
    }

    /* ---------- Tanpa paket kalender pihak ketiga ---------- */

    public function test_tidak_ada_paket_kalender_javascript_yang_ditambahkan(): void
    {
        $paket = json_decode((string) file_get_contents(base_path('package.json')), true);
        $terpasang = array_keys(array_merge(
            $paket['dependencies'] ?? [],
            $paket['devDependencies'] ?? [],
            $paket['optionalDependencies'] ?? [],
        ));

        foreach ([
            'flatpickr', 'litepicker', 'daterangepicker', 'air-datepicker',
            'react-datepicker', 'vanillajs-datepicker', 'pikaday', 'easepick',
            '@easepick/core', 'duet-date-picker', 'vanilla-calendar-pro',
        ] as $pustaka) {
            $this->assertNotContains($pustaka, $terpasang,
                'Pintasan tanggal dibuat dari tautan GET biasa, bukan dari paket kalender.');
        }
    }

    public function test_pintasannya_tautan_biasa_bukan_skrip(): void
    {
        $html = $this->actingAs($this->userAs('supervisor'))->get('/reports')->assertOk()->getContent();

        // Tiap pintasan sebuah <a href>: bekerja tanpa satu baris skrip, dan
        // tetap bekerja di perangkat outlet yang skripnya diblokir.
        $this->assertMatchesRegularExpression('/<a class="chip[^"]*"\s+href="[^"]*from=/', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Alarms\Lingkup;
use App\Alarms\TagihanJatuhTempo;
use App\Models\Outlet;
use App\Models\PembayaranTagihan;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Siapa boleh melihat tagihan, dan siapa boleh mengubahnya.
 * (API-73 kriteria 1, 6, dan 7)
 *
 * Dua sumbu yang sengaja dipisah, dan keduanya ditegakkan DI SISI SERVER:
 *
 * 1. `dashboard.view` — membaca daftar dan alarmnya.
 * 2. `dashboard.manage_tagihan` — menambah, mengubah, menonaktifkan, dan
 *    menandai dibayar. Lebih sempit, karena nominalnya menyangkut keuangan
 *    jaringan dan penandaannya memadamkan satu-satunya pengingat yang ada.
 *
 * Yang diuji bukan tombolnya hilang. Tombol yang hilang bukan wewenang — ia
 * hanya membuat kebocoran lebih sulit ditemukan. Yang diuji permintaan
 * langsung ke servernya ditolak.
 */
class TagihanWewenangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function tagihan(array $atribut = []): Tagihan
    {
        return Tagihan::create(array_merge([
            'nama' => 'Tagihan '.uniqid(),
            'jatuh_tempo_hari' => 5,
            'pengulangan' => 'bulanan',
        ], $atribut));
    }

    private function isian(array $ganti = []): array
    {
        return array_merge([
            'nama' => 'Sewa Kelapa Gading',
            'jumlah' => 12000000,
            'pengulangan' => 'bulanan',
            'jatuh_tempo_hari' => 5,
            'outlet_id' => '',
        ], $ganti);
    }

    /* ---------- Kriteria 6: melihat boleh, mengubah ditolak ---------- */

    public function test_supervisor_melihat_alarm_tagihan_tapi_ditolak_mengubahnya(): void
    {
        $supervisor = $this->userAs('supervisor');
        $tagihan = $this->tagihan(['nama' => 'Internet Tebet', 'jatuh_tempo_hari' => 3]);

        // Melihat: boleh. Papan alarm dan daftar tagihannya dua-duanya terbuka.
        $this->actingAs($supervisor)->get('/operasional')->assertOk()->assertSee('Internet Tebet');
        $this->actingAs($supervisor)->get('/tagihan')->assertOk()->assertSee('Internet Tebet');

        // Mengubah: ditolak server, bukan disembunyikan di tampilan.
        $this->actingAs($supervisor)->get('/tagihan/baru')->assertForbidden();
        $this->actingAs($supervisor)->post('/tagihan', $this->isian())->assertForbidden();
        $this->actingAs($supervisor)->get('/tagihan/'.$tagihan->id.'/ubah')->assertForbidden();
        $this->actingAs($supervisor)->put('/tagihan/'.$tagihan->id, $this->isian())->assertForbidden();
        $this->actingAs($supervisor)->post('/tagihan/'.$tagihan->id.'/status', ['aktif' => 0])->assertForbidden();
        $this->actingAs($supervisor)->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10'])->assertForbidden();
        $this->actingAs($supervisor)->post('/tagihan/ambang', ['ambang_hari' => 10])->assertForbidden();

        $this->assertDatabaseCount('tagihan', 1);
        $this->assertDatabaseCount('pembayaran_tagihan', 0);
        $this->assertTrue($tagihan->fresh()->is_active, 'Tagihan tidak boleh ikut berubah oleh permintaan yang ditolak.');
    }

    public function test_supervisor_tidak_melihat_tombol_kelola(): void
    {
        $this->tagihan(['nama' => 'Internet Tebet']);

        $html = $this->actingAs($this->userAs('supervisor'))->get('/tagihan')->assertOk()->getContent();

        $this->assertStringNotContainsString('Tambah Tagihan', $html);
        $this->assertStringNotContainsString('Tandai dibayar', $html);
    }

    public function test_kasir_dan_customer_care_tidak_bisa_membuka_halaman_tagihan(): void
    {
        foreach (['kasir', 'customer_care', 'divisi'] as $role) {
            $this->actingAs($this->userAs($role))->get('/tagihan')->assertForbidden();
        }
    }

    public function test_kasir_tidak_melihat_menu_tagihan(): void
    {
        $html = $this->actingAs($this->userAs('kasir'))->get('/complaints')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Tagihan</a>', $html);
        $this->assertStringNotContainsString('/tagihan', $html);
    }

    /* ---------- Kriteria 7: cakupan outlet ---------- */

    /**
     * Dijalankan lewat `dashboard_roles` yang diberi peran ber-outlet.
     *
     * Hari ini kasir tidak punya `dashboard.view`, jadi keadaan ini belum ada
     * di produksi. Diuji sekarang justru karena itu: aturannya ditulis hari
     * ini, dan yang tidak diuji saat ditulis akan diuji oleh kebocoran.
     */
    public function test_cakupan_outlet_memisahkan_tagihan_ber_outlet(): void
    {
        config(['complaint.dashboard_roles' => ['admin', 'supervisor', 'kasir']]);

        $kemang = Outlet::create(['name' => 'Kemang']);
        $tebet = Outlet::create(['name' => 'Tebet']);

        $this->tagihan(['nama' => 'Listrik Kemang', 'outlet_id' => $kemang->id, 'jatuh_tempo_hari' => 3]);
        $this->tagihan(['nama' => 'Listrik Tebet', 'outlet_id' => $tebet->id, 'jatuh_tempo_hari' => 3]);
        $this->tagihan(['nama' => 'Langganan NEVIRA', 'jatuh_tempo_hari' => 3]);

        $kasir = $this->userAs('kasir', $kemang);

        $html = $this->actingAs($kasir)->get('/tagihan')->assertOk()->getContent();

        $this->assertStringContainsString('Listrik Kemang', $html);
        $this->assertStringContainsString('Langganan NEVIRA', $html, 'Tagihan jaringan terlihat semua pemegang dashboard.view.');
        $this->assertStringNotContainsString('Listrik Tebet', $html, 'Tagihan outlet lain tidak boleh bocor.');

        // Alarmnya membaca cakupan yang sama, lewat Lingkup — bukan kuerinya sendiri.
        $nyala = (new TagihanJatuhTempo)->periksa(new Lingkup($kasir));

        $this->assertNotNull($nyala);
        $this->assertSame(
            ['Langganan NEVIRA', 'Listrik Kemang'],
            collect($nyala->daftar)->pluck('tiket')->sort()->values()->all()
        );

        // Admin melihat keduanya.
        $nyalaAdmin = (new TagihanJatuhTempo)->periksa(new Lingkup($this->userAs('admin')));
        $this->assertSame(3, $nyalaAdmin->jumlah);
    }

    public function test_pengelola_ber_outlet_tidak_bisa_menyentuh_tagihan_outlet_lain(): void
    {
        config([
            'complaint.dashboard_roles' => ['admin', 'kasir'],
            'complaint.tagihan_roles' => ['admin', 'kasir'],
        ]);

        $kemang = Outlet::create(['name' => 'Kemang']);
        $tebet = Outlet::create(['name' => 'Tebet']);

        $milikTebet = $this->tagihan(['nama' => 'Listrik Tebet', 'outlet_id' => $tebet->id]);
        $kasir = $this->userAs('kasir', $kemang);

        // Tidak terlihat dan tidak boleh dijawab berbeda — 404, bukan 403.
        $this->actingAs($kasir)->get('/tagihan/'.$milikTebet->id.'/ubah')->assertNotFound();
        $this->actingAs($kasir)->put('/tagihan/'.$milikTebet->id, $this->isian())->assertNotFound();
        $this->actingAs($kasir)->post('/tagihan/'.$milikTebet->id.'/status', ['aktif' => 0])->assertNotFound();
        $this->actingAs($kasir)->post('/tagihan/'.$milikTebet->id.'/bayar', ['periode' => '2026-10'])->assertNotFound();

        $this->assertSame('Listrik Tebet', $milikTebet->fresh()->nama);
        $this->assertTrue($milikTebet->fresh()->is_active);
    }

    public function test_tagihan_outlet_lain_ditolak_saat_dibuat(): void
    {
        config([
            'complaint.dashboard_roles' => ['admin', 'kasir'],
            'complaint.tagihan_roles' => ['admin', 'kasir'],
        ]);

        $kemang = Outlet::create(['name' => 'Kemang']);
        $tebet = Outlet::create(['name' => 'Tebet']);

        $this->actingAs($this->userAs('kasir', $kemang))
            ->post('/tagihan', $this->isian(['outlet_id' => $tebet->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('tagihan', 0);
    }

    /* ---------- Kriteria 1: tambah, ubah, nonaktifkan — tanpa hapus ---------- */

    public function test_admin_bisa_menambah_mengubah_dan_menonaktifkan(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->post('/tagihan', $this->isian())->assertRedirect(route('tagihan.index'));

        $tagihan = Tagihan::query()->firstOrFail();
        $this->assertSame('Sewa Kelapa Gading', $tagihan->nama);
        $this->assertSame(12000000, $tagihan->jumlah);
        $this->assertNull($tagihan->outlet_id, 'Outlet kosong berarti tagihan tingkat jaringan.');
        $this->assertNull($tagihan->jatuh_tempo_bulan, 'Tagihan bulanan tidak menyimpan bulan.');

        $this->actingAs($admin)
            ->put('/tagihan/'.$tagihan->id, $this->isian(['jumlah' => 13000000]))
            ->assertRedirect(route('tagihan.index'));

        $this->assertSame(13000000, $tagihan->fresh()->jumlah);

        $this->actingAs($admin)->post('/tagihan/'.$tagihan->id.'/status', ['aktif' => 0]);
        $this->assertFalse($tagihan->fresh()->is_active);

        $this->actingAs($admin)->post('/tagihan/'.$tagihan->id.'/status', ['aktif' => 1]);
        $this->assertTrue($tagihan->fresh()->is_active);
    }

    /**
     * Tidak ada tombol hapus, dan tidak ada rutenya. (API-73 kriteria 1)
     *
     * Tagihan yang dihapus membawa riwayat pembayarannya ikut hilang, dan
     * "kapan terakhir internet outlet ini dibayar" kehilangan jawabannya tanpa
     * siapa pun sadar ia pernah punya satu.
     */
    public function test_tidak_ada_jalan_menghapus_tagihan(): void
    {
        $admin = $this->userAs('admin');
        $tagihan = $this->tagihan();

        $this->actingAs($admin)->delete('/tagihan/'.$tagihan->id)->assertStatus(405);

        $html = $this->actingAs($admin)->get('/tagihan')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Hapus<', $html);

        $this->assertDatabaseCount('tagihan', 1);
    }

    public function test_jumlah_boleh_kosong(): void
    {
        $this->actingAs($this->userAs('admin'))
            ->post('/tagihan', $this->isian(['nama' => 'Listrik outlet', 'jumlah' => '']))
            ->assertRedirect(route('tagihan.index'));

        $this->assertNull(Tagihan::query()->firstOrFail()->jumlah);
    }

    public function test_tagihan_tahunan_wajib_menyebut_bulannya(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)
            ->post('/tagihan', $this->isian(['pengulangan' => 'tahunan']))
            ->assertSessionHasErrors('jatuh_tempo_bulan');

        $this->actingAs($admin)
            ->post('/tagihan', $this->isian(['pengulangan' => 'tahunan', 'jatuh_tempo_bulan' => 3]))
            ->assertRedirect(route('tagihan.index'));

        $this->assertSame(3, Tagihan::query()->firstOrFail()->jatuh_tempo_bulan);
    }

    public function test_tanggal_di_luar_1_sampai_31_ditolak(): void
    {
        $admin = $this->userAs('admin');

        foreach ([0, 32, -1] as $hari) {
            $this->actingAs($admin)
                ->post('/tagihan', $this->isian(['jatuh_tempo_hari' => $hari]))
                ->assertSessionHasErrors('jatuh_tempo_hari');
        }

        $this->assertDatabaseCount('tagihan', 0);
    }

    /* ---------- Penandaan bayar ---------- */

    public function test_menandai_dibayar_mencatat_siapa_dan_kapan(): void
    {
        $admin = $this->userAs('admin');
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->actingAs($admin)->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10']);

        $penanda = PembayaranTagihan::query()->firstOrFail();
        $this->assertSame('2026-10', $penanda->periode);
        $this->assertSame($admin->id, $penanda->user_id);
        $this->assertNotNull($penanda->ditandai_pada);
    }

    /**
     * Periode yang ditandai ditentukan server. Halaman yang dibuka sejak
     * kemarin menunjuk periode yang sudah lewat, dan menandainya akan
     * memadamkan alarm periode yang salah — terbaca "sudah beres" tanpa satu
     * pun tanda keliru.
     */
    public function test_periode_yang_sudah_basi_ditolak(): void
    {
        $admin = $this->userAs('admin');
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->actingAs($admin)
            ->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-08'])
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('pembayaran_tagihan', 0);
    }

    public function test_penanda_pertama_yang_tercatat(): void
    {
        $satu = $this->userAs('admin');
        $dua = $this->userAs('admin');
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->actingAs($satu)->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10']);
        $this->actingAs($dua)->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10']);

        $this->assertDatabaseCount('pembayaran_tagihan', 1);
        $this->assertSame($satu->id, PembayaranTagihan::query()->firstOrFail()->user_id);
    }

    public function test_penandaan_bisa_dibatalkan_dan_alarmnya_menyala_lagi(): void
    {
        $admin = $this->userAs('admin');
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->actingAs($admin)->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10']);
        $this->assertNull((new TagihanJatuhTempo)->periksa(new Lingkup($admin)));

        $this->actingAs($admin)->delete('/tagihan/'.$tagihan->id.'/bayar/2026-10');

        $this->assertDatabaseCount('pembayaran_tagihan', 0);
        $this->assertNotNull(
            (new TagihanJatuhTempo)->periksa(new Lingkup($admin)),
            'Penandaan yang dibatalkan harus menyalakan alarmnya lagi.'
        );
    }

    public function test_tagihan_nonaktif_tidak_bisa_ditandai_dibayar(): void
    {
        $admin = $this->userAs('admin');
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3, 'is_active' => false]);

        $this->actingAs($admin)
            ->post('/tagihan/'.$tagihan->id.'/bayar', ['periode' => '2026-10'])
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('pembayaran_tagihan', 0);
    }

    /* ---------- Ambang pengingat ---------- */

    public function test_ambang_disimpan_tanpa_menyentuh_kode(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->post('/tagihan/ambang', ['ambang_hari' => 7])
            ->assertRedirect(route('tagihan.index'));

        $this->assertSame(7, Pengaturan::ambilAmbangTagihan());

        $this->actingAs($admin)->post('/tagihan/ambang', ['ambang_hari' => 0])
            ->assertSessionHasErrors('ambang_hari');

        $this->assertSame(7, Pengaturan::ambilAmbangTagihan());
    }

    public function test_nama_tagihan_aktif_tidak_boleh_kembar_di_lingkup_yang_sama(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->post('/tagihan', $this->isian())->assertRedirect(route('tagihan.index'));

        $this->actingAs($admin)->post('/tagihan', $this->isian())->assertSessionHasErrors('nama');

        $this->assertDatabaseCount('tagihan', 1);
    }
}

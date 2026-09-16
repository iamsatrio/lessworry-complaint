<?php

namespace Tests\Feature;

use App\Alarms\Lingkup;
use App\Alarms\Nyala;
use App\Alarms\TagihanJatuhTempo;
use App\Models\Outlet;
use App\Models\PembayaranTagihan;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alarm tagihan jatuh tempo. (API-73 kriteria 2, 4, 5)
 *
 * Yang dijaga di sini tiga hal yang gagal diam-diam kalau salah:
 *
 * 1. Penandaan berlaku PER PERIODE. Membayar Oktober tidak boleh memadamkan
 *    November — dan kegagalannya tidak berbunyi, ia hanya membuat tagihan
 *    November lewat tanpa satu pun pengingat.
 * 2. Yang sudah lewat jatuh tempo TETAP menyala, dan menyebut berapa hari.
 * 3. Tagihan nonaktif tidak menyalakan apa pun, tapi riwayatnya tetap terbaca.
 *
 * Jam dipatok setTestNow di sepanjang berkas ini. Alarm yang benar hari ini
 * dan salah tanggal 31 adalah alarm yang salahnya baru ketahuan tujuh bulan
 * kemudian.
 */
class AlarmTagihanTest extends TestCase
{
    use RefreshDatabase;

    /** 1 Oktober 2026, 10.00 WIB — 03.00 UTC, zona aplikasinya. */
    private const SEKARANG = '2026-10-01 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::SEKARANG);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(?Outlet $outlet = null): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin', 'outlet_id' => $outlet?->id,
        ]);
    }

    /**
     * $dibuat menentukan periode mana yang ikut dihitung: jatuh tempo sebelum
     * tagihannya dicatat tidak pernah menyala. Bawaannya 30 September — cukup
     * baru supaya periode September tidak ikut, jadi test yang menguji "jatuh
     * tempo beberapa hari lagi" tidak tercampur tunggakan bulan lalu.
     */
    private function tagihan(array $atribut = [], string $dibuat = '2026-09-30 00:00:00'): Tagihan
    {
        $tagihan = Tagihan::create(array_merge([
            'nama' => 'Internet '.uniqid(),
            'jumlah' => 500000,
            'jatuh_tempo_hari' => 5,
            'pengulangan' => 'bulanan',
        ], $atribut));

        $tagihan->forceFill(['created_at' => $dibuat])->save();

        return $tagihan->fresh();
    }

    private function periksa(User $user, ?Outlet $outlet = null): ?Nyala
    {
        return (new TagihanJatuhTempo)->periksa(new Lingkup($user, $outlet));
    }

    /* ---------- Menyala dan padam ---------- */

    public function test_menyala_saat_jatuh_tempo_tinggal_beberapa_hari(): void
    {
        // Ambang bawaan 3 hari; hari ini 1 Oktober, jatuh tempo 3 Oktober.
        $this->tagihan(['jatuh_tempo_hari' => 3]);

        $nyala = $this->periksa($this->admin());

        $this->assertNotNull($nyala, 'Tagihan yang jatuh tempo dua hari lagi harus menyala.');
        $this->assertSame(1, $nyala->jumlah);
        $this->assertSame('2 hari lagi', $nyala->daftar[0]['umur']);
    }

    public function test_padam_saat_jatuh_tempo_masih_jauh(): void
    {
        $this->tagihan(['jatuh_tempo_hari' => 25]);

        $this->assertNull(
            $this->periksa($this->admin()),
            'Jatuh tempo 24 hari lagi tidak boleh menyalakan apa pun — papan yang selalu merah berhenti dibaca.'
        );
    }

    public function test_ambang_yang_disetel_pengguna_menggeser_kapan_menyala(): void
    {
        $this->tagihan(['jatuh_tempo_hari' => 10]);

        $this->assertNull($this->periksa($this->admin()), 'Dengan ambang 3 hari, 9 hari lagi masih padam.');

        Pengaturan::simpanAmbangTagihan(14);

        $this->assertNotNull($this->periksa($this->admin()), 'Dengan ambang 14 hari, 9 hari lagi sudah menyala.');
    }

    /* ---------- Kriteria 5: sudah berapa hari terlambat ---------- */

    public function test_lewat_jatuh_tempo_tetap_menyala_dan_menyebut_berapa_hari(): void
    {
        // Jatuh tempo 25 September, hari ini 1 Oktober: telat 6 hari. Periode
        // Oktober-nya belum tiba, jadi yang menyala September.
        $this->tagihan(['jatuh_tempo_hari' => 25], dibuat: '2026-01-01 00:00:00');

        $nyala = $this->periksa($this->admin());

        $this->assertNotNull($nyala);
        $this->assertSame('Telat 6 hari', $nyala->daftar[0]['umur']);
        $this->assertStringContainsString('paling lama telat 6 hari', $nyala->ringkasan);
    }

    public function test_jatuh_tempo_hari_ini_disebut_hari_ini(): void
    {
        $this->tagihan(['jatuh_tempo_hari' => 1]);

        $nyala = $this->periksa($this->admin());

        $this->assertNotNull($nyala);
        $this->assertSame('Hari ini', $nyala->daftar[0]['umur']);
    }

    /* ---------- Kriteria 4: per periode, bukan sekali selamanya ---------- */

    public function test_ditandai_untuk_oktober_memadamkan_oktober_saja(): void
    {
        $user = $this->admin();
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->assertNotNull($this->periksa($user), 'Prasyarat: Oktober menyala.');

        PembayaranTagihan::create([
            'tagihan_id' => $tagihan->id, 'periode' => '2026-10',
            'user_id' => $user->id, 'ditandai_pada' => now(),
        ]);

        $this->assertNull($this->periksa($user), 'Oktober sudah ditandai — alarmnya harus padam.');

        // 1 November: tagihan 3 November jatuh dua hari lagi, dan Oktober yang
        // sudah ditandai tidak boleh ikut membungkamnya.
        Carbon::setTestNow('2026-11-01 03:00:00');

        $nyala = $this->periksa($user);

        $this->assertNotNull($nyala, 'Alarm November harus terbit pada waktunya meski Oktober sudah dibayar.');
        $this->assertSame('2 hari lagi', $nyala->daftar[0]['umur']);
    }

    public function test_periode_lama_yang_belum_ditandai_tetap_terbaca(): void
    {
        $user = $this->admin();
        $tagihan = $this->tagihan(['jatuh_tempo_hari' => 20], dibuat: '2026-01-01 00:00:00');

        // September belum ditandai; Oktober belum tiba. Yang menyala yang
        // sudah tiba, bukan yang terdekat: periode yang lewat tanpa ditandai
        // lebih mendesak daripada jatuh tempo tiga minggu lagi.
        $nyala = $this->periksa($user);

        $this->assertNotNull($nyala);
        $this->assertSame('Telat 11 hari', $nyala->daftar[0]['umur']);

        PembayaranTagihan::create([
            'tagihan_id' => $tagihan->id, 'periode' => '2026-09',
            'user_id' => $user->id, 'ditandai_pada' => now(),
        ]);

        $this->assertNull(
            $this->periksa($user),
            'September ditandai, Oktober masih 19 hari lagi — tidak ada yang perlu menyala.'
        );
    }

    /* ---------- Kriteria 2: nonaktif ---------- */

    public function test_tagihan_nonaktif_tidak_menyalakan_alarm(): void
    {
        $this->tagihan(['jatuh_tempo_hari' => 3, 'is_active' => false]);

        $this->assertNull($this->periksa($this->admin()));
    }

    public function test_riwayat_tagihan_nonaktif_masih_terbaca(): void
    {
        $user = $this->admin();
        $tagihan = $this->tagihan(['nama' => 'Internet Tebet', 'jatuh_tempo_hari' => 3, 'is_active' => false]);

        PembayaranTagihan::create([
            'tagihan_id' => $tagihan->id, 'periode' => '2026-09',
            'user_id' => $user->id, 'ditandai_pada' => now(),
        ]);

        $html = $this->actingAs($user)->get('/tagihan')->assertOk()->getContent();

        $this->assertStringContainsString('Internet Tebet', $html, 'Tagihan nonaktif tetap tampil di halaman.');
        $this->assertStringContainsString('September 2026', $html, 'Riwayat pembayarannya tetap terbaca.');
    }

    /**
     * Hitungan hari memakai KALENDER OPERASIONAL, bukan jam UTC.
     *
     * Aplikasi berjalan di UTC dan hari UTC berganti pukul 07.00 WIB — di
     * tengah jam kerja. Kalau jatuh tempo dan "hari ini" dibandingkan dengan
     * zona yang berbeda, selisihnya bergeser tujuh jam dan pembulatan ke bawah
     * memakan satu hari: "telat 6 hari" tertulis "telat 5 hari", tiap hari,
     * tanpa satu pun tanda bahwa angkanya meleset.
     */
    public function test_hari_terlambat_tidak_bergeser_di_ujung_hari_wib(): void
    {
        $this->tagihan(['jatuh_tempo_hari' => 25], dibuat: '2026-01-01 00:00:00');

        // 01.00 WIB 1 Oktober — masih hari yang sama bagi orang di outlet.
        Carbon::setTestNow('2026-09-30 18:00:00');
        $this->assertSame('Telat 6 hari', $this->periksa($this->admin())->daftar[0]['umur']);

        // 23.30 WIB 1 Oktober — tetap hari yang sama, meski UTC sudah 16.30.
        Carbon::setTestNow('2026-10-01 16:30:00');
        $this->assertSame('Telat 6 hari', $this->periksa($this->admin())->daftar[0]['umur']);

        // 03.00 WIB 2 Oktober — hari berikutnya, dan angkanya naik satu.
        Carbon::setTestNow('2026-10-01 20:00:00');
        $this->assertSame('Telat 7 hari', $this->periksa($this->admin())->daftar[0]['umur']);
    }

    /* ---------- Tagihan tahunan ---------- */

    public function test_tagihan_tahunan_hanya_menyala_di_bulannya(): void
    {
        $this->tagihan([
            'nama' => 'Perpanjangan domain',
            'pengulangan' => 'tahunan',
            'jatuh_tempo_bulan' => 10,
            'jatuh_tempo_hari' => 3,
        ], dibuat: '2026-09-01 00:00:00');

        $this->assertNotNull($this->periksa($this->admin()), 'Oktober adalah bulannya — dua hari lagi.');

        Carbon::setTestNow('2026-06-01 03:00:00');

        $this->assertNull(
            $this->periksa($this->admin()),
            'Di bulan Juni tagihan tahunan Oktober tidak boleh menyala.'
        );
    }

    /* ---------- Batas keras: NEVIRA tidak disentuh ---------- */

    /**
     * Fitur ini TIDAK memanggil NEVIRA sama sekali — bukan "hanya GET", tapi
     * nol panggilan. Seluruh datanya milik sistem ini sendiri.
     *
     * Ditulis sebagai test, bukan sebagai catatan, karena satu panggilan yang
     * kelak diselipkan ke halaman ini akan lolos tinjauan tanpa berbunyi. Dan
     * jebakan yang sudah terbukti di API-65 membuat diamnya mahal:
     * `/transfer-order-trx/statistics` dengan format tanggal `DD-MM-YYYY`
     * membalas 200 dengan nol, bukan galat — halaman akan melaporkan angka
     * yang keliru dan terlihat wajar.
     */
    public function test_halaman_dan_alarm_tagihan_tidak_memanggil_nevira(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $user = $this->admin();
        $this->tagihan(['jatuh_tempo_hari' => 3]);

        $this->periksa($user);
        $this->actingAs($user)->get('/tagihan')->assertOk();
        $this->actingAs($user)->get('/operasional')->assertOk();

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Services\NomorTelepon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * API-26 Bagian 2 — cari nota lewat nomor telepon pelanggan.
 *
 * Bentuk data yang dipalsukan di sini bukan karangan: strukturnya diambil
 * dari respons nyata GET /api/transactions?keyword= pada 2026-08-31,
 * termasuk kenyataan bahwa NEVIRA menyimpan nomor yang sama dalam bentuk
 * 62…, 8…, dan 08… sekaligus.
 */
class PhoneNotaSearchTest extends TestCase
{
    use RefreshDatabase;

    /** Nomor pelanggan yang dicari, dalam bentuk yang diketik kasir. */
    private const TELEPON_DIKETIK = '081556611704';

    /** Bentuk yang tersimpan di NEVIRA untuk nomor yang sama. */
    private const TELEPON_NEVIRA = '6281556611704';

    private const TELEPON_LAIN = '6281200009999';

    private const OUTLET_NEVIRA = '118';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
    }

    private function outlet(string $name = 'Tebet', ?string $neviraId = self::OUTLET_NEVIRA): Outlet
    {
        return Outlet::create(['name' => $name, 'nevira_outlet_id' => $neviraId]);
    }

    private function userAs(string $role, ?Outlet $outlet = null, ?string $division = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $outlet?->id, 'division' => $division,
        ]);
    }

    /** Satu baris hasil pencarian, sebentuk dengan respons NEVIRA. */
    private function baris(
        string $invoice,
        string $telepon,
        string $tanggal,
        int $total = 78000,
        string $outlet = self::OUTLET_NEVIRA,
    ): array {
        return [
            'id_transaction'     => crc32($invoice),
            'transaction_number' => $invoice,
            'id_outlet'          => (int) $outlet,
            'outlet_name'        => 'Tebet',
            'grand_total'        => $total,
            'status'             => 'COMPLETED',
            'created_at'         => $tanggal,
            'customer'           => [
                'id_customer'   => 58986,
                'customer_name' => 'Pelanggan Uji',
                'phone'         => $telepon,
            ],
            'services' => [[
                'service_name' => 'Kiloan - Cuci Setrika (3 Hari)',
                'service'      => ['service_name' => 'Kiloan - Cuci Setrika (3 Hari)'],
            ]],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function fake(array $rows): void
    {
        Http::fake([
            '*/login'        => Http::response(['access_token' => 'tok'], 200),
            '*/transactions*' => Http::response(['data' => $rows, 'total' => count($rows)], 200),
        ]);
    }

    private function tigaNota(): array
    {
        return [
            $this->baris('INV/118/1788092637001/1', self::TELEPON_NEVIRA, '2026-08-30 19:23:57', 54600),
            $this->baris('INV/118/1787749345365/1', self::TELEPON_NEVIRA, '2026-08-26 20:02:25', 78000),
            $this->baris('INV/118/1786969602598/1', self::TELEPON_NEVIRA, '2026-08-17 19:26:43', 51428),
        ];
    }

    /* ---------- Kriteria selesai nomor 6 ---------- */

    public function test_tiga_nota_pelanggan_tampil_semua(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)
            ->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $response->assertOk()->assertJson(['ok' => true, 'lebih' => false]);
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(
            ['INV/118/1788092637001/1', 'INV/118/1787749345365/1', 'INV/118/1786969602598/1'],
            array_column($response->json('data'), 'invoice'),
        );
    }

    public function test_baris_hasil_membawa_yang_dibutuhkan_untuk_memilih(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $baris = $this->actingAs($kasir)
            ->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK)
            ->json('data.0');

        $this->assertSame('INV/118/1788092637001/1', $baris['invoice']);
        $this->assertSame('2026-08-30 19:23:57', $baris['created_at']);
        $this->assertSame(54600, $baris['grand_total']);
        $this->assertSame(['Kiloan - Cuci Setrika (3 Hari)'], $baris['layanan']);
    }

    /**
     * Kasir yang diketiknya `0815…` sementara NEVIRA menyimpan `62815…`
     * dulu mendapat nol hasil — dan nol hasil terbaca seperti "pelanggan ini
     * tidak pernah transaksi", bukan seperti bentuk nomor yang berbeda.
     */
    public function test_bentuk_nomor_apa_pun_menemukan_pelanggan_yang_sama(): void
    {
        $kasir = $this->userAs('kasir', $this->outlet());

        foreach (['081556611704', '6281556611704', '81556611704', '0815-5661-1704', '+62 815 5661 1704'] as $bentuk) {
            $this->fake($this->tigaNota());

            $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.urlencode($bentuk));

            $response->assertOk()->assertJson(['ok' => true]);
            $this->assertCount(3, $response->json('data'), 'bentuk "'.$bentuk.'" tidak menemukan nota');
        }
    }

    /* ---------- Kotak pencarian bukan alat menyisir data pelanggan ---------- */

    public function test_nota_milik_pelanggan_lain_dibuang_walau_ikut_terbawa(): void
    {
        // Pencarian NEVIRA mencocokkan SEBAGIAN, jadi nomor pelanggan lain
        // yang memuat potongan yang sama ikut terbawa dalam respons.
        $this->fake(array_merge($this->tigaNota(), [
            $this->baris('INV/118/1780000000001/1', self::TELEPON_LAIN, '2026-08-29 10:00:00'),
        ]));

        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $this->assertCount(3, $response->json('data'));
        $response->assertDontSee('INV/118/1780000000001/1');
    }

    public function test_potongan_nomor_ditolak_tanpa_memanggil_nevira(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone=0815');

        $response->assertOk()->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    public function test_hasil_tidak_membawa_identitas_pelanggan(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $response->assertDontSee('Pelanggan Uji');
        // Id internal transaksi tidak pernah dikirim ke browser, sama seperti
        // pada jalur nota.
        $response->assertDontSee('id_transaction');
    }

    /* ---------- Lingkup outlet berlaku sama seperti jalur nota ---------- */

    public function test_kasir_tidak_melihat_nota_outlet_lain(): void
    {
        $this->fake([
            $this->baris('INV/118/1788092637001/1', self::TELEPON_NEVIRA, '2026-08-30 19:23:57'),
            $this->baris('INV/999/1788092637002/1', self::TELEPON_NEVIRA, '2026-08-31 19:23:57', 60000, '999'),
        ]);

        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $this->assertCount(1, $response->json('data'));
        $response->assertDontSee('INV/999/1788092637002/1');
    }

    public function test_customer_care_melihat_seluruh_outlet(): void
    {
        $this->fake([
            $this->baris('INV/118/1788092637001/1', self::TELEPON_NEVIRA, '2026-08-30 19:23:57'),
            $this->baris('INV/999/1788092637002/1', self::TELEPON_NEVIRA, '2026-08-31 19:23:57', 60000, '999'),
        ]);

        $cc = $this->userAs('customer_care');

        $response = $this->actingAs($cc)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_kasir_yang_outletnya_belum_dipetakan_ditolak_dengan_alasan(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet('Belum Dipetakan', null));

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('belum dipetakan', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_divisi_ditolak(): void
    {
        $this->fake($this->tigaNota());
        $divisi = $this->userAs('divisi', null, 'produksi');

        $this->actingAs($divisi)
            ->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK)
            ->assertForbidden();
    }

    public function test_tamu_ditolak(): void
    {
        $this->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK)->assertUnauthorized();
    }

    /* ---------- Batas laju dipakai bersama dengan jalur nota ---------- */

    public function test_batas_laju_dipakai_bersama_dengan_lookup(): void
    {
        $this->fake($this->tigaNota());
        $cc = $this->userAs('customer_care');

        RateLimiter::clear('nevira:'.$cc->id);
        for ($i = 0; $i < \App\Services\NeviraGate::PER_MINUTE; $i++) {
            RateLimiter::hit('nevira:'.$cc->id, 60);
        }

        $response = $this->actingAs($cc)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertCount(0, (array) $response->json('data'));
    }

    /* ---------- Lebih dari lima nota ---------- */

    public function test_lebih_dari_lima_nota_dipotong_dan_ditandai(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = $this->baris('INV/118/17880926370'.$i.'/1', self::TELEPON_NEVIRA, '2026-08-0'.$i.' 10:00:00');
        }
        $this->fake($rows);

        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $this->assertCount(5, $response->json('data'));
        $this->assertTrue($response->json('lebih'));
    }

    public function test_rentang_tanggal_diteruskan_ke_nevira(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)->getJson(
            '/nevira/cari-nota?phone='.self::TELEPON_DIKETIK.'&from=2026-08-01&to=2026-08-10'
        )->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions')
            && str_contains($request->url(), 'start_date=2026-08-01')
            && str_contains($request->url(), 'end_date=2026-08-10'));
    }

    /**
     * Satu tanggal saja membuat NEVIRA mengembalikan nol baris — dan nol
     * baris terbaca seperti "tidak punya nota". Ditolak di validasi, bukan
     * diteruskan.
     */
    public function test_satu_tanggal_saja_ditolak(): void
    {
        $this->fake($this->tigaNota());
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)
            ->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK.'&from=2026-08-01')
            ->assertStatus(422);
    }

    public function test_tidak_ada_nota_dijawab_dengan_saran_mengetik_nota(): void
    {
        $this->fake([]);
        $kasir = $this->userAs('kasir', $this->outlet());

        $response = $this->actingAs($kasir)->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('nomor notanya langsung', (string) $response->json('message'));
    }

    public function test_nevira_belum_dikonfigurasi_tidak_membuat_halaman_gagal(): void
    {
        config(['nevira.enabled' => false]);
        $kasir = $this->userAs('kasir', $this->outlet());

        $this->actingAs($kasir)
            ->getJson('/nevira/cari-nota?phone='.self::TELEPON_DIKETIK)
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    /* ---------- Penyamaan bentuk nomor ---------- */

    public function test_inti_nomor(): void
    {
        $this->assertSame('81556611704', NomorTelepon::inti('081556611704'));
        $this->assertSame('81556611704', NomorTelepon::inti('6281556611704'));
        $this->assertSame('81556611704', NomorTelepon::inti('+62 815-5661-1704'));
        $this->assertSame('81556611704', NomorTelepon::inti('81556611704'));

        // Terlalu pendek, bukan ponsel, atau kosong — jangan panggil NEVIRA.
        $this->assertNull(NomorTelepon::inti('0815'));
        $this->assertNull(NomorTelepon::inti('0211234567'));
        $this->assertNull(NomorTelepon::inti(''));
        $this->assertNull(NomorTelepon::inti(null));
        $this->assertNull(NomorTelepon::inti('bukan nomor'));

        $this->assertTrue(NomorTelepon::sama('081556611704', '6281556611704'));
        $this->assertFalse(NomorTelepon::sama('081556611704', '6281200009999'));
        $this->assertFalse(NomorTelepon::sama(null, '6281200009999'));
    }
}

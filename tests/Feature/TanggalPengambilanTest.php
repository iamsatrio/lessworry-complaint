<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use App\Services\NeviraClient;
use App\Services\TanggalPengambilan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API-48 — tanggal barang diterima pelanggan, dan jarak hari sampai complaint
 * masuk.
 *
 * Bentuk data di sini disalin dari respons NEVIRA yang sungguhan
 * (diperiksa 16 September 2026), bukan dikarang: `service_process_log` dengan
 * `activity_name` diambil_customer/diantar_kurir dan stempel UTC, dan baris
 * pengantaran dengan `initial_status` '1'/'3' berstatus 7.
 */
class TanggalPengambilanTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'INV/118/1787749345365/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
    }

    /* ---------- bahan ---------- */

    private function complaint(array $attrs = []): Complaint
    {
        $c = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Keluhan uji',
        ], array_diff_key($attrs, array_flip(['nevira_snapshot', 'tanggal_pengambilan', 'sumber_tanggal_pengambilan', 'created_at']))));
        $c->status = 'open';
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->created_at = $attrs['created_at'] ?? now();
        $c->applySla();

        foreach (['nevira_snapshot', 'tanggal_pengambilan', 'sumber_tanggal_pengambilan'] as $kolom) {
            if (array_key_exists($kolom, $attrs)) {
                $c->{$kolom} = $attrs[$kolom];
            }
        }

        $c->save();

        return $c;
    }

    /** Satu baris jejak serah terima, bentuknya seperti yang disimpan snapshot. */
    private function jejak(string $activity, string $utc, int $index = 1): array
    {
        return ['service_index' => $index, 'activity' => $activity, 'at' => $utc];
    }

    /** Satu baris pengantaran, bentuknya seperti keluaran summarizeDeliveries(). */
    private function antaran(string $tanggal, int $status, string $initial): array
    {
        return [
            'id' => 1, 'date' => $tanggal, 'status_code' => $status,
            'status' => 'Selesai', 'initial_status' => $initial,
        ];
    }

    private function hitung(Complaint $c): array
    {
        return app(TanggalPengambilan::class)->untuk($c);
    }

    /* ---------- ringkasan dari NEVIRA ---------- */

    public function test_jejak_serah_terima_diambil_dari_log_pengerjaan(): void
    {
        $ringkas = app(NeviraClient::class)->summarizeTransaction(['data' => [
            'transaction_number' => self::NOTA,
            'services' => [
                ['service' => ['service_name' => 'Kiloan'], 'service_process_log' => [
                    ['activity_name' => 'diambil_customer', 'description' => 'Diambil oleh Customer',
                        'performed_by_name' => 'Sheka Julia Rahma',
                        'image_path' => 'https://api.nevira.id/storage/pickup_proof/rahasia.jpg',
                        'created_at' => '2026-09-16T07:34:55.000000Z'],
                    ['activity_name' => 'pengemasan_selesai', 'created_at' => '2026-09-16T00:36:58.000000Z'],
                ]],
            ],
        ]]);

        $this->assertCount(1, $ringkas['handovers']);
        $this->assertSame('diambil_customer', $ringkas['handovers'][0]['activity']);
        $this->assertSame('2026-09-16T07:34:55.000000Z', $ringkas['handovers'][0]['at']);
        $this->assertSame(1, $ringkas['handovers'][0]['service_index']);
    }

    public function test_nama_petugas_dan_foto_bukti_tidak_ikut_tersimpan(): void
    {
        // Fotonya foto serah terima pelanggan, dan snapshot ini dirender ke
        // halaman complaint. Yang tidak disimpan tidak bisa bocor.
        $ringkas = app(NeviraClient::class)->summarizeTransaction(['data' => [
            'services' => [
                ['service_process_log' => [
                    ['activity_name' => 'diambil_customer', 'performed_by_name' => 'Sheka Julia Rahma',
                        'image_path' => 'https://api.nevira.id/storage/pickup_proof/rahasia.jpg',
                        'created_at' => '2026-09-16T07:34:55.000000Z'],
                ]],
            ],
        ]]);

        $json = json_encode($ringkas['handovers']);
        $this->assertStringNotContainsString('Sheka', (string) $json);
        $this->assertStringNotContainsString('pickup_proof', (string) $json);
    }

    public function test_ringkasan_pengantaran_membawa_initial_status(): void
    {
        $ringkas = app(NeviraClient::class)->summarizeDeliveries([
            ['id_deliveries_transaction' => 31591, 'delivery_date' => '2026-06-12',
                'status' => 7, 'initial_status' => '1'],
        ]);

        $this->assertSame('1', $ringkas[0]['initial_status']);
    }

    /* ---------- aturan penentuan tanggal ---------- */

    public function test_diambil_sendiri_dibaca_menurut_jam_outlet_bukan_utc(): void
    {
        // 15 September 23.05 UTC adalah 16 September 06.05 WIB. Tanpa
        // konversi zona, setiap serah terima pagi tercatat mundur sehari.
        $c = $this->complaint(['nevira_snapshot' => [
            'handovers' => [$this->jejak('diambil_customer', '2026-09-15T23:05:00.000000Z')],
        ]]);

        $this->assertSame(
            ['tanggal' => '2026-09-16', 'sumber' => TanggalPengambilan::AMBIL_SENDIRI],
            $this->hitung($c),
        );
    }

    public function test_order_antar_memakai_tanggal_pengantaran_yang_selesai(): void
    {
        $c = $this->complaint(['nevira_snapshot' => [
            'deliveries' => [$this->antaran('2026-06-12', 7, '1')],
        ]]);

        $this->assertSame(
            ['tanggal' => '2026-06-12', 'sumber' => TanggalPengambilan::ANTAR],
            $this->hitung($c),
        );
    }

    public function test_perjalanan_jemput_tidak_pernah_jadi_tanggal_pengambilan(): void
    {
        // Kurir MENJEMPUT cucian kotor. Baris ini juga berakhir status 7,
        // dan membacanya sebagai serah terima mencatat tanggal barang MASUK
        // sebagai tanggal barang DITERIMA pelanggan.
        $c = $this->complaint(['nevira_snapshot' => [
            'deliveries' => [$this->antaran('2026-06-09', 7, '3')],
        ]]);

        $this->assertSame(
            ['tanggal' => null, 'sumber' => TanggalPengambilan::TIDAK_DIKETAHUI],
            $this->hitung($c),
        );
    }

    public function test_pengantaran_yang_belum_selesai_belum_jadi_tanggal(): void
    {
        $c = $this->complaint(['nevira_snapshot' => [
            'deliveries' => [$this->antaran('2026-06-12', 1, '1')],
        ]]);

        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $this->hitung($c)['sumber']);
    }

    public function test_kode_selesai_lama_71_tetap_diterima(): void
    {
        $c = $this->complaint(['nevira_snapshot' => [
            'deliveries' => [$this->antaran('2026-05-02', 71, '1')],
        ]]);

        $this->assertSame('2026-05-02', $this->hitung($c)['tanggal']);
    }

    public function test_stempel_diantar_kurir_tidak_dipakai_sebagai_tanggal(): void
    {
        // Itu saat barang KELUAR outlet, bukan saat pelanggan menerimanya.
        // Pada nota INV/117/1777554019017/2 keduanya terpaut sepuluh hari.
        $c = $this->complaint(['nevira_snapshot' => [
            'handovers' => [$this->jejak('diantar_kurir', '2026-05-07T12:01:40.000000Z')],
            'deliveries' => [],
        ]]);

        $this->assertSame(
            ['tanggal' => null, 'sumber' => TanggalPengambilan::TIDAK_DIKETAHUI],
            $this->hitung($c),
        );
    }

    public function test_completion_date_tidak_pernah_dipakai(): void
    {
        // Kolomnya ada di skema NEVIRA dan tidak pernah diisi: kosong di 953
        // dari 953 transaksi April–September 2026. Kalaupun suatu hari terisi,
        // artinya belum terbukti serah terima — jadi tetap tidak dipakai.
        $c = $this->complaint(['nevira_snapshot' => [
            'completed_at' => '2026-09-01 10:00:00',
        ]]);

        $this->assertSame(
            ['tanggal' => null, 'sumber' => TanggalPengambilan::TIDAK_DIKETAHUI],
            $this->hitung($c),
        );
    }

    public function test_kalau_ada_dua_jalur_yang_lebih_baru_dipakai(): void
    {
        $c = $this->complaint(['nevira_snapshot' => [
            'handovers' => [$this->jejak('diambil_customer', '2026-06-01T05:00:00.000000Z')],
            'deliveries' => [$this->antaran('2026-06-12', 7, '1')],
        ]]);

        $this->assertSame(
            ['tanggal' => '2026-06-12', 'sumber' => TanggalPengambilan::ANTAR],
            $this->hitung($c),
        );
    }

    public function test_jejak_baris_yang_dikeluhkan_yang_dipakai(): void
    {
        // Satu nota, dua barang, diserahkan terpisah. Keluhan menunjuk barang
        // pertama, jadi tanggal barang kedua tidak berlaku untuknya. (API-51)
        $c = $this->complaint([
            'nevira_service_index' => 1,
            'nevira_snapshot' => [
                'handovers' => [
                    $this->jejak('diambil_customer', '2026-06-01T05:00:00.000000Z', index: 1),
                    $this->jejak('diambil_customer', '2026-06-20T05:00:00.000000Z', index: 2),
                ],
            ],
        ]);

        $this->assertSame('2026-06-01', $this->hitung($c)['tanggal']);
    }

    public function test_baris_tanpa_jejak_sendiri_jatuh_ke_seluruh_nota(): void
    {
        $c = $this->complaint([
            'nevira_service_index' => 3,
            'nevira_snapshot' => [
                'handovers' => [$this->jejak('diambil_customer', '2026-06-01T05:00:00.000000Z', index: 1)],
            ],
        ]);

        $this->assertSame('2026-06-01', $this->hitung($c)['tanggal']);
    }

    public function test_tanggal_yang_diketik_orang_tidak_dihapus_sinkron_kosong(): void
    {
        $c = $this->complaint([
            'tanggal_pengambilan' => '2026-04-10',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::MANUAL,
            'nevira_snapshot' => ['handovers' => [], 'deliveries' => []],
        ]);

        $this->assertSame(
            ['tanggal' => '2026-04-10', 'sumber' => TanggalPengambilan::MANUAL],
            $this->hitung($c),
        );
    }

    public function test_jejak_nevira_mengalahkan_tanggal_yang_diketik_orang(): void
    {
        $c = $this->complaint([
            'tanggal_pengambilan' => '2026-04-10',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::MANUAL,
            'nevira_snapshot' => [
                'handovers' => [$this->jejak('diambil_customer', '2026-04-12T05:00:00.000000Z')],
            ],
        ]);

        $this->assertSame(
            ['tanggal' => '2026-04-12', 'sumber' => TanggalPengambilan::AMBIL_SENDIRI],
            $this->hitung($c),
        );
    }

    /* ---------- jarak hari ---------- */

    public function test_jarak_hari_dihitung_dari_tanggal_pengambilan(): void
    {
        $c = $this->complaint([
            'created_at' => Carbon::parse('2026-06-15 02:00:00'),
            'tanggal_pengambilan' => '2026-06-12',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::ANTAR,
        ]);

        $this->assertSame(3, $c->jarakKomplainHari());
    }

    public function test_jarak_hari_null_kalau_tanggalnya_tidak_diketahui(): void
    {
        // null, BUKAN nol: nol berarti "masuk di hari yang sama".
        $this->assertNull($this->complaint()->jarakKomplainHari());
    }

    public function test_jarak_hari_negatif_kalau_complaint_mendahului_pengambilan(): void
    {
        // "Cucian saya belum selesai" memang masuk sebelum barangnya diambil.
        $c = $this->complaint([
            'created_at' => Carbon::parse('2026-06-10 02:00:00'),
            'tanggal_pengambilan' => '2026-06-12',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::ANTAR,
        ]);

        $this->assertSame(-2, $c->jarakKomplainHari());
    }

    public function test_complaint_sore_hari_tetap_hari_yang_sama(): void
    {
        // 2026-06-12 18.00 UTC = 13 Juni 01.00 WIB — tapi complaint yang
        // dicatat kasir pukul satu pagi masih hari kerja yang sama. Yang
        // diuji di sini konsistensi zonanya, bukan jam bukanya.
        $c = $this->complaint([
            'created_at' => Carbon::parse('2026-06-12 10:00:00'),
            'tanggal_pengambilan' => '2026-06-12',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::ANTAR,
        ]);

        $this->assertSame(0, $c->jarakKomplainHari());
    }

    /* ---------- sinkron sungguhan ---------- */

    /** @var array<int,array<string,mixed>> jejak pengerjaan yang dibalas NEVIRA palsu */
    private array $logNevira = [];

    /** @var array<int,array<string,mixed>> baris pengantaran yang dibalas NEVIRA palsu */
    private array $antaranNevira = [];

    /**
     * Balasannya dibaca dari properti, bukan ditanam saat fake dipasang:
     * Http::fake() yang dipanggil dua kali MENAMBAH stub, tidak menggantinya,
     * jadi stub pertama tetap yang menang. Uji tarik ulang butuh jawaban yang
     * berubah di tengah jalan.
     */
    private function fakeNevira(array $log = [], array $deliveries = []): void
    {
        $this->logNevira = $log;
        $this->antaranNevira = $deliveries;

        Http::fake([
            '*/login' => fn () => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/31242' => fn () => Http::response(['data' => [
                'id_transaction' => 31242, 'transaction_number' => self::NOTA,
                'id_outlet' => 118, 'outlet_name' => 'Tebet',
                'customer' => ['id_customer' => 9, 'customer_name' => 'Ibu Sari', 'phone' => '0812000'],
                'services' => [['service' => ['service_name' => 'Kiloan'], 'service_process_log' => $this->logNevira]],
            ]], 200),
            '*/transactions?*' => fn () => Http::response(['data' => [
                ['id_transaction' => 31242, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/deliveries-transactions*' => fn () => Http::response(['data' => $this->antaranNevira], 200),
        ]);
    }

    private function cc(): User
    {
        return User::create([
            'name' => 'CC', 'email' => 'cc'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'customer_care',
        ]);
    }

    private function buatLewatForm(User $user): Complaint
    {
        $this->actingAs($user)->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Noda masih ada',
            'nevira_transaction_number' => self::NOTA,
        ])->assertRedirect();

        return Complaint::latest('id')->first();
    }

    public function test_sinkron_mengisi_tanggal_tanpa_menambah_kolom_di_form(): void
    {
        $this->fakeNevira(
            log: [['activity_name' => 'diambil_customer', 'created_at' => '2026-09-15T23:05:00.000000Z']],
            deliveries: [],
        );

        $complaint = $this->buatLewatForm($this->cc());

        $this->assertSame('2026-09-16', $complaint->tanggal_pengambilan->toDateString());
        $this->assertSame(TanggalPengambilan::AMBIL_SENDIRI, $complaint->sumber_tanggal_pengambilan);
    }

    public function test_sinkron_tanpa_jejak_menyimpan_tidak_diketahui_bukan_created_at(): void
    {
        $this->fakeNevira(log: [], deliveries: []);

        $complaint = $this->buatLewatForm($this->cc());

        $this->assertNull($complaint->tanggal_pengambilan);
        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $complaint->sumber_tanggal_pengambilan);
    }

    public function test_tarik_ulang_memperbarui_tanggal(): void
    {
        $this->fakeNevira(log: [], deliveries: []);
        $user = $this->cc();
        $complaint = $this->buatLewatForm($user);
        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $complaint->sumber_tanggal_pengambilan);

        // Pelanggan menerima cuciannya, lalu notanya ditarik ulang.
        $this->antaranNevira = [[
            'id_deliveries_transaction' => 31591, 'delivery_date' => '2026-06-12',
            'status' => 7, 'initial_status' => '1',
        ]];

        $this->actingAs($user)
            ->post(route('complaints.resync', $complaint))
            ->assertRedirect();

        $complaint->refresh();
        $this->assertSame('2026-06-12', $complaint->tanggal_pengambilan->toDateString());
        $this->assertSame(TanggalPengambilan::ANTAR, $complaint->sumber_tanggal_pengambilan);
    }

    public function test_melepas_tautan_order_ikut_melepas_tanggalnya(): void
    {
        $this->fakeNevira(
            log: [['activity_name' => 'diambil_customer', 'created_at' => '2026-09-15T23:05:00.000000Z']],
            deliveries: [],
        );
        $user = $this->cc();
        $complaint = $this->buatLewatForm($user);
        $this->assertNotNull($complaint->tanggal_pengambilan);

        // Nomornya salah ketik dan dilepas. Tanggal serah terima milik order
        // yang sudah tidak ada hubungannya tidak boleh tertinggal di layar.
        $this->actingAs($user)->put(route('complaints.link', $complaint), [
            'nevira_transaction_number' => '',
            'nota_exemption' => 'belum_terbit',
        ])->assertRedirect();

        $complaint->refresh();
        $this->assertNull($complaint->tanggal_pengambilan);
        $this->assertSame(TanggalPengambilan::TIDAK_DIKETAHUI, $complaint->sumber_tanggal_pengambilan);
    }

    /* ---------- halaman detail ---------- */

    public function test_halaman_detail_menyebut_jarak_hari(): void
    {
        $c = $this->complaint([
            'created_at' => Carbon::parse('2026-06-15 02:00:00'),
            'tanggal_pengambilan' => '2026-06-12',
            'sumber_tanggal_pengambilan' => TanggalPengambilan::ANTAR,
        ]);

        $this->actingAs($this->cc())->get(route('complaints.show', $c))
            ->assertOk()
            ->assertSee('12 Jun 2026')
            ->assertSee('3 hari');
    }

    public function test_halaman_detail_tidak_menampilkan_angka_kalau_tidak_diketahui(): void
    {
        $c = $this->complaint();

        $this->actingAs($this->cc())->get(route('complaints.show', $c))
            ->assertOk()
            ->assertSee('Tanggal pengambilan tidak diketahui')
            ->assertDontSee('setelah pengambilan');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\KandidatPelaku;
use App\Services\LayananNota;
use App\Services\NeviraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Satu nota bisa berisi banyak baris layanan. (API-51)
 *
 * Nota 31033 berisi sepuluh Sprei (King) yang identik, dan tiap sprei punya
 * rantai pengerjaannya sendiri. Sebelum ini kesepuluh rantai itu diratakan
 * jadi satu daftar, sehingga orang yang mencuci sepuluh sprei terbaca
 * seperti mencuci satu sprei sepuluh kali.
 */
class NotaBanyakLayananTest extends TestCase
{
    use RefreshDatabase;

    /** Nomor nota seperti tercetak di struk; id internalnya 31033. */
    private const NOTA = 'INV/118/31033/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nevira.enabled' => true, 'nevira.email' => 'a@b.c', 'nevira.password' => 'x']);
    }

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    /** Satu baris layanan dengan rantai pengerjaan yang sama untuk semuanya. */
    private function barisSprei(int $ke): array
    {
        return [
            'service' => ['service_name' => 'Bedding - Sprei (King)'],
            'service_number' => 'SRV-'.$ke,
            'quantity' => 1,
            'status' => 'COMPLETED',
            'processes' => [
                ['process_name' => 'Cuci', 'staff_name' => 'Yusuf Marwanto', 'nip' => 'LW/01-0009', 'id_staff' => 9, 'status' => 'COMPLETED', 'total_duration' => 1842],
                ['process_name' => 'Pengeringan', 'staff_name' => 'Yusuf Marwanto', 'nip' => 'LW/01-0009', 'id_staff' => 9, 'status' => 'COMPLETED', 'total_duration' => 398],
                ['process_name' => 'Setrika', 'staff_name' => 'ANGGA WIJAYA', 'nip' => 'LW/01-0004', 'id_staff' => 4, 'status' => 'COMPLETED', 'total_duration' => 1208],
            ],
        ];
    }

    /** @param  int  $jumlahLayanan  berapa baris layanan pada notanya */
    private function payload(int $jumlahLayanan): array
    {
        return ['data' => [
            'id_transaction' => 31033,
            'transaction_number' => self::NOTA,
            'status' => 'COMPLETED',
            'id_outlet' => 118,
            'outlet_name' => 'Tebet',
            'cashier' => ['username' => 'Gilang', 'nip' => 'LW/06-0002'],
            'id_cashier' => 535,
            'customer' => ['id_customer' => 900, 'customer_name' => 'Ibu Sari', 'phone' => '081200001111'],
            'services' => collect(range(1, $jumlahLayanan))->map(fn ($i) => $this->barisSprei($i))->all(),
        ]];
    }

    private function fakeNevira(int $jumlahLayanan): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/31033' => Http::response($this->payload($jumlahLayanan), 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31033, 'transaction_number' => self::NOTA],
            ]], 200),
            '*/deliveries-transactions*' => Http::response(['data' => []], 200),
            '*/user/by-outlet/*' => Http::response(['data' => []], 200),
        ]);
    }

    private function complaint(array $snapshot, array $attrs = []): Complaint
    {
        $c = new Complaint(array_merge([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'satuan_bedding', 'description' => 'Sprei masih kotor',
            'nevira_transaction_number' => self::NOTA,
        ], $attrs));
        $c->ticket_number = Complaint::nextTicketNumber();
        $c->status = 'open';
        $c->applySla();
        $c->save();
        $c->forceFill(['nevira_snapshot' => $snapshot, 'nevira_synced_at' => now()])->save();

        return $c;
    }

    private function snapshot(int $jumlahLayanan): array
    {
        $this->fakeNevira($jumlahLayanan);

        $client = app(NeviraClient::class);

        return $client->summarizeTransaction($client->resolveTransaction(self::NOTA)['payload']);
    }

    /* ---------- Bagian 1: asal baris tidak lagi dibuang ---------- */

    public function test_tiap_proses_membawa_penanda_baris_layanannya(): void
    {
        $snapshot = $this->snapshot(10);

        $this->assertCount(30, $snapshot['processes']);
        $this->assertSame(1, $snapshot['processes'][0]['service_index']);
        $this->assertSame('Bedding - Sprei (King)', $snapshot['processes'][0]['service_name']);
        // Proses ke-4 adalah tahap pertama dari baris layanan KEDUA.
        $this->assertSame(2, $snapshot['processes'][3]['service_index']);
        $this->assertSame(10, $snapshot['processes'][29]['service_index']);
    }

    public function test_baris_layanan_bernomor_urut(): void
    {
        $snapshot = $this->snapshot(10);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], collect($snapshot['services'])->pluck('index')->all());
        $this->assertSame('Bedding - Sprei (King)', $snapshot['services'][0]['name']);
    }

    public function test_jejak_dikelompokkan_per_baris_layanan(): void
    {
        $complaint = $this->complaint($this->snapshot(10));

        $grup = $complaint->orderHandlerGroups();

        // Satu grup tanpa judul untuk kasir penerima, lalu sepuluh barangnya.
        $this->assertCount(11, $grup);
        $this->assertNull($grup[0]['label']);
        $this->assertSame('Kasir penerima order', $grup[0]['items'][0]['stage']);
        $this->assertSame('Bedding - Sprei (King) — barang ke-1 dari 10', $grup[1]['label']);
        $this->assertSame('Bedding - Sprei (King) — barang ke-10 dari 10', $grup[10]['label']);
        $this->assertCount(3, $grup[1]['items']);
    }

    public function test_halaman_menampilkan_judul_kelompok_untuk_nota_banyak_layanan(): void
    {
        $complaint = $this->complaint($this->snapshot(10));

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('barang ke-1 dari 10')
            ->assertSee('barang ke-10 dari 10');
    }

    /* ---------- Nota satu layanan tidak berubah sama sekali ---------- */

    public function test_nota_satu_layanan_tidak_punya_judul_kelompok(): void
    {
        $complaint = $this->complaint($this->snapshot(1));

        $grup = $complaint->orderHandlerGroups();

        $this->assertCount(1, $grup);
        $this->assertNull($grup[0]['label']);
        // Kasir penerima + tiga tahap produksi, satu daftar seperti sebelumnya.
        $this->assertCount(4, $grup[0]['items']);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertDontSee('barang ke-')
            ->assertSee('Yusuf Marwanto');
    }

    /* ---------- Snapshot lama tetap tampil ---------- */

    public function test_snapshot_lama_tanpa_penanda_layanan_tidak_memecahkan_halaman(): void
    {
        // Bentuk sebelum API-51: banyak baris layanan, tapi prosesnya belum
        // membawa penanda barisnya sama sekali.
        $complaint = $this->complaint([
            'invoice' => self::NOTA,
            'cashier_name' => 'Gilang',
            'services' => [['name' => 'Sprei'], ['name' => 'Sprei']],
            'processes' => [
                ['stage' => 'Cuci', 'staff_name' => 'Yusuf Marwanto'],
                ['stage' => 'Setrika', 'staff_name' => 'ANGGA WIJAYA'],
            ],
        ]);

        $grup = $complaint->orderHandlerGroups();

        $this->assertCount(1, $grup);
        $this->assertNull($grup[0]['label']);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('Yusuf Marwanto')
            ->assertDontSee('barang ke-');
    }

    public function test_snapshot_dengan_services_bukan_daftar_tidak_memecahkan_halaman(): void
    {
        $complaint = $this->complaint([
            'invoice' => self::NOTA,
            'services' => 'bukan daftar',
            'processes' => [['stage' => 'Cuci', 'staff_name' => 'Yusuf Marwanto']],
        ]);

        $this->assertSame(0, $complaint->serviceCount());

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('Yusuf Marwanto');
    }

    /* ---------- Bagian 2: complaint menunjuk satu barang ---------- */

    public function test_pemeriksaan_nota_mengembalikan_baris_layanan_beserta_tebakan_layanannya(): void
    {
        $this->fakeNevira(10);

        $data = $this->actingAs($this->userAs('customer_care'))
            ->getJson('/nevira/lookup?id='.self::NOTA)
            ->assertOk()
            ->json('data');

        $this->assertCount(10, $data['services']);
        $this->assertSame(3, $data['services'][2]['index']);
        $this->assertSame('Bedding - Sprei (King)', $data['services'][2]['name']);
        $this->assertSame('satuan_bedding', $data['services'][2]['layanan']);
    }

    public function test_nota_satu_layanan_hanya_mengembalikan_satu_baris(): void
    {
        $this->fakeNevira(1);

        $data = $this->actingAs($this->userAs('customer_care'))
            ->getJson('/nevira/lookup?id='.self::NOTA)
            ->assertOk()
            ->json('data');

        // Satu baris: form tidak punya apa pun untuk ditawarkan, jadi kasir
        // tidak melihat langkah tambahan. (kriteria 4 dan 7)
        $this->assertCount(1, $data['services']);
    }

    public function test_pilihan_barang_tersembunyi_di_form_kosong(): void
    {
        $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')
            ->assertOk()
            ->assertSee('id="barang-blok" style="display:none;"', false);
    }

    public function test_barang_yang_dikeluhkan_tersimpan(): void
    {
        $this->fakeNevira(10);

        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'satuan_bedding', 'description' => 'Sprei ke-3 masih kotor',
            'nevira_transaction_number' => self::NOTA,
            'nevira_service_index' => 3,
        ]);

        $this->assertSame(3, Complaint::latest('id')->first()->nevira_service_index);
    }

    public function test_complaint_tanpa_nota_tidak_boleh_menunjuk_barang(): void
    {
        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'satuan_bedding', 'description' => 'Belum ada notanya',
            'nota_exemption' => array_key_first(config('complaint.nota_exemptions')),
            'nevira_service_index' => 3,
        ]);

        $this->assertNull(Complaint::latest('id')->first()->nevira_service_index);
    }

    public function test_nomor_barang_yang_notanya_tidak_punya_barisnya_dilepas_saat_sinkron(): void
    {
        $this->fakeNevira(2);

        $this->actingAs($this->userAs('customer_care'))->post('/complaints', [
            'channel' => 'wa_cc', 'reporter_name' => 'Ibu Sari', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'satuan_bedding', 'description' => 'Sprei ke-7',
            'nevira_transaction_number' => self::NOTA,
            'nevira_service_index' => 7,
        ]);

        $complaint = Complaint::latest('id')->first();

        $this->assertSame(2, $complaint->serviceCount());
        $this->assertNull($complaint->nevira_service_index);
    }

    public function test_menautkan_nota_lain_melepas_barang_yang_ditunjuk(): void
    {
        $complaint = $this->complaint($this->snapshot(10), ['nevira_service_index' => 3]);
        $this->assertSame(3, $complaint->nevira_service_index);

        config(['nevira.enabled' => false]);

        $this->actingAs($this->userAs('customer_care'))
            ->put('/complaints/'.$complaint->id.'/link', ['nevira_transaction_number' => 'INV/118/31099/1']);

        $this->assertNull($complaint->fresh()->nevira_service_index);
    }

    /* ---------- Penetapan pelaku mendahulukan baris yang dikeluhkan ---------- */

    public function test_kandidat_pelaku_mendahulukan_yang_mengerjakan_barang_itu(): void
    {
        // Baris ke-2 dikerjakan orang lain, supaya pemisahannya terlihat.
        $snapshot = $this->snapshot(2);
        $snapshot['processes'][3]['staff_name'] = 'Siti Nurhaliza';
        $snapshot['processes'][3]['staff_nip'] = 'LW/01-0077';
        $snapshot['processes'][3]['staff_id'] = 77;

        $complaint = $this->complaint($snapshot, ['nevira_service_index' => 1]);

        $grup = KandidatPelaku::untuk($complaint)->groups();

        $this->assertSame('Mengerjakan barang yang dikeluhkan', $grup[0]['label']);
        $this->assertFalse($grup[0]['collapsed']);
        $this->assertContains('Yusuf Marwanto', collect($grup[0]['items'])->pluck('name')->all());
        // Kasir penerima menyentuh seluruh nota, jadi ia tetap ditawarkan.
        $this->assertContains('Gilang', collect($grup[0]['items'])->pluck('name')->all());

        $this->assertSame('Barang lain di nota yang sama', $grup[1]['label']);
        $this->assertTrue($grup[1]['collapsed']);
        $this->assertSame(['Siti Nurhaliza'], collect($grup[1]['items'])->pluck('name')->all());
    }

    public function test_yang_mengerjakan_barang_lain_tidak_disembunyikan_dari_halaman(): void
    {
        $snapshot = $this->snapshot(2);
        $snapshot['processes'][3]['staff_name'] = 'Siti Nurhaliza';
        $snapshot['processes'][3]['staff_id'] = 77;

        $complaint = $this->complaint($snapshot, ['nevira_service_index' => 1]);

        $this->actingAs($this->userAs('supervisor'))
            ->get('/complaints/'.$complaint->id)
            ->assertOk()
            ->assertSee('Barang lain di nota yang sama')
            ->assertSee('Siti Nurhaliza');
    }

    public function test_complaint_seluruh_nota_tidak_memisah_kandidat(): void
    {
        $complaint = $this->complaint($this->snapshot(10));

        $grup = KandidatPelaku::untuk($complaint)->groups();

        $this->assertSame('Tercatat di nota ini', $grup[0]['label']);
        $this->assertFalse($grup[0]['collapsed']);
    }

    /* ---------- Pengukuran: seberapa sering notanya banyak layanan ---------- */

    public function test_perintah_hitung_layanan_melaporkan_sebarannya(): void
    {
        Http::fake([
            '*/login' => Http::response(['access_token' => 'tok'], 200),
            '*/transactions/31033' => Http::response($this->payload(10), 200),
            '*/transactions/31034' => Http::response($this->payload(1), 200),
            '*/transactions?*' => Http::response(['data' => [
                ['id_transaction' => 31033], ['id_transaction' => 31034],
            ]], 200),
        ]);

        $this->artisan('nevira:hitung-layanan --jumlah=2')
            ->expectsOutputToContain('Diperiksa       : 2 nota terakhir')
            ->expectsOutputToContain('Lebih dari satu : 1 nota (50%)')
            ->assertSuccessful();
    }

    public function test_perintah_hitung_layanan_menolak_jalan_tanpa_kredensial(): void
    {
        config(['nevira.email' => null, 'nevira.password' => null]);

        $this->artisan('nevira:hitung-layanan')->assertFailed();
    }

    /* ---------- Menebak kolom layanan dari nama baris ---------- */

    public function test_nama_layanan_nevira_diterjemahkan_ke_kolom_layanan(): void
    {
        $this->assertSame('satuan_bedding', LayananNota::dariNama('Bedding - Sprei (King)'));
        $this->assertSame('kiloan_cuset', LayananNota::dariNama('Kiloan - Cuci Setrika'));
        $this->assertSame('kiloan_culip', LayananNota::dariNama('Kiloan - Cuci Lipat'));
        $this->assertSame('satuan_cloth', LayananNota::dariNama('Cloth - Kemeja'));
        // "Non Cloth" tidak boleh tercocok sebagai "Cloth".
        $this->assertSame('satuan_non_cloth', LayananNota::dariNama('Non Cloth - Tas'));
    }

    public function test_nama_yang_tidak_dikenali_tidak_menebak_layanan(): void
    {
        $this->assertNull(LayananNota::dariNama('Layanan Baru Yang Belum Ada'));
        $this->assertNull(LayananNota::dariNama(null));
        $this->assertNull(LayananNota::dariNama(''));
    }
}

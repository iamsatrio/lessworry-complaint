<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\Outlet;
use App\Models\User;
use App\Services\JejakComplaint;
use App\Services\LayananDariUraian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Layanan jadi delapan nilai. (API-59)
 *
 * Yang dijaga di sini bukan "dropdownnya bertambah dua". Yang dijaga adalah
 * satu aturan yang tidak kelihatan dari kodenya: **`layanan` adalah jasa yang
 * DIBELI pelanggan, bukan barang yang kebetulan disebut dalam keluhan.**
 *
 * Tas laundry adalah wadah tempat cucian datang dan pulang. Tasnya memang
 * milik pelanggan, tapi bukan tasnya yang dicuci — yang dibeli Kiloan.
 * Bandingkan dengan baris yang memang pindah ke `sepatu_tas`: "Tas belum
 * bersih", "tas carrer kurang bersih dibagian dalam". Di situ tasnya YANG
 * DIKERJAKAN. Bedanya bukan siapa pemiliknya, tapi apakah barang itu objek
 * jasanya.
 *
 * Ada empat baris berbunyi "Tas laundry gak dikembalikan" di 545 complaint
 * nyata, dan semuanya ber-layanan Kiloan. Satu pola `tas` tanpa pengecualian
 * mengubah keluhan tentang wadah jadi keluhan tentang layanan cuci tas —
 * diam-diam, di satu-satunya riwayat yang dimiliki sistem.
 *
 * Angka 47 dan 13 dari data nyata TIDAK bisa diuji di sini: berkas aslinya
 * berisi nama dan keluhan pelanggan, dan data itu tidak masuk repositori.
 * Yang diuji di sini invariannya — kata kunci mana yang memindahkan, mana
 * yang tidak, dan berapa banyak — atas data yang dibuat sendiri dengan
 * jumlah yang sudah diketahui.
 */
class LayananDelapanTest extends TestCase
{
    use RefreshDatabase;

    /** Enam nilai lama. Kuncinya TIDAK boleh berubah: 545 baris backfill memakainya. */
    private const LAMA = [
        'kiloan_cuset', 'kiloan', 'kiloan_culip',
        'satuan_non_cloth', 'satuan_cloth', 'satuan_bedding',
    ];

    private const BARU = ['sepatu_tas', 'karpet_gorden'];

    /**
     * Baris nyata dari 545 complaint yang cocok kata kunci karpet tapi isinya
     * soal salah paham. Satu-satunya yang tertandai ambigu di seluruh data.
     */
    private const AMBIGU = 'Miss komunikasi antara kasir dan customer, kasir menginfokan penyelesaian bedcover dan karpet selama 3 hari';

    protected function setUp(): void
    {
        parent::setUp();

        Outlet::create(['name' => 'Kemang', 'nevira_outlet_id' => '115']);
    }

    private function userAs(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
        ]);
    }

    /** Satu complaint seperti keadaannya SEBELUM pembetulan dijalankan. */
    private function buat(string $uraian, string $layanan, int $biaya = 0): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'impor', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => $layanan, 'description' => $uraian,
        ]);

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'close';
        $complaint->compensation_amount = $biaya;
        $complaint->created_at = Carbon::parse('2026-03-01');
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /**
     * Kumpulan uji yang dipakai hampir semua test di bawah.
     *
     * Delapan baris, dan setiap barisnya mewakili satu aturan:
     * tiga yang harus pindah, dua yang tidak boleh pindah karena kata
     * kuncinya dikecualikan atau bukan kata utuh, dan tiga yang tidak boleh
     * pindah karena layanannya memang bukan Satuan Non Cloth.
     *
     * @return array<string,Complaint>
     */
    private function benih(): array
    {
        return [
            'sepatu' => $this->buat('Sepatu kurang bersih', 'satuan_non_cloth', 50000),
            'koper' => $this->buat('Handle koper tidak dibersihkan', 'satuan_non_cloth'),
            'gorden' => $this->buat('Gorden sobek', 'satuan_non_cloth', 2525000),
            'tasLaundryNonCloth' => $this->buat('Tas laundry gak dikembalikan', 'satuan_non_cloth'),
            'batas' => $this->buat('Cucian melewati batas waktu yang pantas', 'satuan_non_cloth'),
            'tasLaundryKiloan' => $this->buat('Tas laundry terbawa customer lain', 'kiloan'),
            'sepatuKiloan' => $this->buat('Sepatu kurang bersih setelah dicuci kiloan', 'kiloan_cuset'),
            'karpetCloth' => $this->buat('Karpet masih lembab', 'satuan_cloth'),
        ];
    }

    /* ---------- 1. Delapan nilai, enam yang lama tidak berubah ---------- */

    public function test_dropdown_intake_berisi_delapan_nilai(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        $this->assertCount(8, config('complaint.layanan'));

        foreach ([...self::LAMA, ...self::BARU] as $kunci) {
            $this->assertStringContainsString('value="'.$kunci.'"', $html,
                "Layanan '$kunci' tidak ada di form intake.");
        }
    }

    public function test_kunci_dan_label_enam_nilai_lama_tidak_berubah(): void
    {
        $layanan = config('complaint.layanan');

        $this->assertSame([
            'kiloan_cuset' => 'Kiloan – Cuci Setrika',
            'kiloan' => 'Kiloan',
            'kiloan_culip' => 'Kiloan – Cuci Lipat',
            'satuan_non_cloth' => 'Satuan Non Cloth',
            'satuan_cloth' => 'Satuan Cloth',
            'satuan_bedding' => 'Satuan Bedding',
        ], array_intersect_key($layanan, array_flip(self::LAMA)));
    }

    public function test_server_menerima_dua_nilai_baru_dan_menolak_yang_karangan(): void
    {
        $cc = $this->userAs('customer_care');

        $intake = [
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'description' => 'Keluhan uji', 'nota_exemption' => 'lebih_sebulan',
        ];

        foreach (self::BARU as $kunci) {
            $this->actingAs($cc)->post('/complaints', $intake + ['layanan' => $kunci])->assertRedirect();
            $this->assertSame($kunci, Complaint::latest('id')->first()->layanan);
        }

        $this->actingAs($cc)->post('/complaints', $intake + ['layanan' => 'baby_gear'])
            ->assertSessionHasErrors('layanan');
    }

    /* ---------- 2. Mode kering tidak menulis apa pun ---------- */

    public function test_dry_run_mencetak_barisnya_dan_tidak_menulis_apa_pun(): void
    {
        $benih = $this->benih();

        $this->artisan('complaint:betulkan-layanan')
            ->expectsOutputToContain('Sepatu & Tas')
            ->expectsOutputToContain('Karpet & Gorden')
            ->expectsOutputToContain('Mode hitung saja')
            ->assertSuccessful();

        foreach ($benih as $nama => $complaint) {
            $this->assertSame($complaint->layanan, $complaint->fresh()->layanan,
                "Baris '$nama' berubah padahal perintah dijalankan tanpa --tulis.");
        }

        $this->assertSame(0, ComplaintActivity::where('note', 'like', JejakComplaint::TANDA_LAYANAN.'%')->count());
    }

    /* ---------- 3. Hitungannya persis, dan Kiloan tidak tersentuh ---------- */

    public function test_tulis_memindahkan_baris_yang_benar_dengan_jumlah_yang_benar(): void
    {
        $benih = $this->benih();
        $kiloanSebelum = Complaint::where('layanan', 'like', 'kiloan%')->pluck('layanan', 'id');

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();

        $this->assertSame('sepatu_tas', $benih['sepatu']->fresh()->layanan);
        $this->assertSame('sepatu_tas', $benih['koper']->fresh()->layanan);
        $this->assertSame('karpet_gorden', $benih['gorden']->fresh()->layanan);

        $this->assertSame(2, Complaint::where('layanan', 'sepatu_tas')->count());
        $this->assertSame(1, Complaint::where('layanan', 'karpet_gorden')->count());

        // Yang tidak cocok tetap di tempatnya, bukan jatuh ke mana-mana.
        $this->assertSame(2, Complaint::where('layanan', 'satuan_non_cloth')->count());

        // NOL baris ber-layanan Kiloan berubah — apa pun isi uraiannya.
        $this->assertEquals($kiloanSebelum, Complaint::where('layanan', 'like', 'kiloan%')->pluck('layanan', 'id'));
    }

    /**
     * `updated_at` baris impor sengaja diisi tanggal penyelesaian aslinya
     * (API-28). Pembetulan ini tidak boleh menaikkannya ke hari ini —
     * complaint 2026 yang terlihat baru saja disunting orang adalah riwayat
     * yang berbohong.
     */
    public function test_pembetulan_tidak_menyentuh_updated_at(): void
    {
        $complaint = $this->buat('Sepatu kurang bersih', 'satuan_non_cloth');
        Complaint::query()->whereKey($complaint->id)->update(['updated_at' => '2026-03-05 00:00:00']);

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();

        $segar = $complaint->fresh();
        $this->assertSame('sepatu_tas', $segar->layanan);
        $this->assertSame('2026-03-05 00:00:00', $segar->updated_at->format('Y-m-d H:i:s'));
    }

    /* ---------- 4. Tas laundry: jebakan yang paling mudah dilanggar ---------- */

    /**
     * Tas laundry adalah WADAH, bukan objek jasanya. Empat baris di data nyata
     * berbunyi begitu dan semuanya ber-layanan Kiloan: yang dibeli pelanggan
     * cuci per kilo, dan tasnya cuma yang membawa cucian itu datang dan
     * pulang. Memindahkannya mengubah keluhan tentang wadah jadi keluhan
     * tentang layanan cuci tas.
     */
    public function test_baris_tas_laundry_tetap_di_layanan_semula(): void
    {
        $baris = [
            $this->buat('Tas laundry gak dikembalikan, kancing baju copot', 'kiloan_cuset'),
            $this->buat('customer merasa tas laundry belum dikembalikan namun kasir dan kurir menyatakan sudah', 'kiloan'),
            $this->buat('Tas laundry terbawa customer lain', 'kiloan'),
            // Yang ini bahkan BER-LAYANAN Satuan Non Cloth, jadi ia lolos
            // penyaring layanan dan hanya pengecualian frasa yang menahannya.
            $this->buat('Tas laundry gak dikembalikan', 'satuan_non_cloth'),
            $this->buat('Tas cuci belum kembali', 'satuan_non_cloth'),
            // Bentuk berakhiran. `-nya` dan `-an` adalah cara paling wajar
            // menulis kalimat ini, dan `\b` sesudah laundry/cuci membiarkan
            // ketiganya lolos dari pengecualian — keluhan tentang WADAH
            // tercatat sebagai keluhan tentang jasa cuci tas. Ketiganya
            // ber-Satuan Non Cloth, jadi penyaring layanan tidak menolong:
            // yang menahannya hanya polanya. (Tinjauan PR #22)
            $this->buat('Tas laundrynya gak dikembalikan', 'satuan_non_cloth'),
            $this->buat('Tas cucian belum kembali', 'satuan_non_cloth'),
            $this->buat('tas cucinya sobek', 'satuan_non_cloth'),
        ];

        $sebelum = array_map(fn (Complaint $c) => $c->layanan, $baris);

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();

        foreach ($baris as $i => $complaint) {
            $this->assertSame($sebelum[$i], $complaint->fresh()->layanan,
                'Baris tas laundry ke-'.$i.' berpindah layanan. Tasnya wadah, bukan yang dicuci.');
        }

        $this->assertSame(0, Complaint::where('layanan', 'sepatu_tas')->count());
    }

    /** `\btas\b` tanpa batas kata mencocokkan "batas", "pantas", "tastes". */
    public function test_kata_kunci_memakai_batas_kata(): void
    {
        foreach (['Cucian melewati batas waktu', 'Hasilnya tidak pantas', 'Tastes berbeda', 'Kertas nota hilang'] as $uraian) {
            $this->assertNull(LayananDariUraian::tebak($uraian), "'$uraian' tidak boleh cocok.");
        }

        foreach (['Tas kurang bersih', 'tas carrer kurang bersih', 'Sepatu jebol', 'Handle koper kotor'] as $uraian) {
            $this->assertSame('sepatu_tas', LayananDariUraian::tebak($uraian), "'$uraian' harus cocok.");
        }

        foreach (['Karpet bau apek', 'karpetnya kasar', 'Gorden sobek', 'Vitrase belum kering'] as $uraian) {
            $this->assertSame('karpet_gorden', LayananDariUraian::tebak($uraian), "'$uraian' harus cocok.");
        }
    }

    /* ---------- 4b. Baris ambigu ditahan, bukan sekadar ditandai ---------- */

    /**
     * `tebak()` menjawab "nilai apa yang boleh disimpan", dan untuk baris
     * ambigu jawabannya null. `periksa()` menjawab "apa yang cocok", dan ia
     * tetap membawa barisnya — itu yang membuatnya bisa dicetak.
     *
     * Kedua jawaban itu harus berbeda. Kalau `periksa()` ikut memulangkan
     * null, barisnya ditahan tanpa terlihat, dan ditahan tanpa terlihat sama
     * saja dengan hilang.
     */
    public function test_tebak_menahan_baris_ambigu_tapi_periksa_masih_membawanya(): void
    {
        $this->assertNull(LayananDariUraian::tebak(self::AMBIGU));

        $temu = LayananDariUraian::periksa(self::AMBIGU);
        $this->assertNotNull($temu);
        $this->assertSame('karpet_gorden', $temu['layanan']);
        $this->assertSame(LayananDariUraian::TAHAN_AMBIGU, $temu['tahan']);

        // Baris yang menyebut barangnya lebih dulu tidak ikut tertahan.
        $jelas = LayananDariUraian::periksa('Karpet bau apek');
        $this->assertNotNull($jelas);
        $this->assertNull($jelas['tahan']);
        $this->assertSame('karpet_gorden', LayananDariUraian::tebak('Karpet bau apek'));
    }

    /** Ditahan berarti TIDAK dipindah — bahkan dengan `--tulis`. */
    public function test_baris_ambigu_dicetak_tapi_tidak_dipindah(): void
    {
        $ambigu = $this->buat(self::AMBIGU, 'satuan_non_cloth');
        $jelas = $this->buat('Karpet bau apek', 'satuan_non_cloth');

        $this->artisan('complaint:betulkan-layanan')
            ->expectsOutputToContain('Ditahan, tidak dipindah: 1 baris.')
            ->expectsOutputToContain('kata kuncinya di luar klausa pertama')
            ->expectsOutputToContain('TIDAK dipindah')
            ->assertSuccessful();

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();

        $this->assertSame('satuan_non_cloth', $ambigu->fresh()->layanan,
            'Baris ambigu dipindah. Nilai yang tidak pasti tidak boleh masuk bucket yang dibangun untuk dipercaya.');
        $this->assertSame('karpet_gorden', $jelas->fresh()->layanan);
        $this->assertSame(1, Complaint::where('layanan', 'karpet_gorden')->count());
    }

    /**
     * Blok "Ditahan" dicetak walau nol. Bagian yang cuma muncul saat ada
     * isinya membuat pembacanya harus menebak: memang tidak ada, atau lupa
     * dicetak?
     */
    public function test_blok_ditahan_dicetak_walau_nol(): void
    {
        $this->buat('Karpet bau apek', 'satuan_non_cloth');

        $this->artisan('complaint:betulkan-layanan')
            ->expectsOutputToContain('Ditahan, tidak dipindah: 0 baris.')
            ->assertSuccessful();
    }

    /**
     * Jalur impor menahan baris yang sama — tanpa menyalin aturannya, dan
     * tanpa menahannya diam-diam. Ini kriteria 5: kalau aturannya cuma ada di
     * perintah, impor berikutnya akan mengisi nilai yang sudah diputuskan
     * tidak tepat, dan tidak ada yang mencetak apa pun di sana.
     */
    public function test_impor_menahan_baris_ambigu_dan_mencatatnya_sebagai_anomali(): void
    {
        $laporan = storage_path('framework/testing/laporan-ambigu.md');

        $this->artisan('complaint:import', [
            'berkas' => base_path('tests/Fixtures/impor-layanan-ambigu.csv'),
            '--sumber' => 'uji-ambigu',
            '--laporan' => $laporan,
            '--tulis' => true,
        ])->assertSuccessful();

        $isi = is_file($laporan) ? (string) file_get_contents($laporan) : '';

        if (is_file($laporan)) {
            unlink($laporan);
        }

        $this->assertSame('satuan_non_cloth', Complaint::where('description', self::AMBIGU)->value('layanan'));
        $this->assertSame('karpet_gorden', Complaint::where('description', 'Karpet bau apek')->value('layanan'));

        $this->assertStringContainsString('kata kuncinya di luar klausa pertama', $isi,
            'Impor menahan barisnya tanpa mengatakannya. Laporan impor justru tempat keputusan begitu harus muncul.');
    }

    /* ---------- 4c. Tas laundry menahan SELURUH baris, bukan satu kata ---------- */

    /**
     * Kalimat yang menyebut `tas` dua kali: sekali sebagai wadah, sekali
     * telanjang.
     *
     * Bentuk lookahead per-kemunculan memindahkan keduanya ke `sepatu_tas`,
     * karena `tas` yang kedua adalah kemunculan `\btas\b` yang sah — bukan
     * sisa lookahead. Menulis ulang pengecualiannya tidak menutup apa pun,
     * termasuk bentuk "buang dulu frasanya, cocokkan sisanya" yang tercatat
     * di API-76 nomor 1 dan sudah diukur tidak mengubah hasilnya. Yang harus
     * ditahan barisnya, bukan katanya.
     */
    public function test_uraian_bertas_dua_kali_ditahan_seluruh_barisnya(): void
    {
        foreach ([
            'Tas laundry gak dikembalikan, tas nya hilang',
            'Tas laundry dan tas customer tertukar',
        ] as $uraian) {
            $this->assertNull(LayananDariUraian::tebak($uraian),
                "'$uraian' berpindah. Keluhannya tentang wadah; jasa yang dibeli tetap Kiloan.");

            $temu = LayananDariUraian::periksa($uraian);
            $this->assertNotNull($temu, 'Baris yang ditahan harus tetap bisa dicetak.');
            $this->assertSame(LayananDariUraian::TAHAN_WADAH, $temu['tahan']);
        }
    }

    /**
     * Barang yang disebut EKSPLISIT menang atas wadahnya. Kalau tidak,
     * penahan wadah akan menelan keluhan sepatu yang kebetulan menyebut
     * kantongnya — arah gagal yang salah, dan 47 baris yang sudah benar ikut
     * terseret.
     */
    public function test_barang_eksplisit_menang_atas_wadahnya(): void
    {
        $this->assertSame('sepatu_tas', LayananDariUraian::tebak('Sepatu kotor, tas laundry juga hilang'));
        $this->assertSame('karpet_gorden', LayananDariUraian::tebak('Karpet bau, tas laundry ikut basah'));

        // Dan yang memang tasnya yang dicuci tetap pindah.
        foreach (['Tas belum bersih', 'tas carrer kurang bersih dibagian dalam', 'Tas Gucci terlipat'] as $uraian) {
            $this->assertSame('sepatu_tas', LayananDariUraian::tebak($uraian), "'$uraian' harus pindah.");
        }
    }

    /** Bentuk berakhiran tetap tertahan — "tas laundrynya", "Tas cucian", "tas cucinya". */
    public function test_wadah_berakhiran_tetap_tertahan(): void
    {
        foreach ([
            'Tas laundry belum kembali',
            'Tas laundrynya belum kembali',
            'Tas cucian belum kembali',
            'tas cucinya sobek',
            'tas laundry hilang, tas nya juga',
        ] as $uraian) {
            $this->assertNull(LayananDariUraian::tebak($uraian), "'$uraian' tidak boleh pindah.");
        }
    }

    /* ---------- 5. Impor ulang menghasilkan pemetaan yang sama ---------- */

    public function test_impor_ulang_berkas_csv_menghasilkan_pemetaan_yang_sama(): void
    {
        $laporan = storage_path('framework/testing/laporan-layanan.md');

        $this->artisan('complaint:import', [
            'berkas' => base_path('tests/Fixtures/impor-layanan.csv'),
            '--sumber' => 'uji-layanan',
            '--laporan' => $laporan,
            '--tulis' => true,
        ])->assertSuccessful();

        if (is_file($laporan)) {
            unlink($laporan);
        }

        $sebaran = Complaint::selectRaw('layanan, count(*) as n')
            ->groupBy('layanan')->pluck('n', 'layanan')->all();

        $this->assertSame(3, $sebaran['sepatu_tas'] ?? 0);
        $this->assertSame(2, $sebaran['karpet_gorden'] ?? 0);
        $this->assertSame(3, $sebaran['satuan_non_cloth'] ?? 0);
        $this->assertSame(1, $sebaran['kiloan'] ?? 0);
        $this->assertSame(1, $sebaran['kiloan_cuset'] ?? 0);
        $this->assertSame(1, $sebaran['satuan_cloth'] ?? 0);

        // Berkas yang sama, diimpor dengan kode yang sama: pembetulan tidak
        // menemukan apa pun lagi. Kalau pemetanya punya salinan kata kunci
        // sendiri, di sinilah bedanya akan terlihat.
        $this->artisan('complaint:betulkan-layanan')
            ->expectsOutputToContain('Tidak ada baris')
            ->assertSuccessful();
    }

    /* ---------- 6. Laporan mengelompokkan dua nilai baru ---------- */

    public function test_laporan_mengelompokkan_dua_nilai_baru_termasuk_grafik_biaya(): void
    {
        $this->buat('Gorden sobek', 'karpet_gorden', 2525000);
        $this->buat('Sepatu kurang bersih', 'sepatu_tas', 50000);
        $this->buat('Noda di kerah', 'satuan_non_cloth', 10000);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-01-01&to=2026-12-31')->assertOk()->getContent();

        $this->assertStringContainsString('Biaya complaint per layanan', $html);

        // Bukan sekadar labelnya ada di dropdown penyaring: angkanya harus
        // ikut ke tabel grafik biaya.
        $this->assertStringContainsString('Rp 2.525.000', $html);

        foreach (['Sepatu &amp; Tas', 'Karpet &amp; Gorden'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /* ---------- 7. Jalan mundur ---------- */

    public function test_jalan_mundur_mengembalikan_baris_yang_dipindah(): void
    {
        $benih = $this->benih();

        // Complaint yang layanannya diisi kasir sendiri, bukan hasil
        // pembetulan. Ia tidak punya baris riwayatnya, jadi tidak boleh ikut
        // mundur ke Satuan Non Cloth.
        $bukanHasilPembetulan = $this->buat('Sepatu baru dikeluhkan hari ini', 'sepatu_tas');

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();
        $this->assertSame(3, Complaint::whereIn('layanan', LayananDariUraian::tujuan())->count() - 1);

        $this->artisan('complaint:betulkan-layanan --balikkan')->assertSuccessful();
        $this->assertSame('sepatu_tas', $benih['sepatu']->fresh()->layanan, 'Mode kering tidak boleh menulis.');

        $this->artisan('complaint:betulkan-layanan --balikkan --tulis')->assertSuccessful();

        foreach (['sepatu', 'koper', 'gorden'] as $nama) {
            $this->assertSame('satuan_non_cloth', $benih[$nama]->fresh()->layanan);
        }

        $this->assertSame('sepatu_tas', $bukanHasilPembetulan->fresh()->layanan,
            'Complaint yang tidak pernah dipindah perintah ini ikut dikembalikan.');

        $this->assertSame(3, ComplaintActivity::where('note', 'like', JejakComplaint::TANDA_LAYANAN_BATAL.'%')->count());
    }

    /**
     * Jalan mundur berhenti di keputusan orang.
     *
     * Reproduksi yang dilaporkan di PR #22: perintah memindahkan baris ke
     * Sepatu & Tas, petugas membacanya lalu memindahkannya lagi ke Karpet &
     * Gorden — keputusan yang hanya bisa diambil orang yang tahu barangnya.
     * `--balikkan --tulis` sesudah itu menariknya ke Satuan Non Cloth: bukan
     * membatalkan perintah ini, tapi membuang penilaian orang, dan di tabel
     * riwayat hasilnya tidak bisa dibedakan dari baris yang memang otomatis.
     *
     * Pembandingnya catatan riwayat perintah ini sendiri: yang ditulis
     * `sepatu_tas`, yang ada sekarang `karpet_gorden`, jadi ada yang
     * memindahkannya setelah perintah lewat.
     */
    public function test_jalan_mundur_tidak_menimpa_pembetulan_orang(): void
    {
        $benih = $this->benih();

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();
        $this->assertSame('sepatu_tas', $benih['sepatu']->fresh()->layanan);

        // Petugas memindahkannya lagi. Riwayat pembetulannya tetap ada —
        // itu justru yang dulu membuat baris ini ikut tertarik mundur.
        $benih['sepatu']->fresh()->update(['layanan' => 'karpet_gorden']);

        $this->artisan('complaint:betulkan-layanan --balikkan --tulis')
            ->expectsOutputToContain('1 baris DILEWATI')
            ->assertSuccessful();

        $this->assertSame('karpet_gorden', $benih['sepatu']->fresh()->layanan,
            'Baris yang dipindah orang ikut ditimpa jalan mundur.');

        // Yang tidak disentuh orang tetap mundur seperti biasa.
        foreach (['koper', 'gorden'] as $nama) {
            $this->assertSame('satuan_non_cloth', $benih[$nama]->fresh()->layanan);
        }

        $this->assertSame(2, ComplaintActivity::where('note', 'like', JejakComplaint::TANDA_LAYANAN_BATAL.'%')->count(),
            'Baris yang dilewati tidak boleh menulis catatan pembatalan.');
    }

    /** Angka nol pun disebut — yang dilewati harus selalu terbaca, bukan hanya saat ada. */
    public function test_jalan_mundur_menyebut_berapa_baris_yang_dilewati(): void
    {
        $this->benih();

        $this->artisan('complaint:betulkan-layanan --tulis')->assertSuccessful();

        $this->artisan('complaint:betulkan-layanan --balikkan')
            ->expectsOutputToContain('Dilewati karena sudah dipindah orang: 0 baris.')
            ->assertSuccessful();
    }
}

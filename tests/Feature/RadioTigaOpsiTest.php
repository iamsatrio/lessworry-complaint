<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bobot dan Kanal sama-sama tiga opsi, dan sama-sama select — di form intake
 * yang sudah memuat delapan select.
 *
 * Untuk kasir satu tangan, tiap select berarti ketuk → tunggu lapisan sistem
 * muncul → gulir → pilih → tutup. Radio memotongnya jadi satu ketukan, dan
 * ketiga opsinya terpapar seluruhnya seketika. Bobot punya alasan tambahan:
 * ia menentukan tenggat DAN siapa yang boleh menutup, jadi konsekuensinya
 * perlu bisa dibandingkan, bukan disembunyikan dua dari tiga.
 *
 * BATASNYA, dan ini bagian yang dijaga di sini juga: Kategori (8 opsi) dan
 * Layanan (6 opsi) TETAP select. Radio sebanyak itu menambah panjang halaman
 * yang sudah jadi masalah tersendiri. (API-86 #4)
 */
class RadioTigaOpsiTest extends TestCase
{
    use RefreshDatabase;

    private function form(string $role = 'customer_care'): string
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $role === 'kasir' ? $outlet->id : null,
        ]);

        return $this->actingAs($user)->get('/complaints/create')->assertOk()->getContent();
    }

    public function test_bobot_jadi_tiga_radio(): void
    {
        $html = $this->form();

        foreach (array_keys(config('complaint.bobot')) as $kunci) {
            $this->assertStringContainsString(
                '<input type="radio" name="bobot" value="'.$kunci.'"',
                $html,
                'Opsi bobot '.$kunci.' hilang saat select diganti radio.'
            );
        }

        $this->assertStringNotContainsString('<select id="bob"', $html,
            'Bobot kembali jadi select.');
    }

    public function test_kanal_jadi_tiga_radio(): void
    {
        $html = $this->form();

        foreach (array_keys(config('complaint.channels')) as $kunci) {
            $this->assertStringContainsString(
                '<input type="radio" name="channel" value="'.$kunci.'"',
                $html,
                'Opsi kanal '.$kunci.' hilang saat select diganti radio.'
            );
        }

        $this->assertStringNotContainsString('<select id="ch"', $html,
            'Kanal kembali jadi select.');
    }

    /**
     * Kalau dua ini diseragamkan ke radio juga, halaman intake bertambah
     * empat belas baris pilihan — persis panjang yang sedang dikurangi.
     */
    public function test_kategori_dan_layanan_tetap_select(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('<select id="cat" name="category"', $html,
            'Kategori (8 opsi) ikut jadi radio dan memanjangkan halaman.');
        $this->assertMatchesRegularExpression('/<select id="lay" name="layanan"/', $html,
            'Layanan (6 opsi) ikut jadi radio dan memanjangkan halaman.');

        $this->assertGreaterThan(5, count(config('complaint.categories')));
        $this->assertGreaterThan(5, count(config('complaint.layanan')));
    }

    /** Grup radio adalah satu pertanyaan, bukan tiga baris tanpa judul. */
    public function test_tiap_grup_radio_punya_fieldset_dan_legend(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression(
            '/<fieldset class="pilihan"[^>]*>\s*<legend>Bobot/s',
            $html,
            'Grup bobot tanpa fieldset/legend: pembaca layar membacakan tiga baris tanpa pertanyaannya.'
        );
        $this->assertMatchesRegularExpression(
            '/<fieldset class="pilihan"[^>]*>\s*<legend>Masuk lewat/s',
            $html,
            'Grup kanal tanpa fieldset/legend.'
        );
    }

    /** Sasaran sentuhnya baris, bukan lingkaran 18px. */
    public function test_tiap_radio_dibungkus_baris_yang_bisa_disentuh(): void
    {
        $html = $this->form();

        preg_match_all('/<label class="pick">\s*<input type="radio" name="(bobot|channel)"/s', $html, $m);

        $this->assertCount(
            count(config('complaint.bobot')) + count(config('complaint.channels')),
            $m[0],
            'Ada radio yang tidak dibungkus label.pick — sasaran sentuhnya jadi lingkaran 18px.'
        );
    }

    /** Bobot tetap harus dipilih; tidak ada yang tercentang lebih dulu. */
    public function test_bobot_tidak_tercentang_lebih_dulu_dan_tetap_wajib(): void
    {
        $html = $this->form();

        preg_match('/<fieldset class="pilihan"[^>]*>\s*<legend>Bobot.*?<\/fieldset>/s', $html, $m);
        $this->assertNotEmpty($m, 'Grup bobot tidak ditemukan.');

        $this->assertStringNotContainsString('checked', $m[0],
            'Bobot tercentang lebih dulu — tenggat dan wewenang penutupan jadi kebetulan.');
        $this->assertSame(count(config('complaint.bobot')), substr_count($m[0], 'required'),
            'Radio bobot kehilangan required.');
    }

    public function test_bobot_kosong_tetap_ditolak_server(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);
        $user = User::create([
            'name' => 'Kasir', 'email' => 'kasir'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir', 'outlet_id' => $outlet->id,
        ]);

        $this->actingAs($user)
            ->from('/complaints/create')
            ->post('/complaints', [
                'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
                'bobot' => '', 'layanan' => 'kiloan', 'description' => 'x',
                'nota_exemption' => 'belum_terbit',
            ])
            ->assertSessionHasErrors('bobot');
    }

    /**
     * Tiap radio membawa value-nya sendiri terlepas dari tercentang atau
     * tidak. Draft yang menyalin seluruh form tanpa menyaring `checked`
     * menyimpan radio TERAKHIR dalam grup — kasir memilih Ringan, draftnya
     * pulang sebagai Berat.
     */
    public function test_draft_lokal_menyaring_radio_yang_tidak_tercentang(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression(
            "/if \(f\.type === 'radio' && !f\.checked\) continue;/",
            $html,
            'Penyimpan draft kembali menyalin radio yang tidak tercentang.'
        );
    }

    /**
     * Saringan radio di atas TIDAK boleh menelan aturan checkbox yang sudah
     * ada di `main`.
     *
     * Branch ini semula menyaring radio dan checkbox dengan satu baris yang
     * sama, lalu menyimpan `f.value` untuk keduanya. Sementara itu `main`
     * memperbaiki checkbox lewat tinjauan PR #32: checkbox disimpan lewat
     * `checked`, karena `value` sebuah checkbox selalu "1" entah ia tercentang
     * atau tidak. Rebase mempertemukan keduanya di satu baris.
     *
     * Keduanya dipertahankan: radio disaring lewat `checked`, checkbox tetap
     * disimpan sebagai boolean. Test ini menuntut aturan PR #32 masih berdiri
     * sesudah saringan radio dipasang di atasnya — kalau ia hilang,
     * `tangani_di_tempat` pulang dari draft dalam keadaan tercentang dan
     * isian penyelesaian yang tersembunyi di baliknya ikut menyesatkan.
     */
    public function test_saringan_radio_tidak_membatalkan_aturan_checkbox(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression(
            "/isi\[f\.name\] = f\.type === 'checkbox' \? f\.checked : f\.value;/",
            $html,
            'Checkbox kembali disimpan lewat `value`; aturan tinjauan PR #32 hilang saat rebase.'
        );
    }
}

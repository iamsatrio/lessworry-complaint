<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua cacat pada form intake yang sama-sama membuat kolom terisi salah atau
 * tidak terpakai:
 *
 * - Select kanal tidak punya opsi kosong, jadi opsi pertama — Direct Kasir —
 *   selalu terpilih. Customer Care yang mencatat keluhan dari WhatsApp
 *   menyimpannya sebagai Direct Kasir tanpa siapa pun tahu, dan blok "Kanal
 *   masuk" di laporan jadi tidak berarti. (API-38 #4)
 *
 * - Blok nota diletakkan paling belakang meski petunjuknya sendiri berbunyi
 *   "isi ini lebih dulu". Kasir yang membaca dari atas ke bawah sudah melewati
 *   empat select dan satu textarea sebelum bertemu kalimat yang menyuruhnya
 *   kembali ke atas, jadi pengisian otomatis tidak pernah terpakai. (API-38 #5)
 */
class FormIntakeUrutanTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role): User
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $role === 'kasir' ? $outlet->id : null,
        ]);
    }

    private function form(string $role): string
    {
        return $this->actingAs($this->userAs($role))
            ->get('/complaints/create')->assertOk()->getContent();
    }

    private function selectKanal(string $html): string
    {
        preg_match('/<select id="ch".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m, 'Kolom kanal tidak ditemukan di form intake.');

        return $m[0];
    }

    public function test_kasir_membuka_form_dengan_kanal_direct_kasir(): void
    {
        $this->assertMatchesRegularExpression(
            '/<option value="kasir"[^>]*\bselected\b/',
            $this->selectKanal($this->form('kasir'))
        );
    }

    public function test_customer_care_tidak_lagi_mewarisi_direct_kasir(): void
    {
        $select = $this->selectKanal($this->form('customer_care'));

        $this->assertMatchesRegularExpression('/<option value="wa_cc"[^>]*\bselected\b/', $select);
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="kasir"[^>]*\bselected\b/',
            $select,
            'Customer Care kembali membuka form dengan kanal Direct Kasir.'
        );
    }

    public function test_peran_yang_kanalnya_tidak_bisa_disimpulkan_harus_memilih(): void
    {
        $select = $this->selectKanal($this->form('supervisor'));

        $this->assertMatchesRegularExpression(
            '/<option value="" disabled[^>]*\bselected\b/',
            $select,
            'Tanpa opsi kosong yang terpilih, opsi pertama terpilih diam-diam dan required tidak menuntut apa pun.'
        );

        foreach (array_keys(config('complaint.channels')) as $kunci) {
            $this->assertDoesNotMatchRegularExpression(
                '/<option value="'.preg_quote($kunci, '/').'"[^>]*\bselected\b/',
                $select
            );
        }
    }

    public function test_kanal_kosong_tetap_ditolak_server(): void
    {
        $this->actingAs($this->userAs('supervisor'))
            ->post('/complaints', [
                'channel' => '', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
                'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
                'nota_exemption' => 'belum_terbit',
            ])
            ->assertSessionHasErrors('channel');
    }

    public function test_nomor_nota_berada_di_atas_kolom_yang_diisinya(): void
    {
        $html = $this->form('kasir');

        $nota = strpos($html, 'id="nv"');
        $kategori = strpos($html, 'id="cat"');
        $keluhan = strpos($html, 'id="desc"');
        $nama = strpos($html, 'id="rn"');

        $this->assertNotFalse($nota, 'Kolom nomor nota hilang dari form intake.');
        $this->assertLessThan($kategori, $nota, 'Nomor nota kembali turun di bawah kategori.');
        $this->assertLessThan($keluhan, $nota);
        $this->assertLessThan($nama, $nota, 'Nomor nota harus mendahului kolom yang diisinya sendiri.');
    }

    public function test_outlet_ikut_naik_bersama_notanya(): void
    {
        $html = $this->form('customer_care');

        $this->assertLessThan(
            strpos($html, 'id="cat"'),
            strpos($html, 'id="out"'),
            'Outlet terisi dari nota, jadi tempatnya bersama nota — bukan dua layar di bawahnya.'
        );
    }
}

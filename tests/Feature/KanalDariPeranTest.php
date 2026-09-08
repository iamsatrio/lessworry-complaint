<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Select kanal tidak punya opsi kosong, jadi opsi pertama — Direct Kasir —
 * selalu terpilih. Customer Care yang mencatat keluhan dari WhatsApp
 * menyimpannya sebagai Direct Kasir tanpa siapa pun tahu, dan blok "Kanal
 * masuk" di laporan jadi tidak berarti. Akibatnya bukan kolom kosong, tapi
 * kolom terisi salah. (API-38 #4)
 *
 * Keputusan Modrić: yang disimpulkan dari peran adalah NILAI BAWAANNYA, bukan
 * kanalnya. Kanal ada tiga dan peran hanya dua — WA Outlet diterima kasir
 * juga — jadi kolomnya tetap tampil dan pilihan manual menimpa bawaan.
 */
class KanalDariPeranTest extends TestCase
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

    private function selectKanal(string $role): string
    {
        $html = $this->actingAs($this->userAs($role))
            ->get('/complaints/create')->assertOk()->getContent();

        preg_match('/<select id="ch".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m, 'Kolom kanal tidak ditemukan di form intake.');

        return $m[0];
    }

    public function test_kasir_membuka_form_dengan_kanal_direct_kasir(): void
    {
        $this->assertMatchesRegularExpression(
            '/<option value="kasir"[^>]*\bselected\b/',
            $this->selectKanal('kasir')
        );
    }

    public function test_customer_care_tidak_lagi_mewarisi_direct_kasir(): void
    {
        $select = $this->selectKanal('customer_care');

        $this->assertMatchesRegularExpression('/<option value="wa_cc"[^>]*\bselected\b/', $select);
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="kasir"[^>]*\bselected\b/',
            $select,
            'Customer Care kembali membuka form dengan kanal Direct Kasir.'
        );
    }

    /**
     * Bawaan, bukan kesimpulan: WA Outlet tidak punya peran sendiri, jadi
     * ketiga kanal harus tetap bisa dipilih siapa pun yang membuka form.
     */
    public function test_ketiga_kanal_tetap_bisa_dipilih(): void
    {
        foreach (['kasir', 'customer_care'] as $role) {
            $select = $this->selectKanal($role);

            foreach (array_keys(config('complaint.channels')) as $kunci) {
                $this->assertStringContainsString('value="'.$kunci.'"', $select);
            }
        }
    }

    public function test_isian_yang_dikembalikan_menang_atas_bawaan_peran(): void
    {
        $html = $this->actingAs($this->userAs('kasir'))
            ->withSession(['_old_input' => ['channel' => 'wa_outlet']])
            ->get('/complaints/create')->assertOk()->getContent();

        preg_match('/<select id="ch".*?<\/select>/s', $html, $m);

        $this->assertMatchesRegularExpression('/<option value="wa_outlet"[^>]*\bselected\b/', $m[0]);
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="kasir"[^>]*\bselected\b/',
            $m[0],
            'Bawaan peran menimpa isian yang sudah dipilih petugas.'
        );
    }

    /**
     * Peran yang kanalnya memang tidak bisa disimpulkan tidak boleh menerima
     * bawaan yang kebetulan — itu kesalahan yang sedang diperbaiki, hanya
     * berpindah orang.
     */
    public function test_peran_tanpa_bawaan_harus_memilih(): void
    {
        $select = $this->selectKanal('supervisor');

        $this->assertMatchesRegularExpression('/<option value="" disabled[^>]*\bselected\b/', $select);

        foreach (array_keys(config('complaint.channels')) as $kunci) {
            $this->assertDoesNotMatchRegularExpression(
                '/<option value="'.preg_quote($kunci, '/').'"[^>]*\bselected\b/',
                $select
            );
        }
    }

    public function test_peran_yang_punya_bawaan_tidak_dipaksa_memilih(): void
    {
        foreach (['kasir', 'customer_care'] as $role) {
            $this->assertStringNotContainsString(
                '— pilih kanal —',
                $this->selectKanal($role),
                'Peran yang bawaannya sudah benar tidak perlu dipaksa memilih.'
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
}

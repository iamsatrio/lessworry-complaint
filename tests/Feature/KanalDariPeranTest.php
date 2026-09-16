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
 *
 * Kolomnya kini tiga radio, bukan select (API-86 #4). Jaminannya sama persis;
 * yang berubah hanya bentuk yang diperiksa — `checked` menggantikan
 * `selected`, dan "belum memilih" tidak lagi butuh opsi kosong: pada radio ia
 * adalah keadaan yang memang tidak ada centangnya.
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

    /** Grup radio kanal, dipotong dari markup form intake. */
    private function grupKanal(string $role): string
    {
        return $this->potongGrup(
            $this->actingAs($this->userAs($role))
                ->get('/complaints/create')->assertOk()->getContent()
        );
    }

    private function potongGrup(string $html): string
    {
        preg_match('/<fieldset class="pilihan"[^>]*>\s*<legend>Masuk lewat.*?<\/fieldset>/s', $html, $m);

        $this->assertNotEmpty($m, 'Kolom kanal tidak ditemukan di form intake.');

        return $m[0];
    }

    /** Radio yang tercentang untuk satu kanal. */
    private function pola(string $kanal): string
    {
        return '/<input type="radio" name="channel" value="'.preg_quote($kanal, '/').'"[^>]*\bchecked\b/s';
    }

    public function test_kasir_membuka_form_dengan_kanal_direct_kasir(): void
    {
        $this->assertMatchesRegularExpression(
            $this->pola('kasir'),
            $this->grupKanal('kasir')
        );
    }

    public function test_customer_care_tidak_lagi_mewarisi_direct_kasir(): void
    {
        $grup = $this->grupKanal('customer_care');

        $this->assertMatchesRegularExpression($this->pola('wa_cc'), $grup);
        $this->assertDoesNotMatchRegularExpression(
            $this->pola('kasir'),
            $grup,
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
            $grup = $this->grupKanal($role);

            foreach (array_keys(config('complaint.channels')) as $kunci) {
                $this->assertStringContainsString('value="'.$kunci.'"', $grup);
            }
        }
    }

    public function test_isian_yang_dikembalikan_menang_atas_bawaan_peran(): void
    {
        $html = $this->actingAs($this->userAs('kasir'))
            ->withSession(['_old_input' => ['channel' => 'wa_outlet']])
            ->get('/complaints/create')->assertOk()->getContent();

        $grup = $this->potongGrup($html);

        $this->assertMatchesRegularExpression($this->pola('wa_outlet'), $grup);
        $this->assertDoesNotMatchRegularExpression(
            $this->pola('kasir'),
            $grup,
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
        $grup = $this->grupKanal('supervisor');

        // Tidak ada opsi kosong untuk dipilih: yang dijaga adalah tidak ada
        // SATU PUN radio yang tercentang, jadi tidak ada kanal yang tersimpan
        // diam-diam. `required` di tiap radio yang menuntut salah satunya.
        foreach (array_keys(config('complaint.channels')) as $kunci) {
            $this->assertDoesNotMatchRegularExpression($this->pola($kunci), $grup);
        }

        $this->assertSame(
            count(config('complaint.channels')),
            substr_count($grup, 'required'),
            'Radio kanal kehilangan required — supervisor bisa mengirim form tanpa memilih kanal.'
        );
    }

    public function test_peran_yang_punya_bawaan_tidak_dipaksa_memilih(): void
    {
        foreach (['kasir', 'customer_care'] as $role) {
            $this->assertSame(
                1,
                preg_match_all('/\bchecked\b/', $this->grupKanal($role)),
                'Peran yang bawaannya sudah benar harus membuka form dengan tepat satu kanal tercentang.'
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

<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Draft form intake disimpan di localStorage supaya isian tidak hilang saat
 * koneksi outlet putus. Kuncinya sempat melekat pada PERANGKAT, bukan pada
 * pengguna, dan tidak pernah dihapus saat keluar: keluhan pelanggan yang
 * diketik kasir sebelumnya muncul lagi di form kasir berikutnya — terbaca
 * petugas lain, dan berisiko tersimpan atas nama pelapor yang salah.
 *
 * Perangkat outlet dipakai bergantian, jadi ini pasti terjadi, bukan mungkin.
 */
class DraftPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function kunciDraft(string $html): string
    {
        $this->assertMatchesRegularExpression(
            '/LW_DRAFT_KEY\s*=\s*"[^"]+"/',
            $html,
            'Halaman tidak mengumumkan kunci draft, jadi kunci itu tidak bisa dibedakan per pengguna.'
        );

        preg_match('/LW_DRAFT_KEY\s*=\s*"([^"]+)"/', $html, $m);

        return $m[1];
    }

    /* ---------- 1. Kunci draft terikat pengguna ---------- */

    public function test_kunci_draft_berbeda_untuk_tiap_pengguna(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A', 'nevira_outlet_id' => '1']);

        $a = $this->kunciDraft(
            $this->actingAs($this->userAs('kasir', $outlet))->get('/complaints/create')->assertOk()->getContent()
        );
        $b = $this->kunciDraft(
            $this->actingAs($this->userAs('kasir', $outlet))->get('/complaints/create')->assertOk()->getContent()
        );

        $this->assertNotSame($a, $b,
            'Dua petugas di perangkat yang sama memakai kunci draft yang sama — isian yang satu terbaca yang lain.');

        // Kunci lama melekat pada perangkat saja; kalau masih dipakai apa
        // adanya, draft tetap tercampur walau formatnya berubah.
        $this->assertNotSame('lw_complaint_draft', $a);
        $this->assertStringStartsWith('lw_complaint_draft:', $a);

        // Kunci yang berubah tiap halaman dimuat sama saja dengan tidak
        // punya draft: isian petugas hilang pada kunjungan berikutnya.
        $tetap = $this->kunciDraft(
            $this->actingAs(User::where('email', 'like', 'kasir%')->orderByDesc('id')->first())
                ->get('/complaints/create')->assertOk()->getContent()
        );
        $this->assertSame($b, $tetap);
    }

    public function test_kunci_draft_tidak_membocorkan_id_pengguna(): void
    {
        $user = $this->userAs('customer_care');

        $kunci = $this->kunciDraft(
            $this->actingAs($user)->get('/complaints/create')->assertOk()->getContent()
        );

        $sufiks = substr($kunci, strlen('lw_complaint_draft:'));

        $this->assertNotSame(
            (string) $user->id, $sufiks,
            'Kunci draft menuliskan id pengguna apa adanya ke perangkat bersama.'
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sufiks);
    }

    /* ---------- 2. Keluar membersihkan draft ---------- */

    public function test_keluar_menitip_perintah_membersihkan_draft(): void
    {
        $this->actingAs($this->userAs('kasir'))
            ->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionHas('bersihkan_semua_draft');
    }

    public function test_halaman_masuk_membersihkan_draft_setelah_keluar(): void
    {
        $html = $this->withSession(['bersihkan_semua_draft' => true])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('lw_complaint_draft', $html,
            'Halaman masuk tidak membersihkan draft yang ditinggalkan petugas sebelumnya.');
        $this->assertStringContainsString('removeItem', $html);
    }

    public function test_halaman_masuk_biasa_tidak_membuang_draft(): void
    {
        // Sesi habis di tengah pengisian juga mendarat di /login. Draftnya
        // justru harus selamat — yang dibuang hanya milik petugas yang keluar
        // dengan sengaja.
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('removeItem', $html);
    }

    /* ---------- 3. Draft hanya hilang setelah benar-benar tersimpan ---------- */

    public function test_draft_tidak_dihapus_hanya_karena_form_dikirim(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        // Menghapus draft pada 'submit' berarti draft hilang sebelum ada
        // kepastian complaint tersimpan — persis kasus sesi mati.
        $this->assertDoesNotMatchRegularExpression(
            "/addEventListener\(\s*'submit'[\s\S]{0,160}?removeItem/",
            $html,
            'Draft dihapus saat form dikirim, padahal belum tentu tersimpan.'
        );
    }

    public function test_draft_dibersihkan_setelah_complaint_benar_benar_tersimpan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A', 'nevira_outlet_id' => '1']);
        $kasir = $this->userAs('kasir', $outlet);

        $this->actingAs($kasir)->post('/complaints', [
            'channel' => 'kasir', 'reporter_name' => 'Budi', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => 'Noda tidak hilang',
            'nota_exemption' => 'lebih_sebulan',
        ])->assertSessionHas('bersihkan_draft');

        $html = $this->actingAs($kasir)
            ->withSession(['bersihkan_draft' => true])
            ->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('removeItem', $html,
            'Complaint sudah tersimpan tapi draftnya tetap tertinggal di perangkat.');
    }

    /* ---------- 4. Draft yang dipulihkan tidak boleh senyap ---------- */

    public function test_draft_lama_ditawarkan_lebih_dulu_bukan_diisi_diam_diam(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        $this->assertStringContainsString('id="draft-tawar"', $html,
            'Tidak ada pemberitahuan bahwa isian yang tampil berasal dari draft lama.');
        $this->assertStringContainsString('id="draft-lanjut"', $html);
        $this->assertStringContainsString('id="draft-buang"', $html);
    }
}

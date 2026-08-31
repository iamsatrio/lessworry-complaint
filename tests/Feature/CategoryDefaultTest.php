<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kategori tidak boleh terisi sebelum petugas menyentuhnya.
 *
 * Select tanpa opsi kosong selalu memilih opsi pertama, jadi `required` tidak
 * menahan apa pun: kasir yang buru-buru menyimpan menghasilkan complaint
 * berlabel kategori pertama, apa pun isi keluhannya. Kolom kosong masih bisa
 * dibaca sebagai "tidak tahu"; kategori yang salah terlihat seperti data yang
 * benar dan ikut masuk ke laporan, ke SLA, dan ke pola keluhan.
 */
class CategoryDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function selectKategori(string $html): string
    {
        preg_match('/<select id="cat".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m, 'Kolom kategori tidak ditemukan di form intake.');

        return $m[0];
    }

    public function test_tidak_ada_kategori_yang_terpilih_sebelum_dipilih(): void
    {
        $html = $this->actingAs($this->userAs('kasir'))
            ->get('/complaints/create')->assertOk()->getContent();

        $select = $this->selectKategori($html);

        foreach (array_keys(config('complaint.categories')) as $kunci) {
            $this->assertDoesNotMatchRegularExpression(
                '/<option value="'.preg_quote($kunci, '/').'"[^>]*\bselected\b/',
                $select,
                "Kategori '$kunci' sudah terpilih sebelum kasir menyentuhnya — ".
                'complaint salah kategori akan tersimpan tanpa ada yang curiga.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*\bdisabled\b[^>]*\bselected\b/',
            $select,
            'Tidak ada opsi kosong yang terpilih, jadi required tidak menuntut pilihan apa pun.'
        );
        $this->assertStringContainsString('pilih kategori', $select);
    }

    public function test_kategori_yang_sudah_diisi_tetap_kembali_setelah_validasi_gagal(): void
    {
        $html = $this->actingAs($this->userAs('kasir'))
            ->withSession(['_old_input' => ['category' => 'barang_hilang']])
            ->get('/complaints/create')->assertOk()->getContent();

        $select = $this->selectKategori($html);

        $this->assertMatchesRegularExpression(
            '/<option value="barang_hilang"[^>]*\bselected\b/', $select,
            'Kategori yang sudah dipilih petugas hilang saat form dikembalikan.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<option value=""[^>]*\bselected\b/', $select
        );
    }

    public function test_server_menolak_complaint_tanpa_kategori(): void
    {
        // Opsi kosong itu hanya penahan di layar. Yang menentukan tetap server.
        $outlet = Outlet::create(['name' => 'Outlet A', 'nevira_outlet_id' => '1']);

        $this->actingAs($this->userAs('kasir', $outlet))
            ->from('/complaints/create')
            ->post('/complaints', [
                'channel' => 'kasir', 'reporter_name' => 'Budi', 'category' => '',
                'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'Noda tidak hilang',
                'nota_exemption' => 'lebih_sebulan',
            ])
            ->assertSessionHasErrors('category');

        $this->assertSame(0, \App\Models\Complaint::count());
    }
}

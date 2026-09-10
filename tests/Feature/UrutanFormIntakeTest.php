<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blok nomor nota diletakkan paling belakang meski petunjuknya sendiri
 * berbunyi "isi ini lebih dulu — nama dan telepon pelapor akan terisi sendiri".
 *
 * Terukur di 390px: nota di y=1362, dua layar di bawah titik masuk. Kasir yang
 * membaca dari atas ke bawah sudah melewati empat select dan satu textarea
 * sebelum bertemu kalimat yang menyuruhnya kembali ke atas — jadi pengisian
 * otomatis yang sudah dibangun tidak pernah terpakai. (API-38 #5)
 */
class UrutanFormIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function form(string $role): string
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $role === 'kasir' ? $outlet->id : null,
        ]);

        return $this->actingAs($user)->get('/complaints/create')->assertOk()->getContent();
    }

    public function test_nomor_nota_berada_di_atas_kolom_yang_diisinya(): void
    {
        $html = $this->form('kasir');

        $nota = strpos($html, 'id="nv"');

        $this->assertNotFalse($nota, 'Kolom nomor nota hilang dari form intake.');
        $this->assertLessThan(strpos($html, 'id="cat"'), $nota, 'Nomor nota kembali turun di bawah kategori.');
        $this->assertLessThan(strpos($html, 'id="desc"'), $nota);
        $this->assertLessThan(
            strpos($html, 'id="rn"'),
            $nota,
            'Nomor nota harus mendahului kolom yang diisinya sendiri.'
        );
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

    public function test_alasan_tanpa_nota_tetap_bersebelahan_dengan_notanya(): void
    {
        $html = $this->form('kasir');

        $this->assertLessThan(
            strpos($html, 'id="cat"'),
            strpos($html, 'id="exempt"'),
            'Nota dan alasan tanpa nota saling meniadakan; keduanya harus dibaca bersamaan.'
        );
    }

    /** Memindahkan blok tidak boleh menghapus kolomnya. */
    public function test_semua_kolom_form_intake_tetap_ada(): void
    {
        $html = $this->form('customer_care');

        foreach (['nv', 'exempt', 'out', 'cat', 'sub', 'bob', 'lay', 'desc', 'att', 'ch', 'rn', 'rp'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, 'Kolom '.$id.' hilang saat blok dipindah.');
        }
    }

    public function test_kasir_tetap_tidak_menerima_pilihan_outlet(): void
    {
        $this->assertStringNotContainsString(
            'id="out"',
            $this->form('kasir'),
            'Kasir hanya boleh mencatat untuk outletnya sendiri.'
        );
    }
}

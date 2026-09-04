<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Aturan `input{width:100%;min-height:48px}` di tata letak dibuat untuk kolom
 * teks, dan tidak pernah bertemu kotak centang sampai API-19 dibangun. Begitu
 * bertemu, kotaknya memuai memenuhi barisnya — satrio menemukannya di layar,
 * bukan di test, karena tidak ada test yang melihat ukuran.
 *
 * Test ini menjaga pengecualiannya tetap ada. Ia tidak mengukur piksel; ia
 * memastikan aturan yang menyebabkannya tidak kembali diam-diam.
 */
class CheckboxSizeTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('views/layouts/app.blade.php'));
    }

    public function test_aturan_kolom_teks_tidak_mengenai_kotak_centang(): void
    {
        $css = $this->css();

        // Baris yang memberi lebar penuh dan tinggi 48px harus menyaring tipe.
        $this->assertMatchesRegularExpression(
            '/input:not\(\[type=checkbox\]\):not\(\[type=radio\]\),select,textarea\{[^}]*width:100%/',
            $css,
            'Aturan lebar-penuh kembali mengenai kotak centang.'
        );
    }

    public function test_kotak_centang_punya_ukurannya_sendiri(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('input[type=checkbox],input[type=radio]{', $css);
        $this->assertMatchesRegularExpression(
            '/input\[type=checkbox\],input\[type=radio\]\{[^}]*min-height:0/',
            $css,
            'Tanpa min-height:0, tinggi 48px masih menang.'
        );
    }

    public function test_sasaran_sentuh_pindah_ke_label_bukan_hilang(): void
    {
        $css = $this->css();

        // Kotaknya mengecil ke 18px; yang disentuh kasir adalah barisnya.
        $this->assertMatchesRegularExpression(
            '/label\.pick\{[^}]*min-height:44px/',
            $css,
            'Kotak mengecil tanpa sasaran sentuh pengganti.'
        );
    }

    public function test_baris_pemilihan_pelaku_memakai_label_pick(): void
    {
        $markup = file_get_contents(resource_path('views/complaints/_staff.blade.php'));

        $this->assertStringContainsString('<label class="pick"', $markup);
        $this->assertStringContainsString('type="checkbox" name="pelaku[]"', $markup);
    }
}

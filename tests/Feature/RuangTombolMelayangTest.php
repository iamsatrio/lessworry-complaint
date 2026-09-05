<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Tombol melayang "Catat Complaint" berposisi tetap di dasar layar. Tanpa
 * ruang bawah, selalu ada satu kartu complaint di baliknya pada setiap posisi
 * gulir — dan jempol yang mengarah ke kartu paling bawah justru menekan
 * "Catat Complaint".
 *
 * Penyebabnya bukan padding yang belum pernah ada: aturan `.fab` sudah
 * menyetel `main{padding-bottom:104px}`, lalu blok `@media(max-width:820px)`
 * di bawahnya menyetel ulang `main{padding:...60px}` dan menang karena datang
 * belakangan. (API-38 #3)
 */
class RuangTombolMelayangTest extends TestCase
{
    public function test_aturan_main_terakhir_menyisakan_ruang_untuk_tombol(): void
    {
        $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

        preg_match_all('/main\{padding:[^}]*\}/', $css, $m);

        $this->assertNotEmpty($m[0], 'Aturan padding main hilang.');

        $this->assertMatchesRegularExpression(
            '/padding:22px 16px 104px/',
            end($m[0]),
            'Aturan main terakhir menyisakan ruang bawah kurang dari tinggi tombol melayang.'
        );
    }

    public function test_tidak_ada_lagi_dua_aturan_ruang_bawah_yang_bertabrakan(): void
    {
        $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringNotContainsString(
            'main{padding-bottom:104px}',
            $css,
            'Aturan padding-bottom terpisah kembali dipasang dan akan ditimpa aturan main{padding} di bawahnya.'
        );
    }
}

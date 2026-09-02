<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ukuran kolom isian tidak bisa dilihat test PHP — yang bisa dijaga adalah
 * BENTUK aturannya.
 *
 * Cacatnya nyata dan sempat terkirim: aturan umum kolom isian memakai
 * pemilih `input` polos, jadi `width:100%` dan `min-height:48px` ikut kena
 * checkbox. Di dalam label yang display:flex, kotak centangnya memuai jadi
 * 216x48 alih-alih 18x18. Tidak ada test yang melihatnya, karena servernya
 * tetap membalas 200.
 *
 * Aturan itu tidak pernah bertemu checkbox sampai API-19: sampai saat itu
 * tidak ada satu pun checkbox di seluruh aplikasi. Begitu API-26 menambah
 * centang "Sudah saya tangani di tempat" ke form intake, cacat yang sama
 * akan muncul di halaman tersibuk — kecuali aturannya dijaga di sini.
 */
class FormControlSizingTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
    }

    public function test_aturan_umum_kolom_isian_tidak_kena_checkbox_dan_radio(): void
    {
        $css = $this->css();

        // Aturan yang memberi lebar penuh harus menyaring tipe lebih dulu.
        $this->assertStringContainsString(
            'input:not([type=checkbox]):not([type=radio]),select,textarea{width:100%',
            $css,
            'Aturan kolom isian kembali memakai pemilih input polos — checkbox akan memuai memenuhi barisnya lagi.'
        );

        // Dan tidak boleh ada yang menghidupkannya lagi lewat pintu belakang.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\]\w-])input\s*(?:,[^{\n]*)?\{[^}]*width:\s*100%/',
            $css,
            'Ada aturan bagi `input` polos yang memberi width:100% — itu mengenai checkbox juga.'
        );
    }

    public function test_checkbox_dan_radio_punya_ukurannya_sendiri(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/input\[type=checkbox\],input\[type=radio\]\{[^}]*width:18px[^}]*height:18px[^}]*min-height:0/',
            $css,
            'Checkbox dan radio kehilangan ukurannya sendiri.'
        );

        // min-height:0 wajib: tanpa itu min-height:48px dari aturan lain —
        // atau dari peramban — mengembalikan kotak raksasa.
        $this->assertStringContainsString('accent-color:var(--teal)', $css,
            'Centangnya kembali memakai biru bawaan peramban, bukan warna merek.');
    }

    /**
     * min-height:48px pada kolom teks itu sasaran sentuh untuk kasir di
     * layar sentuh. Mengecilkan kotaknya jadi 18px menghapusnya, jadi
     * sasarannya harus pindah ke label — bukan hilang.
     */
    public function test_label_checkbox_tetap_jadi_sasaran_sentuh(): void
    {
        $this->assertMatchesRegularExpression(
            '/label:has\(>\s*input\[type=checkbox\]\)[^{]*\{[^}]*min-height:44px/',
            $this->css(),
            'Sasaran sentuh untuk checkbox hilang: kotaknya kecil dan labelnya tidak menggantikan.'
        );
    }

    /**
     * Kalau suatu hari ada checkbox yang TIDAK dibungkus label, sasaran
     * sentuhnya tidak ada penggantinya. Ini bukan larangan gaya — ini yang
     * membuat aturan di atas cukup.
     */
    public function test_setiap_checkbox_dibungkus_label(): void
    {
        $pelanggar = [];

        $berkas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($berkas as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = (string) file_get_contents($file->getPathname());

            preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/', $isi, $cocok, PREG_OFFSET_CAPTURE);

            foreach ($cocok[0] as [$tag, $posisi]) {
                // Sebuah label masih terbuka di titik ini kalau <label> yang
                // dibuka sebelumnya lebih banyak daripada yang sudah ditutup.
                $sebelum = substr($isi, 0, $posisi);

                if (substr_count($sebelum, '<label') <= substr_count($sebelum, '</label>')) {
                    $pelanggar[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], array_unique($pelanggar),
            'Checkbox di luar <label>: kotaknya 18px dan tidak ada yang menggantikan sasaran sentuhnya — '
            .implode(', ', array_unique($pelanggar)));
    }
}

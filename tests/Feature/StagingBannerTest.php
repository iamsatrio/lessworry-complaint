<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penanda lingkungan uji. (API-27)
 *
 * Satu-satunya pembeda di layar antara server percobaan dan server sungguhan.
 * Tanpa itu, complaint pelanggan sungguhan bisa ditutup di staging — atau
 * lebih buruk, complaint uji dikira nyata dan pelanggannya ditelepon.
 */
class StagingBannerTest extends TestCase
{
    use RefreshDatabase;

    private const PITA = 'Lingkungan uji';

    public function test_staging_memunculkan_pita_lingkungan_uji(): void
    {
        config(['app.env' => 'staging']);

        $this->get('/login')
            ->assertOk()
            ->assertSee(self::PITA, false);
    }

    public function test_produksi_tidak_memunculkan_pita(): void
    {
        config(['app.env' => 'production']);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee(self::PITA, false);
    }

    public function test_pita_muncul_sebelum_login_juga(): void
    {
        // Halaman login adalah yang pertama dilihat orang. Kalau pitanya baru
        // muncul setelah masuk, penandanya datang terlambat.
        config(['app.env' => 'staging']);

        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('class="envbar"', $html);
    }
}

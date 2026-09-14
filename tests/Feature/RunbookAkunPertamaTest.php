<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Runbook deploy menyuruh membuat akun pertama lewat tinker, dan peran yang
 * disebutnya harus peran yang benar-benar bisa membuat akun berikutnya.
 * (API-57 nomor 3)
 *
 * Sebelumnya runbook menyebut `supervisor`. Pengelolaan pengguna dijaga
 * User::canManageUsers(), yang hanya mengizinkan `admin` — jadi akun pertama
 * itu bisa masuk tapi tidak bisa menambah siapa pun, dan seluruh tim tertahan
 * di satu akun pada malam deploy.
 *
 * Test ini menuntut hubungannya, bukan kata-katanya: peran apa pun yang
 * tertulis di potongan tinker itu diuji ke canManageUsers() yang sungguhan.
 * Kalau kelak wewenangnya pindah peran, yang jatuh test ini — bukan tim yang
 * sedang deploy jam sebelas malam.
 */
class RunbookAkunPertamaTest extends TestCase
{
    private const RUNBOOK = 'docs/deploy-care-lessworry.md';

    public function test_peran_akun_pertama_di_runbook_bisa_mengelola_pengguna(): void
    {
        $isi = (string) file_get_contents(base_path(self::RUNBOOK));

        $this->assertMatchesRegularExpression("/\\\$u->role = '([a-z_]+)';/", $isi,
            self::RUNBOOK.' tidak lagi memuat potongan pembuatan akun pertama');

        preg_match_all("/\\\$u->role = '([a-z_]+)';/", $isi, $m);

        $this->assertNotEmpty($m[1]);

        foreach ($m[1] as $peran) {
            $user = new User(['role' => $peran]);

            $this->assertTrue($user->canManageUsers(),
                'Runbook menyuruh membuat akun pertama sebagai `'.$peran.'`, tapi peran itu '
                .'tidak bisa membuka halaman Pengguna — akun pertama tidak akan bisa '
                .'menambah akun siapa pun.');
        }
    }
}

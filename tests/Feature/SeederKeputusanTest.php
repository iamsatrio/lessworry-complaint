<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAudit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua keputusan API-57 yang sebelumnya tidak terjaga apa pun.
 *
 * 1. Peran di daftar seeder adalah DEKLARASI — ia berlaku tiap kali seeder
 *    jalan. Yang berubah bukan aturannya melainkan diamnya: pengembalian
 *    peran sekarang tercatat di jejak audit dan dicetak ke layar.
 * 2. `kasir@`, `produksi@`, dan `kurir@` di `lessworry.id` diblokir permanen,
 *    dan karena itu README WAJIB mengatakannya — sebelumnya README justru
 *    menyuruh Admin memakai alamat semacam itu.
 */
class SeederKeputusanTest extends TestCase
{
    use RefreshDatabase;

    /* ---------- 1. Peran dikembalikan, tapi tidak lagi diam-diam ---------- */

    public function test_peran_yang_diturunkan_dikembalikan_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tsulasa = User::where('email', 'tsulasa@lessworry.id')->firstOrFail();
        $this->assertSame('admin', $tsulasa->role);

        // Admin menurunkan perannya lewat halaman Pengguna.
        $tsulasa->forceFill(['role' => 'supervisor'])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('admin', $tsulasa->fresh()->role,
            'Daftar akun seeder adalah deklarasi: ia harus tetap berlaku tiap deploy.');
    }

    /**
     * Gagal sebelum API-57 nomor 1: perannya memang kembali, tapi tanpa satu
     * jejak pun — dan yang kembali peran tertinggi di sistem.
     */
    public function test_pengembalian_peran_tercatat_di_jejak_audit(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tsulasa = User::where('email', 'tsulasa@lessworry.id')->firstOrFail();
        $tsulasa->forceFill(['role' => 'supervisor'])->save();

        $this->seed(DatabaseSeeder::class);

        $jejak = UserAudit::where('user_id', $tsulasa->id)
            ->where('action', 'peran_disetel_ulang_seeder')
            ->first();

        $this->assertNotNull($jejak,
            'Peran dikembalikan tanpa jejak — tidak ada yang bisa menjawab kenapa perannya naik lagi.');

        // Peran sebelumnya ikut tercatat: tanpa itu jejaknya tidak bisa
        // menjawab apa yang sebenarnya diubah orang.
        $this->assertStringContainsString('supervisor', (string) $jejak->detail);
        $this->assertStringContainsString('admin', (string) $jejak->detail);

        // Seeder tidak punya akun, dan mengarang satu lebih buruk daripada
        // mengosongkannya.
        $this->assertNull($jejak->actor_id);
        $this->assertSame('Seeder (php artisan db:seed)', $jejak->actorLabel());
        $this->assertSame('Peran dikembalikan oleh seeder', $jejak->actionLabel());
    }

    public function test_seeder_yang_tidak_mengubah_peran_tidak_menulis_jejak(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0,
            UserAudit::where('action', 'peran_disetel_ulang_seeder')->count(),
            'Deploy yang tidak mengubah apa pun tidak boleh menumpuk jejak kosong.');
    }

    /* ---------- 2. Alamat yang diblokir permanen harus tertulis ---------- */

    /**
     * Jalan yang dipilih untuk API-57 nomor 2 adalah "blokir selamanya", dan
     * issue-nya menetapkan satu syarat untuk jalan itu: README wajib
     * mengatakannya. Test ini syarat itu.
     */
    public function test_readme_memperingatkan_ketiga_alamat_yang_diblokir(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));

        foreach (['kasir@lessworry.id', 'produksi@lessworry.id', 'kurir@lessworry.id'] as $email) {
            $this->assertStringContainsString($email, $readme,
                'README tidak menyebut '.$email.', padahal seeder mematikannya tiap kali jalan.');
        }

        $this->assertStringContainsString('Jangan memakai', $readme,
            'README menyebut alamatnya tapi tidak melarang memakainya.');
    }

    /**
     * Dan larangannya harus benar: akun yang dibuat di salah satu alamat itu
     * memang mati pada seeder berikutnya. Kalau kelak perilakunya berubah,
     * yang jatuh test ini — bukan kasir yang tidak bisa masuk.
     */
    public function test_akun_di_alamat_terblokir_memang_mati_pada_seeder_berikutnya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kasir = User::create([
            'name' => 'Kasir Tebet', 'email' => 'kasir@lessworry.id',
            'password' => 'PasswordPilihanSendiri123', 'role' => 'kasir',
            'is_active' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse((bool) $kasir->fresh()->is_active,
            'README memperingatkan akun ini akan mati; kalau ternyata hidup, peringatannya yang salah.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jaring pengaman untuk API-21 — peran kustom.
 *
 * Kriteria selesai nomor 1 issue itu berbunyi: sesudah migrasi, tiap peran
 * lama harus menjawab IDENTIK untuk kesepuluh metode wewenang, dan ada
 * testnya yang membandingkan sebelum-sesudah.
 *
 * Masalahnya, "sebelum" tidak bisa diukur lagi setelah kolom `users.role`
 * bertipe teks hilang. Jadi jawabannya diambil SEKARANG, dari kode yang
 * berjalan hari ini, dan ditulis di sini sebagai tabel harfiah. Nilainya
 * dihasilkan dengan memanggil metodenya sungguhan — bukan diketik dari
 * membaca kode, yang akan mengunci apa yang KUKIRA terjadi alih-alih apa
 * yang benar-benar terjadi.
 *
 * Berkas ini sengaja berdiri sendiri dan tidak menyentuh apa pun. Ia bisa
 * masuk `main` mendahului API-21, dan justru itu gunanya: ia harus hijau
 * SEBELUM migrasinya ditulis, supaya "identik" punya pembanding yang tidak
 * ikut berubah bersama perubahannya.
 *
 * Kalau API-21 membuat satu sel di tabel ini bergeser, yang jatuh test ini —
 * dan pergeserannya terbaca sebagai satu baris, bukan sebagai sepuluh test
 * perilaku yang merah entah kenapa. Tidak seorang pun boleh bisa melakukan
 * LEBIH BANYAK sesudah migrasi; sel yang berubah dari false ke true adalah
 * kegagalan yang paling mahal, dan paling sulit terlihat tanpa tabel.
 *
 * Batas kompensasi dan batas bobot ikut dikunci di sini — keduanya sumbu
 * wewenang yang tidak bisa diratakan jadi centang izin, dan itu jebakan yang
 * issue API-21 sendiri sebut menentukan seluruh rancangannya.
 */
class WewenangPeranSaatIniTest extends TestCase
{
    /**
     * Jawaban setiap metode wewenang untuk setiap peran, apa adanya hari ini.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tabelWewenang(): array
    {
        return [
            'admin' => [
                'canCreateComplaint' => true,
                'seesAllOutlets' => true,
                'canAssignResponsibility' => true,
                'canSeeStaffAttribution' => true,
                'canManageUsers' => true,
                'canManageDivisions' => true,
                'canResolve.ringan' => true,
                'canPause.ringan' => true,
                'canReopen.ringan' => true,
                'canResolve.sedang' => true,
                'canPause.sedang' => true,
                'canReopen.sedang' => true,
                'canResolve.berat' => true,
                'canPause.berat' => true,
                'canReopen.berat' => true,
                'canResolve.null' => true,
                'compensation_limit' => PHP_INT_MAX,
                'bisaDitugasi' => true,
            ],
            'supervisor' => [
                'canCreateComplaint' => true,
                'seesAllOutlets' => true,
                'canAssignResponsibility' => true,
                'canSeeStaffAttribution' => true,
                'canManageUsers' => false,
                'canManageDivisions' => false,
                'canResolve.ringan' => true,
                'canPause.ringan' => true,
                'canReopen.ringan' => true,
                'canResolve.sedang' => true,
                'canPause.sedang' => true,
                'canReopen.sedang' => true,
                'canResolve.berat' => true,
                'canPause.berat' => true,
                'canReopen.berat' => true,
                'canResolve.null' => true,
                'compensation_limit' => PHP_INT_MAX,
                'bisaDitugasi' => true,
            ],
            'customer_care' => [
                'canCreateComplaint' => true,
                'seesAllOutlets' => true,
                'canAssignResponsibility' => true,
                'canSeeStaffAttribution' => true,
                'canManageUsers' => false,
                'canManageDivisions' => false,
                'canResolve.ringan' => true,
                'canPause.ringan' => true,
                'canReopen.ringan' => true,
                'canResolve.sedang' => true,
                'canPause.sedang' => true,
                'canReopen.sedang' => true,
                'canResolve.berat' => true,
                'canPause.berat' => true,
                'canReopen.berat' => true,
                'canResolve.null' => true,
                'compensation_limit' => 200000,
                'bisaDitugasi' => true,
            ],
            'kasir' => [
                'canCreateComplaint' => true,
                'seesAllOutlets' => false,
                'canAssignResponsibility' => false,
                'canSeeStaffAttribution' => false,
                'canManageUsers' => false,
                'canManageDivisions' => false,
                'canResolve.ringan' => true,
                'canPause.ringan' => true,
                'canReopen.ringan' => true,
                'canResolve.sedang' => false,
                'canPause.sedang' => false,
                'canReopen.sedang' => false,
                'canResolve.berat' => false,
                'canPause.berat' => false,
                'canReopen.berat' => false,
                'canResolve.null' => false,
                'compensation_limit' => 50000,
                'bisaDitugasi' => true,
            ],
            'divisi' => [
                'canCreateComplaint' => false,
                'seesAllOutlets' => false,
                'canAssignResponsibility' => false,
                'canSeeStaffAttribution' => false,
                'canManageUsers' => false,
                'canManageDivisions' => false,
                'canResolve.ringan' => false,
                'canPause.ringan' => false,
                'canReopen.ringan' => false,
                'canResolve.sedang' => false,
                'canPause.sedang' => false,
                'canReopen.sedang' => false,
                'canResolve.berat' => false,
                'canPause.berat' => false,
                'canReopen.berat' => false,
                'canResolve.null' => false,
                'compensation_limit' => 0,
                'bisaDitugasi' => false,
            ],
        ];
    }

    /** @return array<string,array{0:string}> */
    public static function peran(): array
    {
        $kasus = [];

        foreach (array_keys(self::tabelWewenang()) as $peran) {
            $kasus[$peran] = [$peran];
        }

        return $kasus;
    }

    /**
     * Metode yang tidak bergantung pada complaint mana pun.
     */
    #[DataProvider('peran')]
    public function test_wewenang_tanpa_objek_tidak_bergeser(string $peran): void
    {
        $harapan = self::tabelWewenang()[$peran];
        $user = new User(['role' => $peran]);

        foreach ([
            'canCreateComplaint', 'seesAllOutlets', 'canAssignResponsibility',
            'canSeeStaffAttribution', 'canManageUsers', 'canManageDivisions',
        ] as $metode) {
            $this->assertSame($harapan[$metode], $user->$metode(),
                $peran.'::'.$metode.'() bergeser dari perilaku sebelum API-21.');
        }
    }

    /**
     * Sumbu bobot — menutup, menjeda, dan membuka kembali, untuk ketiga bobot.
     *
     * Ini yang paling mudah hilang saat wewenang diratakan jadi daftar izin
     * datar: `complaint.close` yang dicentang tidak memuat "hanya Ringan".
     */
    #[DataProvider('peran')]
    public function test_batas_bobot_tidak_bergeser(string $peran): void
    {
        $harapan = self::tabelWewenang()[$peran];
        $user = new User(['role' => $peran]);

        foreach (['ringan', 'sedang', 'berat'] as $bobot) {
            $complaint = new Complaint(['bobot' => $bobot]);

            foreach (['canResolve', 'canPause', 'canReopen'] as $metode) {
                $this->assertSame(
                    $harapan[$metode.'.'.$bobot],
                    $user->$metode($complaint),
                    $peran.'::'.$metode.'() untuk bobot '.$bobot.' bergeser dari perilaku sebelum API-21.'
                );
            }
        }

        // Tanpa complaint sama sekali — dipakai tampilan untuk memutuskan
        // apakah tombolnya dirender. Jawabannya tidak boleh lebih longgar
        // daripada jawaban dengan objek.
        $this->assertSame($harapan['canResolve.null'], $user->canResolve(null),
            $peran.'::canResolve(null) bergeser.');
    }

    /**
     * Batas kompensasi hidup di config, bukan di User — API-21 memindahkannya
     * ke kolom peran. Nilainya tidak boleh berubah saat pindah.
     */
    #[DataProvider('peran')]
    public function test_batas_kompensasi_tidak_bergeser(string $peran): void
    {
        $this->assertSame(
            self::tabelWewenang()[$peran]['compensation_limit'],
            config('complaint.compensation_limit.'.$peran),
            'Batas kompensasi '.$peran.' bergeser dari nilai sebelum API-21.'
        );
    }

    /** Peran yang boleh MENERIMA penugasan complaint. */
    #[DataProvider('peran')]
    public function test_peran_yang_bisa_ditugasi_tidak_bergeser(string $peran): void
    {
        $this->assertSame(
            self::tabelWewenang()[$peran]['bisaDitugasi'],
            in_array($peran, User::peranBisaDitugasi(), true),
            'Keanggotaan '.$peran.' di peranBisaDitugasi() bergeser.'
        );
    }

    /**
     * Tabelnya harus memuat KELIMA peran. Tanpa penegasan ini, menghapus satu
     * baris dari tabel membuat seluruh berkas hijau — peran yang tidak
     * diperiksa tidak bisa gagal.
     */
    public function test_kelima_peran_ada_di_tabel(): void
    {
        $this->assertSame(
            ['admin', 'supervisor', 'customer_care', 'kasir', 'divisi'],
            array_keys(self::tabelWewenang())
        );

        // Dan tiap peran memuat kolom yang lengkap: 18 sel, bukan sebagian.
        foreach (self::tabelWewenang() as $peran => $baris) {
            $this->assertCount(18, $baris, 'Baris '.$peran.' tidak lengkap.');
        }
    }
}

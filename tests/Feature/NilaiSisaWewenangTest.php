<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nilai sisa `outlet_id` dan `division` tidak berlaku sebagai wewenang.
 * (API-133, lanjutan API-132)
 *
 * API-132 menghentikan seeder menulis `division` dan `outlet_id` pada akun
 * yang sudah ada, dan menerima akibatnya secara sadar: akun yang dulu terikat
 * satu outlet lalu naik jadi `admin` atau `customer_care` MENYIMPAN nilai
 * lamanya sampai seseorang membersihkannya dengan tangan lewat halaman
 * Pengguna. Pasangannya ada di
 * `SeederKeputusanTest::test_seeder_tidak_membersihkan_outlet_sisa_pada_akun_yang_naik_peran`,
 * yang memaku bahwa nilai itu memang TIDAK dibersihkan otomatis.
 *
 * Yang dijaga DI SINI adalah sisi satunya: selama nilai itu masih tersimpan,
 * ia tidak boleh menyempitkan apa pun. Cakupan ditentukan PERAN, dan kolom
 * outlet/divisi hanya dibaca oleh peran yang memang terikat padanya — `kasir`
 * untuk outlet, `divisi` untuk divisi.
 *
 * Kenapa penjaganya baru ditulis sekarang: sebelum API-132, seeder
 * membersihkan kedua kolom itu tiap deploy, jadi pembaca baru yang lupa
 * bergerbang peran tidak pernah punya nilai sisa untuk salah dibaca —
 * kesalahannya tersembunyi dengan sendirinya. Sesudah API-132 nilai sisanya
 * bertahan. Yang dulu tidak bisa muncul sekarang bisa, dan tidak ada satu
 * test pun yang menangkapnya.
 *
 * Kalau berkas ini merah, yang rusak bukan berkas ini. Yang rusak adalah
 * sebuah pembaca `outlet_id` atau `division` milik User yang lupa memeriksa
 * peran lebih dulu — dan akibatnya seorang Admin melihat SEBAGIAN jaringan
 * tanpa satu pun pesan galat memberitahunya bahwa ada yang hilang.
 */
class NilaiSisaWewenangTest extends TestCase
{
    use RefreshDatabase;

    /** Divisi sisa yang dipakai di seluruh berkas ini. */
    private const DIVISI_SISA = 'produksi';

    /**
     * Peran yang melihat seluruh jaringan tanpa peduli isi kolom outlet dan
     * divisinya. `supervisor` sengaja tidak ikut: ia tidak pernah lahir
     * terikat outlet di jalur mana pun, jadi tidak punya nilai sisa untuk
     * dijaga. Kedua peran di bawah punya — keduanya bisa berasal dari akun
     * kasir outlet yang dinaikkan.
     *
     * @return array<string,array{string}>
     */
    public static function peranYangMelihatSeluruhJaringan(): array
    {
        return [
            'admin' => ['admin'],
            'customer_care' => ['customer_care'],
        ];
    }

    /**
     * Akun ber-peran $role yang MASIH menyimpan outlet dan divisi lamanya.
     *
     * Nilai sisanya ditulis lewat forceFill, persis seperti keadaannya di
     * produksi: kolomnya terisi dari masa lalu akun itu, bukan dari peran
     * yang dipegangnya sekarang.
     */
    private function akunDenganNilaiSisa(string $role, Outlet $outletLama): User
    {
        $user = User::create([
            'name' => 'Naik Peran',
            'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123',
            'role' => $role,
        ]);

        $user->forceFill([
            'outlet_id' => $outletLama->id,
            'division' => self::DIVISI_SISA,
        ])->save();

        $user = $user->fresh();

        // Fixture-nya sendiri ikut dipaku. Tanpa ini, kode pembersih yang
        // diam-diam masuk membuat seluruh berkas ini HIJAU tanpa menguji
        // apa pun — nilai sisanya hilang, jadi tidak ada yang bisa salah
        // dibaca. Test yang lolos karena kehilangan subjeknya lebih buruk
        // daripada test yang tidak ada.
        $this->assertSame(
            $outletLama->id,
            $user->outlet_id,
            'Nilai sisa outlet hilang sebelum diuji: ada kode pembersih yang menghapusnya, dan itu persis cacat yang API-132 buang.'
        );
        $this->assertSame(
            self::DIVISI_SISA,
            $user->division,
            'Nilai sisa divisi hilang sebelum diuji: ada kode pembersih yang menghapusnya, dan itu persis cacat yang API-132 buang.'
        );

        return $user;
    }

    private function complaint(?Outlet $outlet, ?string $diteruskanKe = null): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir',
            'reporter_name' => 'Pelapor',
            'category' => 'kurang_bersih',
            'bobot' => 'sedang',
            'layanan' => 'kiloan',
            'description' => 'x',
            'outlet_id' => $outlet?->id,
            'forwarded_division' => $diteruskanKe,
        ]);

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse('2026-08-03 09:00');
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function tagihan(?Outlet $outlet): Tagihan
    {
        return Tagihan::create([
            'nama' => 'Tagihan '.uniqid(),
            'jumlah' => 500000,
            'jatuh_tempo_hari' => 5,
            'pengulangan' => 'bulanan',
            'outlet_id' => $outlet?->id,
        ]);
    }

    /* ---------- Complaint::scopeVisibleTo ---------- */

    #[DataProvider('peranYangMelihatSeluruhJaringan')]
    public function test_nilai_sisa_tidak_menyempitkan_cakupan_complaint(string $role): void
    {
        $outletLama = Outlet::create(['name' => 'Cipete']);
        $outletLain = Outlet::create(['name' => 'Lebak Bulus']);

        // Empat bentuk yang masing-masing menangkap satu cara salah baca:
        // menyaring outlet, menyaring divisi, dan `where('outlet_id', null)`
        // yang diterjemahkan jadi whereNull.
        $diOutletLama = $this->complaint($outletLama);
        $diOutletLain = $this->complaint($outletLain);
        $tanpaOutlet = $this->complaint(null);
        $diDivisiLain = $this->complaint($outletLain, 'kurir');

        $user = $this->akunDenganNilaiSisa($role, $outletLama);

        $terlihat = Complaint::visibleTo($user)->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$diOutletLama, $diOutletLain, $tanpaOutlet, $diDivisiLain])
                ->pluck('id')->sort()->values()->all(),
            $terlihat,
            "Peran {$role} kehilangan sebagian complaint. Cakupannya ditentukan PERAN, ".
            'dan outlet/divisi sisa dari masa lalu akun ini bukan wewenang — '.
            'Complaint::scopeVisibleTo membacanya untuk peran yang tidak seharusnya.'
        );
    }

    /* ---------- Outlet::scopeVisibleTo ---------- */

    #[DataProvider('peranYangMelihatSeluruhJaringan')]
    public function test_nilai_sisa_tidak_menyempitkan_cakupan_outlet(string $role): void
    {
        $outletLama = Outlet::create(['name' => 'Cipete']);
        Outlet::create(['name' => 'Lebak Bulus']);
        Outlet::create(['name' => 'Kelapa Gading']);

        $user = $this->akunDenganNilaiSisa($role, $outletLama);

        $terlihat = Outlet::visibleTo($user)->pluck('id')->sort()->values()->all();

        $this->assertSame(
            Outlet::orderBy('id')->pluck('id')->all(),
            $terlihat,
            "Peran {$role} kehilangan sebagian outlet. Daftar outlet yang menyusut ke outlet ".
            'sisa akun ini berarti Outlet::scopeVisibleTo memakai kolom itu tanpa memeriksa '.
            'peran — dan JUMLAH outlet jaringan itu sendiri informasi yang ikut bocor salah.'
        );
    }

    /* ---------- Tagihan::scopeVisibleTo ---------- */

    #[DataProvider('peranYangMelihatSeluruhJaringan')]
    public function test_nilai_sisa_tidak_menyempitkan_cakupan_tagihan(string $role): void
    {
        $outletLama = Outlet::create(['name' => 'Cipete']);
        $outletLain = Outlet::create(['name' => 'Lebak Bulus']);

        // Dua sumbu sekaligus: yang tingkat jaringan (outlet_id null) dan yang
        // ber-outlet. Pembaca yang salah gerbang menyisakan tagihan jaringan
        // plus tagihan outlet sisa saja — dan itu terlihat masuk akal sampai
        // seseorang mencari tagihan outlet lain dan mengira belum dicatat.
        $jaringan = $this->tagihan(null);
        $milikOutletLama = $this->tagihan($outletLama);
        $milikOutletLain = $this->tagihan($outletLain);

        $user = $this->akunDenganNilaiSisa($role, $outletLama);

        $terlihat = Tagihan::visibleTo($user)->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$jaringan, $milikOutletLama, $milikOutletLain])
                ->pluck('id')->sort()->values()->all(),
            $terlihat,
            "Peran {$role} kehilangan sebagian tagihan. seesAllOutlets() harus menjawab true ".
            'untuk peran ini apa pun isi kolom outletnya; kalau tidak, Tagihan::scopeVisibleTo '.
            'jatuh ke cabang per-outlet dan tagihan outlet lain hilang tanpa pesan apa pun.'
        );
    }
}

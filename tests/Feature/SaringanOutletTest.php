<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Saringan outlet halaman Laporan. (API-62 nomor 3)
 *
 * Sebelas outlet, dan salah satu temuan paling berguna dari data 2025 adalah
 * selisih 3× antar-outlet — Cipete median 2 hari, Lebak Bulus 6 hari, bisnis
 * yang sama. Tanpa saringan outlet, temuan seperti itu tidak bisa dikejar
 * dari halaman ini.
 *
 * Yang dijaga di sini bukan "saringannya ada", tapi tiga hal yang membuat
 * sebuah saringan berbahaya: ia harus mengenai GRAFIK dan TABEL sekaligus
 * (kalau hanya salah satu, halaman memperlihatkan dua kebenaran sekaligus),
 * ia harus ditegakkan SERVER dan bukan dengan menyembunyikan pilihannya, dan
 * pembagi grafiknya harus ikut menyusut — kalau tidak, memilih satu outlet
 * menggambar garis yang turun sebelas kali lipat tanpa satu pun angkanya
 * salah.
 */
class SaringanOutletTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $waktu, ?Outlet $outlet, array $attr = []): Complaint
    {
        $complaint = new Complaint(array_merge([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet?->id,
        ], $attr));

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /* ---------- Saringannya mengenai grafik DAN tabel ---------- */

    public function test_saringan_outlet_mengenai_tabel_dan_grafik_sekaligus(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->complaint('2026-08-03 09:00', $cipete, ['reporter_name' => 'Pelapor Cipete']);
        $this->complaint('2026-08-04 09:00', $lebakBulus, ['reporter_name' => 'Pelapor Lebak']);
        $this->complaint('2026-08-05 09:00', $lebakBulus, ['reporter_name' => 'Pelapor Lebak Dua']);

        $semua = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-08-01&to=2026-08-31')
            ->assertOk();

        $semua->assertSee('3 complaint masuk');
        // Dicari di blok "Per outlet", bukan di halaman utuh: daftar pilihan
        // saringannya sendiri menyebut semua outlet yang boleh dipilih
        // supervisor, dan itu memang seharusnya.
        $semua->assertSee('<span>Lebak Bulus</span>', false);

        $disaring = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-08-01&to=2026-08-31&outlet='.$cipete->id)
            ->assertOk();

        // Kartu angka dan blok "Per outlet" — keduanya dari koleksi yang sama.
        $disaring->assertSee('1 complaint masuk');
        $disaring->assertSee('<span>Cipete</span>', false);
        $disaring->assertDontSee('<span>Lebak Bulus</span>', false);

        // Dan grafiknya: satu complaint sebulan, bukan tiga.
        $html = $disaring->getContent();
        $this->assertStringContainsString('1 complaint, 1 outlet', $html);
        $this->assertStringNotContainsString('3 complaint, 2 outlet', $html);
    }

    public function test_saringan_outlet_ikut_ke_pembagi_grafik(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        // Kedua outlet punya sejarah sejak Juli, jadi tanpa saringan
        // pembaginya dua.
        $this->complaint('2026-07-01 09:00', $cipete);
        $this->complaint('2026-07-01 09:00', $lebakBulus);
        $this->complaint('2026-08-03 09:00', $cipete);
        $this->complaint('2026-08-04 09:00', $cipete);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-08-01&to=2026-08-31&outlet='.$cipete->id)
            ->assertOk()->getContent();

        // Pembaginya satu outlet, bukan dua: 2 complaint per 1 outlet.
        $this->assertStringContainsString('2 complaint, 1 outlet', $html);
        $this->assertStringNotContainsString('2 complaint, 2 outlet', $html);
    }

    public function test_saringan_outlet_ikut_ke_ekspor(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->complaint('2026-08-03 09:00', $cipete, ['reporter_name' => 'Pelapor Cipete']);
        $this->complaint('2026-08-04 09:00', $lebakBulus, ['reporter_name' => 'Pelapor Lebak']);

        $response = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports/export?from=2026-08-01&to=2026-08-31&outlet='.$cipete->id)
            ->assertOk();

        $isi = $response->streamedContent();

        $this->assertStringContainsString('Pelapor Cipete', $isi);
        // Ekspor yang mengabaikan saringan mengirim data outlet lain ke
        // WhatsApp dan email tanpa siapa pun melihatnya di layar lebih dulu.
        $this->assertStringNotContainsString('Pelapor Lebak', $isi);
    }

    /* ---------- Ditegakkan server, bukan dengan menyembunyikan pilihan ---------- */

    public function test_kasir_hanya_menemukan_outletnya_sendiri_di_daftar_pilihan(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        Outlet::create(['name' => 'Lebak Bulus']);
        Outlet::create(['name' => 'Jagakarsa']);

        $response = $this->actingAs($this->userAs('kasir', $cipete))
            ->get('/reports?from=2026-08-01&to=2026-08-31')
            ->assertOk();

        $response->assertSee('name="outlet"', false);
        $response->assertSee('value="'.$cipete->id.'"', false);
        // Jumlah outlet jaringan itu sendiri informasi: daftar pilihan yang
        // lengkap membocorkannya tanpa satu baris data pun ikut keluar.
        $response->assertDontSee('Lebak Bulus');
        $response->assertDontSee('Jagakarsa');
    }

    public function test_permintaan_langsung_kasir_dengan_outlet_lain_ditolak(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->complaint('2026-08-04 09:00', $lebakBulus);

        // Ditolak, bukan diam-diam dikosongkan: permintaan yang dibiarkan
        // lewat lalu dikembalikan "semua outlet" memperlihatkan lebih banyak
        // daripada yang diminta, tanpa satu pun tanda bahwa saringannya
        // diabaikan.
        $this->actingAs($this->userAs('kasir', $cipete))
            ->get('/reports?from=2026-08-01&to=2026-08-31&outlet='.$lebakBulus->id)
            ->assertForbidden();
    }

    public function test_ekspor_langsung_kasir_dengan_outlet_lain_ditolak(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        $this->actingAs($this->userAs('kasir', $cipete))
            ->get('/reports/export?from=2026-08-01&to=2026-08-31&outlet='.$lebakBulus->id)
            ->assertForbidden();
    }

    /**
     * Outlet yang tidak ada dijawab sama dengan outlet yang tidak boleh
     * dilihat. Kalau yang satu 404/422 dan yang lain 403, kasir bisa
     * menghitung ada berapa outlet di jaringan dengan mencoba id satu per
     * satu — tanpa pernah melihat satu baris data pun.
     */
    public function test_outlet_yang_tidak_ada_dijawab_sama_dengan_outlet_terlarang(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);
        $kasir = $this->userAs('kasir', $cipete);

        $terlarang = $this->actingAs($kasir)
            ->get('/reports?from=2026-08-01&to=2026-08-31&outlet='.$lebakBulus->id);
        $tidakAda = $this->actingAs($kasir)
            ->get('/reports?from=2026-08-01&to=2026-08-31&outlet=999999');

        $this->assertSame($terlarang->getStatusCode(), $tidakAda->getStatusCode());
        $this->assertSame(403, $tidakAda->getStatusCode());
    }

    public function test_supervisor_boleh_menyaring_outlet_mana_pun(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);

        foreach ([$cipete, $lebakBulus] as $outlet) {
            $this->actingAs($this->userAs('supervisor'))
                ->get('/reports?from=2026-08-01&to=2026-08-31&outlet='.$outlet->id)
                ->assertOk();
        }
    }

    public function test_kasir_tanpa_outlet_tidak_menemukan_satu_pun_pilihan(): void
    {
        Outlet::create(['name' => 'Cipete']);

        // Cakupan yang kosong berarti tidak melihat apa pun — bukan melihat
        // semua yang sama-sama kosong. Sama dengan Complaint::scopeVisibleTo.
        $this->actingAs($this->userAs('kasir'))
            ->get('/reports?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertDontSee('Cipete');
    }

    /**
     * Tanggal yang bukan tanggal dijawab sebagai isian yang salah, bukan
     * dengan halaman galat. Sebelum saringannya punya FormRequest,
     * `?from=kemarin` membuat Carbon melempar dan halamannya membalas 500.
     */
    public function test_tanggal_yang_bukan_tanggal_tidak_memecahkan_halaman(): void
    {
        $response = $this->actingAs($this->userAs('supervisor'))->get('/reports?from=kemarin');

        $this->assertLessThan(500, $response->getStatusCode());
    }

    /* ---------- Kueri dan pemeriksaan wewenangnya harus sepakat ---------- */

    /**
     * `Outlet::scopeVisibleTo` dan `User::canViewOutlet` menulis aturan yang
     * sama dalam dua bentuk: satu kueri, satu pemeriksaan. Dua salinan berarti
     * dua tempat yang bisa berbeda — dan yang berbeda diam-diam adalah
     * daftarnya, karena tidak ada yang menekan tombolnya untuk mengeceknya.
     */
    public function test_daftar_pilihan_dan_pemeriksaan_wewenang_sepakat(): void
    {
        $cipete = Outlet::create(['name' => 'Cipete']);
        $lebakBulus = Outlet::create(['name' => 'Lebak Bulus']);
        $semua = [$cipete, $lebakBulus];

        $pengguna = [
            $this->userAs('kasir', $cipete),
            $this->userAs('kasir', $lebakBulus),
            $this->userAs('kasir'),
            $this->userAs('customer_care'),
            $this->userAs('supervisor'),
            $this->userAs('admin'),
            $this->userAs('divisi'),
        ];

        foreach ($pengguna as $user) {
            $lewatKueri = Outlet::query()->visibleTo($user)->pluck('id')->all();

            foreach ($semua as $outlet) {
                $this->assertSame(
                    in_array($outlet->id, $lewatKueri, true),
                    $user->canViewOutlet($outlet),
                    "Daftar pilihan dan pemeriksaan wewenang tidak sepakat untuk peran {$user->role}."
                );
            }
        }
    }
}

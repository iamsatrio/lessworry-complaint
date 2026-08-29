<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjaga untuk satu kelas kegagalan yang tidak terlihat oleh test biasa.
 *
 * Skrip di halaman memanggil elemen lewat getElementById. Kalau markup-nya
 * hilang sementara skripnya tetap memanggil, getElementById mengembalikan
 * null, .addEventListener melempar TypeError saat halaman dimuat, dan
 * SELURUH skrip berhenti — tombol lain ikut mati tanpa pesan apa pun.
 *
 * Persis itu yang terjadi pada tombol Cek: select alasan tidak jadi masuk
 * markup, skripnya tetap memanggilnya, dan seluruh halaman kehilangan
 * fungsinya. Test PHP tidak melihatnya karena servernya membalas 200.
 */
class PageScriptTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    /**
     * Ambil semua id yang dipanggil skrip, lalu pastikan ada di markup.
     *
     * $wajibAdaRujukan: halaman yang skripnya memang menyetir elemen. Nol
     * rujukan di situ berarti pola penangkapnya yang tidak lagi cocok, bukan
     * halamannya yang bersih. Halaman yang tidak menyetir elemen apa pun —
     * skrip satu-satunya di sana milik layout — lewat dengan false.
     */
    private function assertScriptIdsExist(string $html, string $halaman, bool $wajibAdaRujukan = true): void
    {
        // Tangkap dua bentuk: getElementById('x') dan helper el('x').
        preg_match_all("/(?:getElementById|\bel)\(\s*'([^']+)'\s*\)/", $html, $dipanggil);
        preg_match_all('/\bid="([^"]+)"/', $html, $tersedia);

        $dipanggil = array_unique($dipanggil[1]);
        $tersedia  = $tersedia[1];

        // Halaman tanpa skrip tidak punya kelas kegagalan ini sama sekali —
        // yang dijaga di sini adalah skrip yang memanggil elemen hilang.
        // Yang berbahaya justru sebaliknya: skrip ada, rujukannya tidak
        // terbaca pola di atas.
        if (! str_contains($html, '<script')) {
            $this->assertSame([], $dipanggil,
                "Halaman $halaman tidak punya skrip, tapi ada rujukan elemen — pola test perlu ditinjau.");

            return;
        }

        if ($wajibAdaRujukan) {
            $this->assertNotEmpty($dipanggil, "Tidak ada rujukan elemen di $halaman — pola test perlu ditinjau.");
        }

        foreach ($dipanggil as $id) {
            $this->assertContains(
                $id,
                $tersedia,
                "Halaman $halaman memanggil elemen '$id' tapi elemennya tidak ada. "
                ."Skrip akan berhenti di titik itu dan tombol lain ikut mati."
            );
        }
    }

    public function test_form_intake_tidak_memanggil_elemen_yang_tidak_ada(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')->assertOk()->getContent();

        $this->assertScriptIdsExist($html, 'complaints/create');
    }

    public function test_halaman_complaint_tidak_memanggil_elemen_yang_tidak_ada(): void
    {
        $cc = $this->userAs('customer_care');

        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'status' => 'baru', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        $html = $this->actingAs($cc)->get('/complaints/'.$complaint->id)->assertOk()->getContent();

        $this->assertScriptIdsExist($html, 'complaints/show', wajibAdaRujukan: false);
    }

    /* ---------- Kolom yang dibutuhkan aturan nota harus benar-benar ada ---------- */

    public function test_form_intake_menyediakan_pilihan_alasan_tanpa_nota(): void
    {
        $response = $this->actingAs($this->userAs('customer_care'))->get('/complaints/create');

        // Validasi server menolak complaint tanpa nota dan tanpa alasan, jadi
        // pilihan alasannya wajib tersedia — kalau tidak, petugas menemui
        // jalan buntu tanpa cara memenuhinya.
        $response->assertSee('name="nota_exemption"', false);

        foreach (array_keys(config('complaint.nota_exemptions')) as $kunci) {
            $response->assertSee('value="'.$kunci.'"', false);
        }
    }

    public function test_form_intake_menyediakan_kolom_nota_dan_tombol_cek(): void
    {
        $this->actingAs($this->userAs('customer_care'))
            ->get('/complaints/create')
            ->assertSee('name="nevira_transaction_number"', false)
            ->assertSee('id="cek"', false)
            ->assertSee('id="nvbox"', false);
    }
}

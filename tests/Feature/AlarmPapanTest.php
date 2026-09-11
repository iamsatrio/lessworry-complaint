<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\PenandaAlarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kerangka alarm: hidup, matinya, dan penandaan "sudah saya tangani".
 * (API-72 bagian 1, alur 2 di API-71)
 *
 * Kegagalan yang dicegah penandaan ini: beberapa orang membaca alarm yang
 * sama, semua mengira orang lain sudah menanganinya, alarmnya menyala
 * berhari-hari dan tidak ada yang bertindak.
 *
 * Kegagalan yang dicegah penandaan yang TIDAK permanen: alarm dipegang sekali,
 * lalu diam selamanya walau keadaannya tidak pernah berubah.
 */
class AlarmPapanTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role = 'admin', string $nama = 'Admin'): User
    {
        return User::create([
            'name' => $nama, 'email' => strtolower($role).uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
        ]);
    }

    /** Satu complaint Open tanpa pemilik, cukup tua untuk menyalakan alarm. */
    private function complaintMenganggur(int $umurJam = 6): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = now()->subHours($umurJam);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    private function tangani(User $user, string $alarm = 'complaint.belum_dipegang')
    {
        return $this->actingAs($user)->post('/operasional/alarm/'.$alarm.'/tangani');
    }

    /* ---------- Kriteria 1 ---------- */

    public function test_papan_tanpa_alarm_mengatakan_tidak_ada_yang_perlu_ditangani(): void
    {
        $this->actingAs($this->userAs())->get('/operasional')
            ->assertOk()
            ->assertSee('Tidak ada yang perlu ditangani')
            // Bukan halaman kosong: keadaan ini NORMAL, dan kalimatnya harus
            // menutup kunjungan pagi.
            ->assertSee('Papan bersih');
    }

    public function test_papan_menampilkan_kedua_alarm_saat_keduanya_menyala(): void
    {
        $this->complaintMenganggur();

        $telat = $this->complaintMenganggur(240);
        $telat->status = 'handling';
        $telat->save();

        $this->actingAs($this->userAs())->get('/operasional')
            ->assertOk()
            ->assertSee('Complaint belum dipegang')
            ->assertSee('Complaint melewati SLA')
            ->assertDontSee('Tidak ada yang perlu ditangani');
    }

    /* ---------- Kriteria 2 ---------- */

    public function test_penandaan_terlihat_pengguna_lain_lengkap_dengan_nama_dan_jam(): void
    {
        // Jamnya dipaku SEBELUM complaint dibuat: umurnya dihitung dari
        // created_at terhadap waktu uji, dan complaint yang dibuat sebelum
        // jamnya dipaku akan berumur negatif.
        Carbon::setTestNow(Carbon::parse('2026-09-11 03:20:00')); // 10:20 WIB

        $this->complaintMenganggur();

        $siti = $this->userAs('admin', 'Siti Aminah');
        $this->tangani($siti)->assertRedirect(route('operasional'));

        $rekan = $this->userAs('supervisor', 'Rudi');

        $this->actingAs($rekan)->get('/operasional')
            ->assertOk()
            ->assertSee('Siti Aminah')
            // Jam dibaca dalam zona operasional, bukan UTC tempat aplikasi
            // berjalan: "pukul 03:20" tidak berarti apa-apa bagi tim HO.
            ->assertSee('pukul 10:20')
            ->assertDontSee('Sudah saya tangani');
    }

    public function test_alarm_yang_ditangani_tetap_tampil(): void
    {
        $this->complaintMenganggur();
        $this->tangani($this->userAs());

        // Hilangnya alarm yang dipegang membuat rekan yang membuka setengah jam
        // kemudian menyimpulkan keadaannya sudah beres.
        $this->actingAs($this->userAs('supervisor', 'Rudi'))->get('/operasional')
            ->assertOk()
            ->assertSee('Complaint belum dipegang')
            ->assertSee('Dipegang');
    }

    /* ---------- Kriteria 3 ---------- */

    public function test_alarm_menyala_lagi_besok_walau_kemarin_sudah_ditandai(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 03:00:00')); // 10:00 WIB

        $this->complaintMenganggur();
        $this->tangani($this->userAs('admin', 'Siti Aminah'));

        $rekan = $this->userAs('supervisor', 'Rudi');

        $this->actingAs($rekan)->get('/operasional')->assertOk()->assertSee('Dipegang');

        // Besok pagi. Complaint-nya masih Open dan masih tanpa pemilik.
        Carbon::setTestNow(Carbon::parse('2026-09-12 01:30:00')); // 08:30 WIB

        $this->actingAs($rekan)->get('/operasional')
            ->assertOk()
            ->assertSee('Complaint belum dipegang')
            ->assertSee('Sudah saya tangani')
            ->assertDontSee('Dipegang Siti Aminah');
    }

    public function test_penandaan_kemarin_tidak_dihapus_hanya_berhenti_berlaku(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 03:00:00'));
        $this->complaintMenganggur();
        $this->tangani($this->userAs());

        Carbon::setTestNow(Carbon::parse('2026-09-12 01:30:00'));
        $this->tangani($this->userAs('supervisor', 'Rudi'));

        // Dua baris, dua tanggal — riwayat siapa memegang apa tidak dihapus
        // oleh hari berikutnya, dan tidak ada pekerjaan terjadwal yang harus
        // membersihkannya.
        $this->assertSame(2, PenandaAlarm::where('alarm', 'complaint.belum_dipegang')->count());
    }

    /* ---------- Keadaan yang berubah ---------- */

    public function test_alarm_padam_saat_keadaannya_hilang_bukan_saat_ditandai(): void
    {
        $complaint = $this->complaintMenganggur();
        $penangan = $this->userAs();

        $this->tangani($penangan);
        $this->actingAs($penangan)->get('/operasional')->assertOk()->assertSee('Complaint belum dipegang');

        // Yang benar-benar memadamkan: complaint-nya dipegang seseorang.
        $complaint->assigned_to = $penangan->id;
        $complaint->save();

        $this->actingAs($penangan)->get('/operasional')
            ->assertOk()
            ->assertSee('Tidak ada yang perlu ditangani')
            ->assertDontSee('Complaint belum dipegang');
    }

    public function test_alarm_yang_sudah_padam_tidak_bisa_ditandai(): void
    {
        $this->tangani($this->userAs())
            ->assertRedirect(route('operasional'))
            ->assertSessionHas('warning');

        $this->assertSame(0, PenandaAlarm::count());
    }

    public function test_alarm_yang_tidak_terdaftar_dijawab_404(): void
    {
        $this->complaintMenganggur();

        // Bukan 403: tidak ada yang bisa dipegang, bukan wewenang yang kurang.
        $this->tangani($this->userAs(), 'alarm.karangan')->assertNotFound();
    }

    public function test_pemegang_pertama_yang_tercatat(): void
    {
        $this->complaintMenganggur();

        $pertama = $this->userAs('admin', 'Siti Aminah');
        $kedua = $this->userAs('supervisor', 'Rudi');

        $this->tangani($pertama);
        $this->tangani($kedua)->assertSessionHas('status', fn (string $pesan) => str_contains($pesan, 'Siti Aminah'));

        $this->assertSame(1, PenandaAlarm::count());
        $this->assertSame($pertama->id, PenandaAlarm::firstOrFail()->user_id);
    }

    /* ---------- Kriteria 7 ---------- */

    public function test_papan_terbuka_di_bawah_satu_detik_dengan_545_complaint(): void
    {
        $baris = [];

        for ($i = 0; $i < 545; $i++) {
            $dibuat = now()->subHours(6 + $i);

            $baris[] = [
                'ticket_number' => 'LW-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'channel' => 'wa_cc', 'reporter_name' => 'Pelapor '.$i,
                'category' => 'kurang_bersih', 'bobot' => 'sedang', 'layanan' => 'kiloan',
                'description' => 'x',
                // Sepertiganya masih terbuka tanpa pemilik, sepertiganya telat,
                // sisanya tertutup — bentuk yang memaksa kedua alarm bekerja.
                'status' => match ($i % 3) {
                    0 => 'open', 1 => 'handling', default => 'close'
                },
                'due_resolution_at' => $dibuat->copy()->addDays(3),
                'created_at' => $dibuat,
                'updated_at' => $dibuat,
            ];
        }

        Complaint::insert($baris);

        $this->assertSame(545, Complaint::count());

        $user = $this->userAs();

        // Sekali dulu tanpa diukur: yang diukur bukan waktu kompilasi Blade —
        // di produksi view-nya sudah ter-cache (php artisan view:cache).
        $this->actingAs($user)->get('/operasional')->assertOk();

        $mulai = microtime(true);
        $this->actingAs($user)->get('/operasional')->assertOk();
        $detik = microtime(true) - $mulai;

        $this->assertLessThan(1.0, $detik,
            'Halaman yang lebih lambat dari satu detik tidak akan dibuka tiap pagi. Terukur: '.round($detik, 3).'s');
    }
}

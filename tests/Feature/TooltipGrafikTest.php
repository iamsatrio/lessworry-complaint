<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\View\Components\Grafik\Garis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Menunjuk titik grafik harus memunculkan angkanya. (API-62 nomor 1)
 *
 * Sebelum ini keterangannya hanya `<title>` SVG. Secara teknis itu memang
 * keterangan saat ditunjuk, tapi praktiknya tidak terpakai: peramban
 * menundanya sekitar sedetik, yang muncul tooltip sistem operasi di luar gaya
 * halaman, dan sasarannya bulatan 4,5 satuan yang di layar sentuh praktis
 * mustahil ditunjuk.
 *
 * Yang dijaga di sini dua hal yang tidak bisa dilihat dari tangkapan layar:
 * bahwa keterangan halaman itu ADA di dalam HTML yang dikirim server, dan
 * bahwa sasaran tunjuknya benar-benar ≥28px DI LAYAR — bukan ≥28 satuan
 * viewBox, yang artinya berbeda di tiap lebar layar.
 */
class TooltipGrafikTest extends TestCase
{
    use RefreshDatabase;

    /** Lebar viewBox grafik garis; satuan di dalam SVG diukur terhadap ini. */
    private const VIEWBOX = 880;

    /** Lebar kanvas terbesar di layar, dari `max-width` CSS `.fig svg`. */
    private const LEBAR_MAKS = 920;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $waktu, Outlet $outlet): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'kurang_bersih',
            'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            'outlet_id' => $outlet->id,
        ]);

        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'open';
        $complaint->created_at = Carbon::parse($waktu);
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /** @param  list<array{label:string,nilai:float|null,teks:string}>  $titik */
    private function garis(array $titik): Garis
    {
        return new Garis(judul: 'Uji', catatan: 'Uji', titik: $titik);
    }

    /** @return list<array{label:string,nilai:float|null,teks:string}> */
    private function titikSebanyak(int $n): array
    {
        $titik = [];

        for ($i = 0; $i < $n; $i++) {
            $titik[] = ['label' => 'T'.$i, 'nilai' => (float) ($i + 1), 'teks' => ($i + 1).' kasus'];
        }

        return $titik;
    }

    /* ---------- Keterangannya ada di HTML, bukan menunggu skrip ---------- */

    public function test_titik_grafik_membawa_keterangan_bergaya_halaman(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);
        $this->complaint('2026-08-03 09:00', $outlet);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->getContent();

        // Kotak keterangannya sendiri, bukan tooltip sistem operasi.
        $this->assertStringContainsString('class="g-tip"', $html);
        $this->assertStringContainsString('class="g-tip-lab"', $html);
        $this->assertStringContainsString('class="g-tip-nil"', $html);

        // Sasaran tunjuk tak terlihat di tiap titik.
        $this->assertStringContainsString('class="g-sasaran"', $html);

        // Muncul karena CSS, bukan karena skrip: tidak ada satu pun skrip baru
        // yang perlu jalan supaya keterangan ini terlihat.
        $this->assertStringContainsString('.g-titik:hover .g-tip', $html);
    }

    public function test_title_svg_dipertahankan_sebagai_cadangan(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);
        $this->complaint('2026-08-03 09:00', $outlet);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->getContent();

        // Cadangan untuk pembaca layar dan untuk saat CSS gagal dimuat —
        // tooltipnya menggantikan pemakaian sehari-hari, bukan menghapus ini.
        $this->assertMatchesRegularExpression('/<circle[^>]*r="4\.5"[^>]*><title>/', $html);
    }

    public function test_label_dan_angkanya_tertulis_terpisah_di_tooltip(): void
    {
        $garis = $this->garis([
            ['label' => 'Agu 26', 'nilai' => 1.2, 'teks' => '1,2 per outlet (13 complaint, 11 outlet)'],
        ]);

        $simpul = $garis->simpul();

        $this->assertSame('Agu 26', $simpul[0]['label']);
        $this->assertSame('1,2 per outlet (13 complaint, 11 outlet)', $simpul[0]['nilai']);
        // Baris gabungan tetap ada untuk <title>.
        $this->assertSame('Agu 26 · 1,2 per outlet (13 complaint, 11 outlet)', $simpul[0]['teks']);
    }

    /* ---------- Sasaran ≥28px DI LAYAR, di 1440px maupun 390px ---------- */

    /**
     * Di layar 1440px kanvasnya digambar selebar `max-width` CSS-nya; di 390px
     * ia mentok di `min-width` dan digeser mendatar. Kedua-duanya harus lolos,
     * dan yang sempit itulah yang menentukan.
     */
    public function test_sasaran_tunjuk_minimal_28px_di_layar_lebar_maupun_sempit(): void
    {
        foreach ([2, 6, 12, 19, 31, 53] as $n) {
            $garis = $this->garis($this->titikSebanyak($n));

            $sempit = 2 * $garis->jariSasaran() * ($garis->lebarMin() / self::VIEWBOX);
            $lebar = 2 * $garis->jariSasaran() * (max(self::LEBAR_MAKS, $garis->lebarMin()) / self::VIEWBOX);

            $this->assertGreaterThanOrEqual(28, round($sempit, 2),
                "Sasaran tunjuk $n titik hanya {$sempit}px pada kanvas tersempit.");
            $this->assertGreaterThanOrEqual(28, round($lebar, 2),
                "Sasaran tunjuk $n titik hanya {$lebar}px pada kanvas terlebar.");
        }
    }

    /**
     * Sasaran yang lebih lebar dari jarak antar titik saling menimpa, dan yang
     * menang jadi tetangga sebelah: menunjuk Agustus lalu terbaca September.
     */
    public function test_sasaran_tunjuk_tidak_saling_menimpa(): void
    {
        foreach ([2, 6, 12, 19, 31, 53] as $n) {
            $garis = $this->garis($this->titikSebanyak($n));

            $jarak = (self::VIEWBOX - 54 - 20) / ($n - 1);

            $this->assertLessThanOrEqual(round($jarak / 2, 2), $garis->jariSasaran(),
                "Sasaran tunjuk $n titik lebih lebar dari jarak antar titiknya.");
        }
    }

    /**
     * Kanvas 560px dengan 31 titik hanya punya 18px per titik: sasaran 28px di
     * situ mustahil secara aritmetika. Yang mengalah lebar kanvasnya.
     */
    public function test_kanvas_melebar_saat_titiknya_rapat(): void
    {
        $this->assertSame(560, $this->garis($this->titikSebanyak(6))->lebarMin());
        $this->assertSame(560, $this->garis($this->titikSebanyak(17))->lebarMin());
        $this->assertGreaterThan(560, $this->garis($this->titikSebanyak(31))->lebarMin());

        // Dan lebar itu benar-benar sampai ke markup-nya.
        $outlet = Outlet::create(['name' => 'Outlet A']);
        $this->complaint('2026-07-03 09:00', $outlet);

        $html = $this->actingAs($this->userAs('supervisor'))
            ->get('/reports?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('style="min-width:560px"', $html);
    }

    /* ---------- Kotaknya tidak terpotong tepi gambar ---------- */

    public function test_kotak_keterangan_tetap_di_dalam_gambar(): void
    {
        $garis = $this->garis($this->titikSebanyak(12));
        $simpul = $garis->simpul();

        foreach ($simpul as $titik) {
            $tip = $garis->tooltip($titik['x'], $titik['y'], $titik['label'], $titik['nilai']);

            $this->assertGreaterThanOrEqual(0, $tip['x'], 'Kotak keterangan terpotong tepi kiri.');
            $this->assertLessThanOrEqual(self::VIEWBOX, $tip['x'] + $tip['lebar'], 'Kotak keterangan terpotong tepi kanan.');
            $this->assertGreaterThanOrEqual(0, $tip['y'], 'Kotak keterangan terpotong tepi atas.');
        }
    }

    /**
     * Titik yang nyaris menyentuh atap gambar tidak punya ruang di atasnya,
     * jadi kotaknya pindah ke bawah titiknya — bukan terpotong.
     */
    public function test_kotak_keterangan_pindah_ke_bawah_saat_titiknya_di_puncak(): void
    {
        $garis = $this->garis($this->titikSebanyak(3));
        $tip = $garis->tooltip(400.0, 26.0, 'Agu 26', '9 kasus');

        $this->assertGreaterThan(26.0, $tip['y'], 'Kotak keterangan titik puncak masih digambar di atasnya.');
    }
}

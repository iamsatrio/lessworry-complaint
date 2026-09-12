<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Services\JejakComplaint;
use App\Services\LayananDariUraian;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan baris `Satuan Non Cloth` yang sebenarnya sepatu/tas atau
 * karpet/gorden ke dua nilai layanan baru. (API-59)
 *
 * Nilai enum yang kosong tidak berguna: kalau riwayatnya tidak ikut
 * dibetulkan, laporan "berapa kerugian dari layanan karpet" tetap menjawab
 * nol. Tapi ini menyentuh satu-satunya riwayat yang dimiliki sistem — 545
 * baris backfill — jadi tiga hal dipasang sebelum satu baris pun berubah:
 *
 * 1. **Hitung dulu, tulis kemudian.** Tanpa `--tulis` perintah ini tidak
 *    menyentuh basis data sama sekali, dan mencetak SETIAP baris yang akan
 *    dipindah lengkap dengan potongan uraiannya. Enam puluh baris riwayat
 *    tidak boleh berubah karena seseorang salah ketik.
 * 2. **Hanya baris yang sekarang bernilai `satuan_non_cloth`.** Layanan lain
 *    tidak disentuh apa pun isi uraiannya. Yang paling penting: baris
 *    ber-layanan Kiloan TIDAK PERNAH ikut. Ada empat baris berbunyi "Tas
 *    laundry gak dikembalikan" di sana, dan tas laundry itu wadah tempat
 *    cucian datang dan pulang — tasnya milik pelanggan, tapi bukan tasnya
 *    yang dicuci. Kolom `layanan` mencatat jasa yang DIBELI, bukan barang
 *    yang disebut keluhannya, dan yang dibeli di keempat baris itu Kiloan.
 *
 *    Pagar kedua untuk hal yang sama ada di `LayananDariUraian`: uraian yang
 *    menyebut tas laundry menahan SELURUH BARISNYA, walau layanannya
 *    `satuan_non_cloth`. Penyaring `ASAL` saja tidak menolong baris yang
 *    masuk lewat impor berikutnya.
 * 3. **Ada jalan mundur, dan ia berhenti di keputusan orang.** `--balikkan`
 *    mengembalikan baris yang pernah dipindah ke `satuan_non_cloth`, dikenali
 *    dari baris riwayat yang ditulis saat memindahkannya. Baris yang SESUDAH
 *    itu dipindahkan lagi oleh petugas tidak ikut mundur: catatan riwayatnya
 *    menyebut tujuan yang ditulis perintah ini, dan kalau kolom `layanan`
 *    sekarang berisi nilai lain, yang menaruhnya di sana bukan perintah ini.
 *    Jalan mundur yang menimpa penilaian orang bukan jalan mundur, itu
 *    kerusakan kedua.
 *
 * Kata kuncinya tidak ada di kelas ini. `LayananDariUraian` yang memilikinya,
 * dan `PemetaBarisImpor` memanggil kelas yang sama — itu yang membuat impor
 * ulang berkas CSV menghasilkan pemetaan yang sama dengan hasil pembetulan
 * ini, bukan mengembalikannya ke `satuan_non_cloth`.
 *
 *   php artisan complaint:betulkan-layanan            # hitung dan cetak
 *   php artisan complaint:betulkan-layanan --tulis    # baru menulis
 *   php artisan complaint:betulkan-layanan --balikkan
 *   php artisan complaint:betulkan-layanan --balikkan --tulis
 */
class BetulkanLayanan extends Command
{
    protected $signature = 'complaint:betulkan-layanan
        {--tulis : Benar-benar menyimpan. Tanpa ini perintah hanya menghitung dan mencetak}
        {--balikkan : Kembalikan baris yang pernah dipindah ke Satuan Non Cloth}
        {--potong=80 : Berapa huruf uraian yang dicetak per baris}';

    protected $description = 'Tarik Sepatu & Tas dan Karpet & Gorden keluar dari Satuan Non Cloth pada riwayat complaint';

    public function handle(JejakComplaint $jejak): int
    {
        return $this->option('balikkan')
            ? $this->balikkan($jejak)
            : $this->betulkan($jejak);
    }

    /* ---------- maju ---------- */

    private function betulkan(JejakComplaint $jejak): int
    {
        $kering = ! $this->option('tulis');
        $cocok = $this->kandidat();

        // Yang cocok belum tentu yang dipindah, dan yang memutuskan bukan
        // perintah ini: `ditahan` datang dari `LayananDariUraian::tebak()`.
        // Pemisahannya terjadi sekali, di sini, supaya tabel "yang akan
        // dipindah", angka ringkasannya, dan apa yang benar-benar ditulis
        // adalah tiga pandangan atas satu himpunan yang sama — bukan tiga
        // penyaring yang kebetulan sepakat.
        $pindah = array_values(array_filter($cocok, fn (array $b) => ! $b['ditahan']));
        $ditahan = array_values(array_filter($cocok, fn (array $b) => $b['ditahan']));

        $this->info('Pembetulan layanan (API-59) — '.($kering ? 'MODE HITUNG, tidak menulis apa pun' : 'MENULIS'));
        $this->newLine();

        if ($cocok === []) {
            $this->line('Tidak ada baris '.$this->label(LayananDariUraian::ASAL).' yang cocok kata kunci.');

            return self::SUCCESS;
        }

        if ($pindah === []) {
            $this->line('Tidak ada baris yang dipindah — semua yang cocok ditahan.');
        } else {
            $this->cetakBaris($pindah);
            $this->cetakRingkasan($pindah);
        }

        $this->cetakDitahan($ditahan);
        $this->cetakPagarKiloan();

        if ($kering) {
            $this->newLine();
            $this->warn('Mode hitung saja — tidak ada baris yang ditulis. Tambahkan --tulis untuk menyimpan.');

            return self::SUCCESS;
        }

        $this->tulis($pindah, $jejak);

        $this->newLine();
        $this->info(count($pindah).' baris dipindah. Jalan mundurnya: '
            .'php artisan complaint:betulkan-layanan --balikkan --tulis');

        return self::SUCCESS;
    }

    /**
     * Baris yang COCOK kata kunci — termasuk yang nanti ditahan.
     *
     * Penyaringnya kolom `layanan`, BUKAN isi uraiannya: uraian yang menyebut
     * sepatu pada complaint Kiloan tetap complaint Kiloan.
     *
     * Dua pertanyaan, dua sumber, dan bedanya disengaja:
     *
     * - **Boleh dipindah?** dijawab `tebak()`. Perintah ini TIDAK memutuskan
     *   sendiri. Alasan penahanan sudah dua — kata kunci di luar klausa
     *   pertama, dan uraian yang bicara tentang wadah cucian — dan keduanya
     *   lahir di `LayananDariUraian`. Yang ketiga akan diikuti perintah ini
     *   tanpa disunting; itulah gunanya keputusannya tidak disalin ke sini.
     * - **Kenapa?** dijawab `periksa()`, yang tetap membawa barisnya beserta
     *   kata yang cocok dan kode `tahan`. Dipakai untuk MENCETAK, tidak
     *   pernah untuk memutuskan.
     *
     * @return list<array{complaint:Complaint,ke:string,kata:string,tahan:?string,ditahan:bool}>
     */
    private function kandidat(): array
    {
        $hasil = [];

        Complaint::query()
            ->where('layanan', LayananDariUraian::ASAL)
            ->orderBy('id')
            ->each(function (Complaint $complaint) use (&$hasil) {
                $uraian = (string) $complaint->description;
                $temu = LayananDariUraian::periksa($uraian);

                if ($temu === null) {
                    return;
                }

                $hasil[] = [
                    'complaint' => $complaint,
                    'ke' => $temu['layanan'],
                    'kata' => $temu['kata'],
                    'tahan' => $temu['tahan'],
                    'ditahan' => LayananDariUraian::tebak($uraian) === null,
                ];
            });

        return $hasil;
    }

    /** @param list<array{complaint:Complaint,ke:string,kata:string,tahan:?string,ditahan:bool}> $pindah */
    private function tulis(array $pindah, JejakComplaint $jejak): void
    {
        DB::transaction(function () use ($pindah, $jejak) {
            foreach ($pindah as $baris) {
                $complaint = $baris['complaint'];
                $dari = (string) $complaint->layanan;

                $this->ganti($complaint, $baris['ke']);
                $jejak->layananDibetulkan($complaint, $dari, $baris['ke']);
            }
        });
    }

    /* ---------- mundur ---------- */

    private function balikkan(JejakComplaint $jejak): int
    {
        $kering = ! $this->option('tulis');

        // Dikenali dari baris riwayatnya, bukan dari isi uraiannya: yang
        // dikembalikan harus PERSIS baris yang pernah dipindah perintah ini.
        // Complaint yang layanannya diisi Sepatu & Tas oleh kasir sendiri
        // tidak pernah punya baris riwayat itu, dan tidak boleh ikut mundur.
        $calon = Complaint::query()
            ->whereIn('layanan', LayananDariUraian::tujuan())
            ->whereHas('activities', fn ($q) => $q->where('note', 'like', JejakComplaint::TANDA_LAYANAN.'%'))
            ->with(['activities' => fn ($q) => $q->where('note', 'like', JejakComplaint::TANDA_LAYANAN.'%')])
            ->orderBy('id')
            ->get();

        [$complaints, $dilewati] = $this->saringYangDisentuhOrang($calon);

        $this->info('Mengembalikan pembetulan layanan (API-59) — '
            .($kering ? 'MODE HITUNG, tidak menulis apa pun' : 'MENULIS'));
        $this->newLine();

        $this->cetakDilewati($dilewati);

        if ($complaints === []) {
            $this->line($dilewati === []
                ? 'Tidak ada baris yang pernah dipindah dan masih berada di nilai barunya.'
                : 'Tidak ada baris yang bisa dikembalikan — semuanya sudah dipindah orang.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tiket', 'Sekarang', 'Dikembalikan ke'],
            array_map(fn (Complaint $c) => [
                $c->id,
                $c->ticket_number,
                $this->label((string) $c->layanan),
                $this->label(LayananDariUraian::ASAL),
            ], $complaints),
        );

        if ($kering) {
            $this->warn(count($complaints).' baris akan dikembalikan. '
                .'Mode hitung saja — tambahkan --tulis untuk menyimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($complaints, $jejak) {
            foreach ($complaints as $complaint) {
                $dari = (string) $complaint->layanan;

                $this->ganti($complaint, LayananDariUraian::ASAL);
                $jejak->layananDikembalikan($complaint, $dari, LayananDariUraian::ASAL);
            }
        });

        $this->info(count($complaints).' baris dikembalikan ke '.$this->label(LayananDariUraian::ASAL).'.');

        return self::SUCCESS;
    }

    /**
     * Pisahkan baris yang masih berada di nilai yang DITULIS perintah ini dari
     * baris yang sesudah itu dipindahkan orang.
     *
     * Pembandingnya catatan riwayat perintah ini sendiri — `Layanan dibetulkan
     * (API-59): X → Y`. Kalau kolom `layanan` sekarang tidak sama dengan Y,
     * ada yang memindahkannya setelah perintah ini lewat, dan satu-satunya
     * pihak yang bisa melakukan itu adalah orang. Nilai orang menang.
     *
     * Catatan yang tidak terbaca juga dilewati, bukan dianggap cocok: perintah
     * ini menulis, dan yang menulis tanpa bisa membaca lebih baik berhenti.
     *
     * @param  Collection<int,Complaint>  $calon
     * @return array{0:list<Complaint>, 1:list<array{complaint:Complaint,tujuan:?string}>}
     */
    private function saringYangDisentuhOrang($calon): array
    {
        $balik = [];
        $dilewati = [];

        foreach ($calon as $complaint) {
            $tujuan = $this->tujuanDariRiwayat($complaint);

            if ($tujuan !== null && $tujuan === (string) $complaint->layanan) {
                $balik[] = $complaint;

                continue;
            }

            $dilewati[] = ['complaint' => $complaint, 'tujuan' => $tujuan];
        }

        return [$balik, $dilewati];
    }

    /**
     * Nilai layanan yang ditulis perintah ini menurut baris riwayat TERAKHIR,
     * atau null kalau catatannya tidak bisa dibaca.
     *
     * Yang terakhir, bukan yang pertama: satu complaint bisa dipindah,
     * dikembalikan, lalu dipindah lagi, dan yang berlaku selalu yang paling
     * belakang. Labelnya dipetakan balik ke kunci enum lewat config yang sama
     * yang dipakai menulisnya.
     */
    private function tujuanDariRiwayat(Complaint $complaint): ?string
    {
        $catatan = $complaint->activities
            ->filter(fn ($a) => str_starts_with((string) $a->note, JejakComplaint::TANDA_LAYANAN.':'))
            ->sortByDesc('id')
            ->first();

        if ($catatan === null) {
            return null;
        }

        $sisa = substr((string) $catatan->note, strlen(JejakComplaint::TANDA_LAYANAN.':'));
        $potong = explode('→', $sisa);

        if (count($potong) < 2) {
            return null;
        }

        $label = trim(rtrim(trim(end($potong)), '.'));
        $kunci = array_search($label, (array) config('complaint.layanan', []), true);

        return is_string($kunci) ? $kunci : null;
    }

    /**
     * Baris yang TIDAK ikut mundur, dicetak sebagai angka lebih dulu.
     *
     * Tabelnya menyebut kedua nilai berdampingan supaya yang membaca melihat
     * apa yang ditulis perintah ini dan apa yang diputuskan orang sesudahnya —
     * itu seluruh alasan barisnya dilewati.
     *
     * @param  list<array{complaint:Complaint,tujuan:?string}>  $dilewati
     */
    private function cetakDilewati(array $dilewati): void
    {
        if ($dilewati === []) {
            $this->line('Dilewati karena sudah dipindah orang: 0 baris.');
            $this->newLine();

            return;
        }

        $this->warn(count($dilewati).' baris DILEWATI — sesudah pembetulan ini, '
            .'orang memindahkannya lagi. Nilai yang dipilih orang tidak ditimpa:');

        $this->table(
            ['ID', 'Tiket', 'Ditulis perintah ini', 'Sekarang (keputusan orang)'],
            array_map(fn (array $d) => [
                $d['complaint']->id,
                $d['complaint']->ticket_number,
                $d['tujuan'] === null ? '— catatan tidak terbaca' : $this->label($d['tujuan']),
                $this->label((string) $d['complaint']->layanan),
            ], $dilewati),
        );

        $this->newLine();
    }

    /* ---------- alat ---------- */

    /**
     * Ganti kolom layanan TANPA menyentuh `updated_at` maupun `lock_version`.
     *
     * Lewat kueri di dalam `withoutTimestamps()`, bukan `save()`:
     * `updated_at` baris impor sengaja diisi tanggal penyelesaian aslinya
     * (API-28), dan menaikkannya ke hari ini membuat 60 complaint dari 2025
     * terlihat baru saja disentuh orang. Kuerinya sendiri tidak cukup —
     * Eloquent menambahkan `updated_at` pada `update()` kalau tidak dimatikan.
     * Yang mencatat perubahan ini adalah baris riwayatnya.
     */
    private function ganti(Complaint $complaint, string $ke): void
    {
        Complaint::withoutTimestamps(
            fn () => Complaint::query()->whereKey($complaint->id)->update(['layanan' => $ke]),
        );

        $complaint->layanan = $ke;
        $complaint->syncOriginalAttribute('layanan');
    }

    /**
     * Tabel baris yang BENAR-BENAR akan dipindah. Yang ditahan tidak ikut ke
     * sini — ia punya bloknya sendiri di bawah, supaya tidak ada pembaca yang
     * menghitung tabel ini lalu mendapat angka yang berbeda dari yang ditulis.
     *
     * @param  list<array{complaint:Complaint,ke:string,kata:string,tahan:?string,ditahan:bool}>  $pindah
     */
    private function cetakBaris(array $pindah): void
    {
        $potong = max(20, (int) $this->option('potong'));

        $this->table(
            ['ID', 'Tiket', 'Sekarang', 'Tujuan', 'Kata', 'Uraian'],
            array_map(fn (array $b) => [
                $b['complaint']->id,
                $b['complaint']->ticket_number,
                $this->label((string) $b['complaint']->layanan),
                $this->label($b['ke']),
                $b['kata'],
                $this->potong((string) $b['complaint']->description, $potong),
            ], $pindah),
        );
    }

    /** @param list<array{complaint:Complaint,ke:string,kata:string,tahan:?string,ditahan:bool}> $pindah */
    private function cetakRingkasan(array $pindah): void
    {
        $per = [];

        foreach ($pindah as $b) {
            $per[$b['ke']] = ($per[$b['ke']] ?? 0) + 1;
        }

        $this->newLine();
        $this->info('Ringkasan');

        foreach (LayananDariUraian::tujuan() as $ke) {
            $this->line('  '.str_pad($this->label($ke), 18).': '.($per[$ke] ?? 0).' baris');
        }

        $this->line('  '.str_pad('Total', 18).': '.count($pindah).' baris');
    }

    /**
     * Baris yang cocok kata kunci tapi DITAHAN, lengkap dengan alasannya.
     *
     * Dicetak selalu, termasuk saat nol. Angka nol di sini bukan ruang
     * terbuang: pembaca laporan perlu tahu bahwa penyaringnya dijalankan dan
     * tidak menahan apa pun, bukan menebak apakah baginya memang tidak ada
     * atau bagian itu lupa dicetak.
     *
     * Alasannya datang dari `LayananDariUraian::alasanTahan()`, bukan ditulis
     * di sini. Alasan penahanan ketiga akan muncul di laporan ini tanpa
     * perintah ini disunting — dan itu bukan kerapian belaka: penahanan yang
     * tidak punya kalimat di laporan akan terbaca sebagai baris yang hilang.
     *
     * @param  list<array{complaint:Complaint,ke:string,kata:string,tahan:?string,ditahan:bool}>  $ditahan
     */
    private function cetakDitahan(array $ditahan): void
    {
        $this->newLine();
        $this->line('Ditahan, tidak dipindah: '.count($ditahan).' baris.');

        if ($ditahan === []) {
            return;
        }

        $this->warn('  Perlu dibaca — jasanya mungkin bukan yang disebut kata kuncinya:');

        foreach ($ditahan as $b) {
            $this->line('  #'.$b['complaint']->id.' (cocok '.$b['kata'].' -> '.$this->label($b['ke'])
                .', TETAP di '.$this->label((string) $b['complaint']->layanan).')');
            $this->line('    alasan: '.LayananDariUraian::alasanTahan((string) $b['tahan']));
            $this->line('    '.$b['complaint']->description);
        }

        $this->line('  Baris di atas TIDAK dipindah, bahkan dengan --tulis. Kalau salah satunya');
        $this->line('  memang harus pindah, sunting complaint-nya lewat aplikasi — jangan');
        $this->line('  melebarkan kata kuncinya sampai angkanya cocok.');
    }

    /**
     * Pagar yang paling mudah dilanggar, dicetak sebagai angka bukan sebagai
     * janji: berapa baris ber-layanan Kiloan yang cocok kata kunci — dan
     * karenanya SENGAJA tidak disentuh.
     */
    private function cetakPagarKiloan(): void
    {
        $cocok = Complaint::query()
            ->where('layanan', 'like', 'kiloan%')
            ->get(['id', 'layanan', 'description'])
            // `periksa()`, bukan `tebak()`: yang dihitung di sini "berapa baris
            // Kiloan yang COCOK kata kunci", dan `tebak()` sekarang sudah
            // menahan baris ambigu. Memakainya akan membuat pagar ini
            // melaporkan angka yang lebih kecil dari yang sebenarnya cocok —
            // pagar yang mengecilkan dirinya sendiri tidak menjaga apa pun.
            ->filter(fn (Complaint $c) => LayananDariUraian::periksa($c->description) !== null)
            ->count();

        $this->newLine();
        $this->line('Baris ber-layanan Kiloan yang berubah: 0 — hanya baris '
            .$this->label(LayananDariUraian::ASAL).' yang dipilih.');
        $this->line('  Yang cocok kata kunci tapi sengaja dibiarkan: '.$cocok.' baris.');
    }

    private function label(string $kunci): string
    {
        return (string) config('complaint.layanan.'.$kunci, $kunci);
    }

    private function potong(string $teks, int $panjang): string
    {
        $rapat = (string) preg_replace('/\s+/', ' ', trim($teks));

        return mb_strlen($rapat) <= $panjang ? $rapat : mb_substr($rapat, 0, $panjang - 1).'…';
    }
}

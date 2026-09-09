<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Services\JejakComplaint;
use App\Services\LayananDariUraian;
use Illuminate\Console\Command;
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
 *    laundry gak dikembalikan" di sana, dan tas laundry adalah kantong milik
 *    Less Worry — bukan tas pelanggan yang dicuci.
 * 3. **Ada jalan mundur.** `--balikkan` mengembalikan baris yang pernah
 *    dipindah ke `satuan_non_cloth`, dikenali dari baris riwayat yang ditulis
 *    saat memindahkannya.
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
        $pindah = $this->kandidat();

        $this->info('Pembetulan layanan (API-59) — '.($kering ? 'MODE HITUNG, tidak menulis apa pun' : 'MENULIS'));
        $this->newLine();

        if ($pindah === []) {
            $this->line('Tidak ada baris '.$this->label(LayananDariUraian::ASAL).' yang cocok kata kunci.');

            return self::SUCCESS;
        }

        $this->cetakBaris($pindah);
        $this->cetakRingkasan($pindah);
        $this->cetakAmbigu($pindah);
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
     * Baris yang akan dipindah.
     *
     * Penyaringnya kolom `layanan`, BUKAN isi uraiannya: uraian yang menyebut
     * sepatu pada complaint Kiloan tetap complaint Kiloan.
     *
     * @return list<array{complaint:Complaint,ke:string,kata:string,ambigu:bool}>
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
                    'ambigu' => LayananDariUraian::ambigu($uraian, $temu['posisi']),
                ];
            });

        return $hasil;
    }

    /** @param list<array{complaint:Complaint,ke:string,kata:string,ambigu:bool}> $pindah */
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
        $complaints = Complaint::query()
            ->whereIn('layanan', LayananDariUraian::tujuan())
            ->whereHas('activities', fn ($q) => $q->where('note', 'like', JejakComplaint::TANDA_LAYANAN.'%'))
            ->orderBy('id')
            ->get();

        $this->info('Mengembalikan pembetulan layanan (API-59) — '
            .($kering ? 'MODE HITUNG, tidak menulis apa pun' : 'MENULIS'));
        $this->newLine();

        if ($complaints->isEmpty()) {
            $this->line('Tidak ada baris yang pernah dipindah dan masih berada di nilai barunya.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tiket', 'Sekarang', 'Dikembalikan ke'],
            $complaints->map(fn (Complaint $c) => [
                $c->id,
                $c->ticket_number,
                $this->label((string) $c->layanan),
                $this->label(LayananDariUraian::ASAL),
            ])->all(),
        );

        if ($kering) {
            $this->warn($complaints->count().' baris akan dikembalikan. '
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

        $this->info($complaints->count().' baris dikembalikan ke '.$this->label(LayananDariUraian::ASAL).'.');

        return self::SUCCESS;
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

    /** @param list<array{complaint:Complaint,ke:string,kata:string,ambigu:bool}> $pindah */
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
                ($b['ambigu'] ? '⚠ ' : '').$this->potong((string) $b['complaint']->description, $potong),
            ], $pindah),
        );
    }

    /** @param list<array{complaint:Complaint,ke:string,kata:string,ambigu:bool}> $pindah */
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
     * Baris yang kata kuncinya baru muncul setelah klausa pertama.
     *
     * Ditampilkan terpisah supaya tidak tenggelam di antara 60 baris lain.
     * Ia tetap ikut dipindah — yang memutuskan sebaliknya orang, setelah
     * membacanya.
     *
     * @param  list<array{complaint:Complaint,ke:string,kata:string,ambigu:bool}>  $pindah
     */
    private function cetakAmbigu(array $pindah): void
    {
        $ambigu = array_values(array_filter($pindah, fn (array $b) => $b['ambigu']));

        if ($ambigu === []) {
            return;
        }

        $this->newLine();
        $this->warn('Perlu dibaca dulu — kata kuncinya muncul setelah klausa pertama, '
            .'jadi keluhannya mungkin bukan tentang barang itu:');

        foreach ($ambigu as $b) {
            $this->line('  #'.$b['complaint']->id.' ('.$b['kata'].' -> '.$this->label($b['ke']).')');
            $this->line('    '.$b['complaint']->description);
        }

        $this->line('  Baris di atas TETAP ikut dipindah kalau perintah dijalankan dengan --tulis.');
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
            ->filter(fn (Complaint $c) => LayananDariUraian::tebak($c->description) !== null)
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

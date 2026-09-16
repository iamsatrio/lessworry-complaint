<?php

namespace App\Alarms;

use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Services\PeriodeTagihan;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tagihan bulanan yang jatuh tempo sebentar lagi, atau sudah lewat. (API-73)
 *
 * SATU-SATUNYA alarm di papan yang benar-benar PADAM saat ditandai, dan itu
 * bukan ketidakkonsistenan. Menandai complaint "sudah saya tangani" berarti
 * *aku memegangnya* — keadaannya belum berubah. Menandai tagihan "sudah
 * dibayar" MENGUBAH keadaannya: tagihan itu memang tidak perlu dibayar lagi
 * periode ini. Yang memadamkan alarm tetap keadaannya berubah, seperti alarm
 * mana pun; yang berbeda cuma tindakan penandanya kebetulan mengubah keadaan.
 *
 * Penandaannya PER PERIODE, bukan sekali selamanya: membayar tagihan Oktober
 * tidak memadamkan tagihan November, dan alarm November tetap terbit pada
 * waktunya.
 *
 * Periode mana yang sedang berjalan dijawab App\Services\PeriodeTagihan, yang
 * sama dengan yang dipakai halaman Tagihan — lihat alasannya di sana.
 */
final class TagihanJatuhTempo implements Alarm
{
    /** Berapa baris yang ikut tampil di kartu. Sama dengan alarm complaint. */
    private const BARIS = 5;

    public function kunci(): string
    {
        return 'tagihan.jatuh_tempo';
    }

    public function judul(): string
    {
        return 'Tagihan jatuh tempo';
    }

    public function tindakan(): string
    {
        return 'Bayar, lalu tandai di halaman Tagihan supaya rekanmu tahu sudah diurus.';
    }

    public function periksa(Lingkup $lingkup): ?Nyala
    {
        /** @var Collection<int, Tagihan> $tagihan */
        $tagihan = $lingkup->tagihan()->with('outlet')->orderBy('nama')->get();

        if ($tagihan->isEmpty()) {
            return null;
        }

        $ambang = Pengaturan::ambilAmbangTagihan();
        $periode = new PeriodeTagihan($tagihan, PeriodeTagihan::hariIni());

        $terbit = [];

        foreach ($tagihan as $satu) {
            $jatuhTempo = $periode->berjalan($satu);
            $selisih = $periode->selisihHari($jatuhTempo);

            // Lewat jatuh tempo ($selisih negatif) TETAP menyala — itu justru
            // keadaan yang paling perlu dibaca. Yang belum jatuh tempo menyala
            // hanya kalau tinggal beberapa hari.
            if ($selisih > $ambang) {
                continue;
            }

            $terbit[] = ['tagihan' => $satu, 'selisih' => $selisih];
        }

        if ($terbit === []) {
            return null;
        }

        // Yang paling telat lebih dulu. Urutan nama dipertahankan untuk yang
        // seimbang karena kuerinya sudah diurutkan nama — dua tagihan yang
        // sama-sama jatuh hari ini tidak boleh bertukar tempat tiap muat ulang.
        usort($terbit, fn (array $a, array $b): int => $a['selisih'] <=> $b['selisih']);

        return new Nyala(
            jumlah: count($terbit),
            ringkasan: $this->ringkasan($terbit, $ambang),
            daftar: $this->daftar($terbit),
            perOutlet: $this->perOutlet($terbit),
            kolom: ['Tagihan', 'Outlet', 'Jatuh tempo'],
            tautanSemua: route('tagihan.index'),
        );
    }

    /** @param  list<array{tagihan:Tagihan,selisih:int}>  $terbit */
    private function ringkasan(array $terbit, int $ambang): string
    {
        $telat = array_values(array_filter($terbit, fn (array $baris): bool => $baris['selisih'] < 0));

        if ($telat !== []) {
            $terlama = abs((int) min(array_column($telat, 'selisih')));

            return count($terbit).' tagihan menunggu dibayar. '
                .count($telat).' sudah lewat jatuh tempo, paling lama telat '.$terlama.' hari.';
        }

        return count($terbit).' tagihan jatuh tempo dalam '.$ambang.' hari ke depan.';
    }

    /**
     * @param  list<array{tagihan:Tagihan,selisih:int}>  $terbit
     * @return list<array{id:int,tiket:string,outlet:string,umur:string,tautan:string}>
     */
    private function daftar(array $terbit): array
    {
        return array_map(fn (array $baris): array => [
            'id' => $baris['tagihan']->id,
            'tiket' => $baris['tagihan']->nama,
            'outlet' => self::namaOutlet($baris['tagihan']),
            'umur' => PeriodeTagihan::keterangan($baris['selisih']),
            // Ke halaman Tagihan, pada barisnya. Yang mau dilakukan pembacanya
            // adalah menandainya dibayar, dan tombol itu ada di daftar — bukan
            // di halaman detail yang tidak dibuat.
            'tautan' => route('tagihan.index').'#tagihan-'.$baris['tagihan']->id,
        ], array_slice($terbit, 0, self::BARIS));
    }

    /**
     * @param  list<array{tagihan:Tagihan,selisih:int}>  $terbit
     * @return list<array{outlet:string,jumlah:int}>
     */
    private function perOutlet(array $terbit): array
    {
        $jumlah = [];

        foreach ($terbit as $baris) {
            $nama = self::namaOutlet($baris['tagihan']);
            $jumlah[$nama] = ($jumlah[$nama] ?? 0) + 1;
        }

        arsort($jumlah);

        $hasil = [];

        foreach ($jumlah as $nama => $total) {
            $hasil[] = ['outlet' => (string) $nama, 'jumlah' => $total];
        }

        return $hasil;
    }

    private static function namaOutlet(Tagihan $tagihan): string
    {
        // "Jaringan", bukan "Tanpa outlet": tagihan tanpa outlet bukan data
        // yang kurang lengkap, melainkan tagihan yang memang milik semua
        // outlet sekaligus.
        return $tagihan->outlet->name ?? 'Jaringan';
    }
}
